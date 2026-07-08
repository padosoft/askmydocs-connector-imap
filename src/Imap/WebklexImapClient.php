<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\Message;

final class WebklexImapClient implements ImapClientInterface
{
    private bool $connected = false;

    /** @var array<string,int> */
    private array $uidValidityCache = [];

    public function __construct(private Client $client) {}

    private function ensure(): void
    {
        if (! $this->connected) {
            try {
                $this->client->connect();
                $this->connected = true;
            } catch (AuthFailedException $e) {
                // Rejected credentials → an AUTH failure (the host must prompt
                // re-authentication), never a transient outage. Kept distinct so
                // the sync job stops retrying and SupportsFolderDiscovery surfaces
                // the right error instead of a misleading 503.
                throw new ConnectorAuthException('IMAP authentication failed: '.$e->getMessage(), previous: $e);
            } catch (ResponseException $e) {
                // webklex only throws AuthFailedException when the AUTHENTICATE/LOGIN
                // response validates but carries no data. The far more common real
                // rejection — the server answering `NO`/`BAD` to the auth exchange
                // (e.g. Exchange Online app-only: "NO AUTHENTICATE failed") — surfaces
                // as a ResponseException out of Client::authenticate()->validatedData(),
                // which the AuthFailedException catch above never sees. Classify a
                // rejected auth exchange as a permanent AUTH failure too, so the host
                // re-prompts and the sync job stops retrying; a non-auth handshake
                // fault stays a transient API error.
                if (Str::contains($e->getMessage(), ['authenticate', 'authentication', 'credential', 'login failed'], ignoreCase: true)) {
                    throw new ConnectorAuthException('IMAP authentication failed: '.$e->getMessage(), previous: $e);
                }
                throw new ConnectorApiException('IMAP connect failed: '.$e->getMessage(), previous: $e);
            } catch (\Throwable $e) {
                throw new ConnectorApiException('IMAP connect failed: '.$e->getMessage(), previous: $e);
            }
        }
    }

    public function listMailboxes(): array
    {
        $this->ensure();
        $names = [];
        foreach ($this->client->getFolders(false) as $folder) {
            // Use the DECODED UTF-8 name, not $folder->path (raw modified
            // UTF-7 / RFC 3501, e.g. "Attivit&AOA-"). getFolder() expects
            // UTF-8: getFolderByName() matches the decoded ->name, and
            // getFolderByPath() re-encodes UTF-8→UTF7-IMAP itself. Feeding it
            // the already-encoded path double-encodes and the folder is never
            // found — every non-ASCII mailbox (e.g. Exchange "Attività")
            // would fail with "Mailbox not found" while ASCII (Gmail) worked.
            $names[] = $folder->full_name;
        }

        return $names;
    }

    public function selectMailbox(string $name): MailboxState
    {
        $this->ensure();
        $folder = $this->client->getFolder($name);
        if ($folder === null) {
            throw new ConnectorApiException("Mailbox not found: {$name}");
        }
        // status() calls folderStatus() via EXAMINE; returns array: uidvalidity, uidnext, messages, recent, unseen
        $status = $folder->status();
        $uidValidity = (int) ($status['uidvalidity'] ?? 0);
        $lastUid = max(0, ((int) ($status['uidnext'] ?? 1)) - 1);

        $this->uidValidityCache[$name] = $uidValidity;

        return new MailboxState($uidValidity, $lastUid);
    }

    public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
    {
        $this->ensure();
        $folder = $this->client->getFolder($mailbox);
        if ($folder === null) {
            throw new ConnectorApiException("Mailbox not found: {$mailbox}");
        }
        $query = $folder->query();
        if ($since !== null) {
            $query = $query->since($since);
        } else {
            $query = $query->all();
        }

        $uids = [];
        foreach ($query->setFetchBody(false)->get() as $message) {
            /** @var Message $message */
            $uid = (int) $message->getUid();
            if ($sinceUid !== null && $uid <= $sinceUid) {
                continue;
            }
            $uids[] = $uid;
        }
        sort($uids);

        return $uids;
    }

