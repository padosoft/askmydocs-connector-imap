<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

use Carbon\Carbon;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message;

final class WebklexImapClient implements ImapClientInterface
{
    private bool $connected = false;

    public function __construct(private Client $client) {}

    private function ensure(): void
    {
        if (! $this->connected) {
            try {
                $this->client->connect();
                $this->connected = true;
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
            $names[] = $folder->path;
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

        // Use status() (non-deprecated) to get uidvalidity; returns array from EXAMINE
        $status = $folder->status();
        $uidValidity = (int) ($status['uidvalidity'] ?? 0);

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

        return array_values(array_filter(preg_split('/\s+/', $refs) ?: []));
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
