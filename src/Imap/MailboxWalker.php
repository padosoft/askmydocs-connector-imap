<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

use Carbon\Carbon;

final class MailboxWalker
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private ImapClientInterface $client,
        private array $config,
    ) {}

    /** @return list<string> */
    public function selectedMailboxes(): array
    {
        $all = $this->client->listMailboxes();
        $include = (array) ($this->config['folders']['include'] ?? []);
        $exclude = (array) ($this->config['folders']['exclude'] ?? []);

        if ($include !== []) {
            return array_values(array_filter($all, static fn ($m) => in_array($m, $include, true)));
        }

        return array_values(array_filter($all, static fn ($m) => ! in_array($m, $exclude, true)));
    }

    public function windowSince(): ?Carbon
    {
        $days = (int) ($this->config['date_window_days'] ?? 365);

        return $days > 0 ? Carbon::now()->subDays($days) : null;
    }

    /**
     * @param  array{uidvalidity?:int,last_uid?:int}  $state
     * @return array{uids:list<int>, uidValidity:int}
     */
    public function incrementalUids(string $mailbox, array $state, ?Carbon $since): array
    {
        $live = $this->client->selectMailbox($mailbox);
        $sameValidity = isset($state['uidvalidity']) && (int) $state['uidvalidity'] === $live->uidValidity;
        $sinceUid = $sameValidity ? (int) ($state['last_uid'] ?? 0) : null;

        $uids = $this->client->searchUids($mailbox, $since, $sinceUid);

        return ['uids' => $uids, 'uidValidity' => $live->uidValidity];
    }
}
