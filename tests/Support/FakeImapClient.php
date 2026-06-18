<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Support;

use Carbon\Carbon;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;

final class FakeImapClient implements ImapClientInterface
{
    /** @param array<string, list<ImapMessage>> $byMailbox */
    public function __construct(
        private array $byMailbox = [],
        private int $uidValidity = 1,
        public bool $closed = false,
    ) {}

    public function listMailboxes(): array
    {
        return array_values(array_keys($this->byMailbox));
    }

    public function selectMailbox(string $name): MailboxState
    {
        $messages = $this->byMailbox[$name] ?? [];
        $lastUid = 0;
        foreach ($messages as $m) {
            $lastUid = max($lastUid, $m->uid);
        }

        return new MailboxState($this->uidValidity, $lastUid);
    }

    public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
    {
        $uids = [];
        foreach ($this->byMailbox[$mailbox] ?? [] as $m) {
            if ($sinceUid !== null && $m->uid <= $sinceUid) {
                continue;
            }
            if ($since !== null && $m->date !== null && $m->date->lessThan($since)) {
                continue;
            }
            $uids[] = $m->uid;
        }
        sort($uids);

        return $uids;
    }

    public function fetchMessage(string $mailbox, int $uid): ImapMessage
    {
        foreach ($this->byMailbox[$mailbox] ?? [] as $m) {
            if ($m->uid === $uid) {
                return $m;
            }
        }
        throw new \RuntimeException("No fake message uid={$uid} in {$mailbox}");
    }

    public function ping(): bool
    {
        return true;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