    public function fetchMessage(string $mailbox, int $uid): ImapMessage
    {
        $this->ensure();
        $folder = $this->client->getFolder($mailbox);
        if ($folder === null) {
            throw new ConnectorApiException("Mailbox not found: {$mailbox}");
        }
        /** @var Message $message */
        $message = $folder->query()->getMessageByUid($uid);

        $attachments = [];
        foreach ($message->getAttachments() as $a) {
            /** @var Attachment $a */
            $attachments[] = new ImapAttachment(
                filename: (string) $a->getName(),
                // getMimeType() uses finfo::buffer() which needs content — may return null
                mimeType: (string) ($a->getMimeType() ?? $a->getContentType()),
                sizeBytes: (int) $a->getSize(),
                isInline: strtolower((string) $a->getDisposition()) === 'inline',
                contents: (string) $a->getContent(),
            );
        }

        // getFrom() returns Attribute whose values are Address objects
        // Attribute::get(0) returns mixed; we cast safely via Address check below
        $fromRaw = $message->getFrom()->get(0);
        $fromAddr = $fromRaw instanceof Address ? $fromRaw : null;

        // getDate() returns Attribute; toDate() returns Carbon directly (not nullable)
        $date = null;
        $dateAttr = $message->getDate();
        if ($dateAttr->count() > 0) {
            try {
                $date = $dateAttr->toDate();
            } catch (\Throwable) {
                $date = null;
            }
        }

        $uidValidity = $this->uidValidityFor($mailbox);

        // getFlags() returns FlagCollection; ->all() returns underlying collection data
        $flagCollection = $message->getFlags();
        $flags = array_values((array) $flagCollection->all());

        return new ImapMessage(
            uid: $uid,
            uidValidity: $uidValidity,
            mailbox: $mailbox,
            messageId: (string) $message->getMessageId(),
            inReplyTo: $this->attributeStringOrNull($message->getInReplyTo()),
            references: $this->splitRefs((string) $message->getReferences()),
            fromName: $fromAddr !== null ? $fromAddr->personal : '',
            fromEmail: $fromAddr !== null ? $fromAddr->mail : '',
            to: $this->addresses($message->getTo()),
            cc: $this->addresses($message->getCc()),
            date: $date,
            subject: (string) $message->getSubject(),
            flags: $flags,
            labels: [],
            textBody: $message->hasTextBody() ? $message->getTextBody() : null,
            htmlBody: $message->hasHTMLBody() ? $message->getHTMLBody() : null,
            rawHeaders: $this->headers($message),
            attachments: $attachments,
        );
    }

    public function ping(): bool
    {
        $this->ensure();

        return $this->client->isConnected();
    }

    public function close(): void
    {
        if ($this->connected) {
            $this->client->disconnect();
            $this->connected = false;
        }
    }

    /** @return list<array{name:string,email:string}> */
    private function addresses(mixed $attribute): array
    {
        if (! $attribute instanceof Attribute) {
            return [];
        }
        $out = [];
        foreach ($attribute->all() as $addr) {
            if ($addr instanceof Address) {
                $out[] = ['name' => $addr->personal, 'email' => $addr->mail];
            }
        }

        return $out;
    }

    /** @return array<string,string> */
    private function headers(Message $message): array
    {
        $out = [];
        $header = $message->getHeader();
        if ($header === null) {
            return $out;
        }
        foreach (['precedence', 'auto-submitted', 'list-unsubscribe'] as $h) {
            // header->get() always returns an Attribute; toString() is empty if not set
            $attr = $header->get($h);
            $val = $attr->toString();
            if ($val !== '') {
                $out[$h] = $val;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function splitRefs(string $refs): array
    {
        if ($refs === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/[\s,]+/', $refs) ?: []));
    }

    /**
     * Returns the cached uidvalidity for the given mailbox, fetching it via STATUS only when
     * the cache is cold (i.e. fetchMessage() was called without a preceding selectMailbox()).
     */
    private function uidValidityFor(string $mailbox): int
    {
        if (isset($this->uidValidityCache[$mailbox])) {
            return $this->uidValidityCache[$mailbox];
        }

        $folder = $this->client->getFolder($mailbox);
        if ($folder === null) {
            return 0;
        }

        $uidValidity = (int) ($folder->status()['uidvalidity'] ?? 0);
        $this->uidValidityCache[$mailbox] = $uidValidity;

        return $uidValidity;
    }

    private function attributeStringOrNull(mixed $attribute): ?string
    {
        if (! $attribute instanceof Attribute) {
            return null;
        }
        $val = $attribute->toString();

        return $val !== '' ? $val : null;
    }
}
