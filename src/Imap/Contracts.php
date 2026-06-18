<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

use Carbon\Carbon;

final class ImapAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly bool $isInline,
        public readonly string $contents,
    ) {}
}

final class ImapMessage
{
    /**
     * @param  list<string>  $references
     * @param  list<array{name:string,email:string}>  $to
     * @param  list<array{name:string,email:string}>  $cc
     * @param  list<string>  $flags
     * @param  list<string>  $labels
     * @param  array<string,string>  $rawHeaders
     * @param  list<ImapAttachment>  $attachments
     */
    public function __construct(
        public readonly int $uid,
        public readonly int $uidValidity,
        public readonly string $mailbox,
        public readonly string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly string $fromName,
        public readonly string $fromEmail,
        public readonly array $to,
        public readonly array $cc,
        public readonly ?Carbon $date,
        public readonly string $subject,
        public readonly array $flags,
        public readonly array $labels,
        public readonly ?string $textBody,
        public readonly ?string $htmlBody,
        public readonly array $rawHeaders,
        public readonly array $attachments,
    ) {}

    public function hasAttachments(): bool
    {
        return $this->attachments !== [];
    }
}

final class MailboxState
{
    public function __construct(
        public readonly int $uidValidity,
        public readonly int $lastUid,
    ) {}
}

interface ImapClientInterface
{
    /** @return list<string> */
    public function listMailboxes(): array;

    public function selectMailbox(string $name): MailboxState;

    /** @return list<int> ascending UIDs matching the window */
    public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array;

    public function fetchMessage(string $mailbox, int $uid): ImapMessage;

    public function ping(): bool;

    public function close(): void;
}
