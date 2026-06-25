<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

use Carbon\Carbon;

final class MailboxWalker
{
    /** @var list<string>|null Live mailbox list, fetched once per walker. */
    private ?array $liveCache = null;

    /** @param array<string,mixed> $config */
    public function __construct(
        private ImapClientInterface $client,
        private array $config,
    ) {}

    /**
     * The live upstream mailbox list, fetched once and reused (so
     * selectedMailboxes() + missingIncludedMailboxes() share one round trip).
     *
     * @return list<string>
     */
    private function live(): array
    {
        return $this->liveCache ??= $this->client->listMailboxes();
    }

    /** @return list<string> */
    public function selectedMailboxes(): array
    {
        $all = $this->live();
        $include = (array) ($this->config['folders']['include'] ?? []);
        $exclude = (array) ($this->config['folders']['exclude'] ?? []);

        if ($include !== []) {
            return array_values(array_filter($all, static fn ($m) => in_array($m, $include, true)));
        }

        return array_values(array_filter($all, static fn ($m) => ! in_array($m, $exclude, true)));
    }

    /**
     * Included folders the operator asked for that no longer exist upstream
     * (e.g. deleted from webmail after configuration). The sync skips them and
     * keeps ingesting the folders that DO exist — this just lets the caller
     * surface the stale entries (never a hard failure). Empty unless an explicit
     * include whitelist is set.
     *
     * @return list<string>
     */
    public function missingIncludedMailboxes(): array
    {
        $include = (array) ($this->config['folders']['include'] ?? []);
        if ($include === []) {
            return [];
        }

        $all = $this->live();

        return array_values(array_filter(
            array_map('strval', $include),
            static fn ($m) => ! in_array($m, $all, true),
        ));
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
