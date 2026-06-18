<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

use Carbon\Carbon;

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
