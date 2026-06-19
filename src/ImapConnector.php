<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\BaseConnector;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorPaginationLimitException;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
use Padosoft\AskMyDocsConnectorBase\SyncResult;
use Padosoft\AskMyDocsConnectorImap\Imap\AttachmentPolicy;
use Padosoft\AskMyDocsConnectorImap\Imap\EmailToMarkdown;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxWalker;
use Padosoft\AskMyDocsConnectorImap\Imap\MessageFilter;
use Padosoft\AskMyDocsConnectorImap\Support\MailMetadata;

class ImapConnector extends BaseConnector
{
    public function __construct(
        OAuthCredentialVault $vault,
        TenantContext $tenantContext,
        ConnectorIngestionContract $ingestion,
        private readonly ImapClientFactoryInterface $factory,
    ) {
        parent::__construct($vault, $tenantContext, $ingestion);
    }

    public function key(): string
    {
        return 'imap';
    }

    public function displayName(): string
    {
        return 'Email (IMAP)';
    }

    public function iconUrl(): string
    {
        return asset('connectors/imap.svg');
    }

    public function oauthScopes(): array
    {
        // Used only in xoauth2 mode; provider-specific scopes resolved at runtime.
        return [];
    }

    public function initiateOAuth(int $installationId): string
    {
        $state = $this->issueOAuthState($installationId);
        $mode = $this->authMode($installationId);

        if ($mode === 'xoauth2') {
            return $this->xoauthAuthorizeUrl($installationId, $state);
        }

        $form = (string) config('connectors.providers.imap.credential_form_url');

        return $form.'?installation='.$installationId.'&state='.$state;
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        $state = (string) $request->input('state', '');
        if (! $this->consumeOAuthState($installationId, $state)) {
            throw new ConnectorAuthException('IMAP credential callback: invalid or expired state.');
        }

        if ($this->authMode($installationId) === 'xoauth2') {
            $this->handleXoauthCallback($installationId, $request);

            return;
        }

        $password = (string) $request->input('password', '');
        if ($password === '') {
            throw new ConnectorAuthException('IMAP credential callback: missing password.');
        }

        // Verify the credentials actually log in before persisting.
        $client = $this->makeClientWithPassword($installationId, $password);
        if (! $client->ping()) {
            $client->close();
            throw new ConnectorAuthException('IMAP login failed with provided credentials.');
        }
        $client->close();

        $this->vault->setCredentials(
            $installationId,
            accessToken: $password,
            refreshToken: null,
            expiresAt: null,
            extra: ['auth_mode' => 'basic'],
        );

        $this->emitAudit('installed', installationId: $installationId, metadata: ['auth_mode' => 'basic']);
    }

    public function disconnect(int $installationId): void
    {
        $this->vault->clearCredentials($installationId);
    }

    public function health(int $installationId): HealthStatus
    {
        $client = $this->makeClient($installationId);
        try {
            $ok = $client->ping();

            return $ok ? HealthStatus::healthy() : HealthStatus::errored('IMAP ping failed');
        } catch (\Throwable $e) {
            return HealthStatus::errored($e->getMessage());
        } finally {
            $client->close();
        }
    }

    public function syncFull(int $installationId): SyncResult
    {
        return $this->runSync($installationId, null, true);
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        if ($since === null) {
            return $this->syncFull($installationId);
        }

        return $this->runSync($installationId, $since, false);
    }

    private function runSync(int $installationId, ?Carbon $since, bool $full): SyncResult
    {
        $installation = $this->loadInstallation($installationId);
        $config = $this->resolveConfig((array) ($installation->config_json ?? []));
        $projectKey = (string) ($config['project_key'] ?? ('connector-'.$this->key()));

        $client = $this->makeClient($installationId);
        $walker = new MailboxWalker($client, $config);
        $filter = new MessageFilter($config);
        $attachmentPolicy = new AttachmentPolicy((array) ($config['attachments'] ?? []));
        $preferText = (string) ($config['body_format'] ?? 'prefer_text') === 'prefer_text';
        $stripQuoted = (bool) ($config['strip_quoted_history'] ?? false);
        $maxMessages = (int) ($config['limits']['max_messages_per_sync'] ?? 5000);

        $state = (array) ($this->vault->getExtra($installationId)['mailboxes_state'] ?? []);
        $added = 0;
        $errors = [];
        $processed = 0;

        try {
            foreach ($walker->selectedMailboxes() as $mailbox) {
                $mailboxState = $full ? [] : (array) ($state[$mailbox] ?? []);
                $window = $walker->windowSince();
                $effectiveSince = $since !== null && $window !== null ? $since->max($window) : ($since ?? $window);
                $r = $walker->incrementalUids($mailbox, $mailboxState, $effectiveSince);
                $maxUid = (int) ($mailboxState['last_uid'] ?? 0);

                foreach ($r['uids'] as $uid) {
                    if ($processed >= $maxMessages) {
                        throw new ConnectorPaginationLimitException(maxPages: $maxMessages);
                    }
                    $processed++;
                    try {
                        $message = $client->fetchMessage($mailbox, $uid);
                        if (! $filter->passes($message)) {
                            $maxUid = max($maxUid, $uid);

                            continue;
                        }
                        $added += $this->ingestMessage($installation, $projectKey, $config, $message, $attachmentPolicy, $preferText, $stripQuoted);
                        $maxUid = max($maxUid, $uid);
                    } catch (\Throwable $e) {
                        $errors[] = sprintf('%s uid %d: %s', $mailbox, $uid, $e->getMessage());
                    }
                }

                $state[$mailbox] = ['uidvalidity' => $r['uidValidity'], 'last_uid' => $maxUid];
            }
        } catch (ConnectorPaginationLimitException) {
            $errors[] = sprintf('sync truncated at max_messages_per_sync=%d', $maxMessages);
        } finally {
            $client->close();
        }

        $this->vault->setExtraKey($installationId, 'mailboxes_state', $state);

        return new SyncResult(
            documentsAdded: $full ? $added : 0,
            documentsUpdated: $full ? 0 : $added,
            documentsRemoved: 0,
            errors: $errors,
            completedAt: Carbon::now(),
        );
    }

    /** @param array<string,mixed> $config */
    private function ingestMessage(
        ConnectorInstallation $installation,
        string $projectKey,
        array $config,
        ImapMessage $message,
        AttachmentPolicy $policy,
        bool $preferText,
        bool $stripQuoted,
    ): int {
        $count = 0;
        $mailboxSlug = Str::slug($message->mailbox) ?: 'folder';

        $markdown = (new EmailToMarkdown)->render($message, $preferText, $stripQuoted);
        if (($config['redact_pii'] ?? false) === true) {
            $markdown = $this->maybeRedactContent($markdown);
        }

        $relative = sprintf('%s/connectors/imap/installation-%d/%s/%d.md', $projectKey, $installation->id, $mailboxSlug, $message->uid);
        $paths = $this->resolveKbSourcePath($relative);
        Storage::disk($paths['disk'])->put($paths['absolute'], $markdown);

        $this->dispatchIngestion(
            projectKey: $projectKey,
            relativePath: $paths['relative'],
            disk: $paths['disk'],
            title: $message->subject !== '' ? $message->subject : '(no subject)',
            metadata: (new MailMetadata)->build($installation->id, $message),
            mimeType: 'text/markdown',
            tenantId: $installation->tenant_id,
        );
        $count++;

        $emitted = 0;
        foreach ($message->attachments as $attachment) {
            if ($emitted >= $policy->limit()) {
                break;
            }
            if (! $policy->accepts($attachment)) {
                continue;
            }
            $emitted++;
            $safe = Str::slug(pathinfo($attachment->filename, PATHINFO_FILENAME)) ?: 'file';
            $ext = pathinfo($attachment->filename, PATHINFO_EXTENSION);
            $attRelative = sprintf('%s/connectors/imap/installation-%d/%s/%d/%s.%s', $projectKey, $installation->id, $mailboxSlug, $message->uid, $safe, $ext);
            $attPaths = $this->resolveKbSourcePath($attRelative);
            Storage::disk($attPaths['disk'])->put($attPaths['absolute'], $attachment->contents);

            $meta = (new MailMetadata)->build($installation->id, $message);
            $meta['attachment_of_message_id'] = $message->messageId;
            $meta['attachment_filename'] = $attachment->filename;

            $this->dispatchIngestion(
                projectKey: $projectKey,
                relativePath: $attPaths['relative'],
                disk: $attPaths['disk'],
                title: $attachment->filename,
                metadata: $meta,
                mimeType: $attachment->mimeType !== '' ? $attachment->mimeType : 'application/octet-stream',
                tenantId: $installation->tenant_id,
            );
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private function resolveConfig(array $config): array
    {
        $defaults = (array) config('connectors.providers.imap.defaults', []);
        $config['attachments'] = array_merge((array) ($defaults['attachments'] ?? []), (array) ($config['attachments'] ?? []));
        $config['limits'] = array_merge((array) ($defaults['limits'] ?? []), (array) ($config['limits'] ?? []));
        $config['date_window_days'] ??= $defaults['date_window_days'] ?? 365;
        $config['skip_auto_generated'] ??= $defaults['skip_auto_generated'] ?? true;
        $config['body_format'] ??= $defaults['body_format'] ?? 'prefer_text';
        if (! is_array($config['folders'] ?? null)) {
            $config['folders'] = [];
        }
        $config['folders']['exclude'] = $config['folders']['exclude'] ?? ($defaults['folders_exclude'] ?? []);

        return $config;
    }

    protected function authMode(int $installationId): string
    {
        $config = (array) ($this->loadInstallation($installationId)->config_json ?? []);

        return (string) ($config['auth_mode'] ?? 'basic');
    }

    protected function makeClient(int $installationId): ImapClientInterface
    {
        $password = (string) ($this->vault->getAccessToken($installationId) ?? '');

        return $this->makeClientWithPassword($installationId, $password);
    }

    protected function makeClientWithPassword(int $installationId, string $secret): ImapClientInterface
    {
        $config = (array) ($this->loadInstallation($installationId)->config_json ?? []);
        $connection = (array) ($config['connection'] ?? []);

        return $this->factory->make($connection, $secret, (string) ($config['auth_mode'] ?? 'basic'));
    }

    private function xoauthAuthorizeUrl(int $installationId, string $state): string
    {
        $config = (array) ($this->loadInstallation($installationId)->config_json ?? []);
        $provider = (string) ($config['xoauth2_provider'] ?? 'google');
        $p = (array) config('connectors.providers.imap.xoauth2.'.$provider, []);

        return ((string) ($p['authorize_url'] ?? '')).'?'.http_build_query([
            'client_id' => $p['client_id'] ?? '',
            'redirect_uri' => $p['redirect_uri'] ?? '',
            'response_type' => 'code',
            // TODO(Task 13): move xoauth2 scopes to config when the full token-exchange path lands.
            'scope' => $provider === 'google' ? 'https://mail.google.com/' : 'https://outlook.office.com/IMAP.AccessAsUser.All offline_access',
            'access_type' => 'offline',
            'state' => $state,
        ]);
    }

    private function handleXoauthCallback(int $installationId, Request $request): void
    {
        // Design-ready: exchange code -> tokens. Full implementation deferred (see plan Task 13).
        throw new ConnectorAuthException('XOAUTH2 mode is configured but token exchange is not enabled in this build.');
    }
}
