<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\BaseConnector;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsConnectionSettings;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsFolderDiscovery;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorPaginationLimitException;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\CredentialField;
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

class ImapConnector extends BaseConnector implements SupportsConnectionSettings, SupportsCredentialForm, SupportsFolderDiscovery
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

    public function credentialFormSchema(): array
    {
        $basicOnly = ['field' => 'auth_mode', 'equals' => 'basic'];
        $xoauthOnly = ['field' => 'auth_mode', 'equals' => 'xoauth2'];

        $fields = [
            new CredentialField(
                name: 'auth_mode',
                label: 'Authentication Mode',
                type: 'select',
                target: 'auth_mode',
                required: true,
                default: 'basic',
                options: ['basic' => 'Password / App password', 'xoauth2' => 'OAuth2 (Gmail / Microsoft 365)'],
                group: 'Authentication',
            ),
            new CredentialField(
                name: 'xoauth2_provider',
                label: 'OAuth2 Provider',
                type: 'select',
                target: 'provider',
                default: 'google',
                options: ['google' => 'Gmail', 'microsoft' => 'Microsoft 365'],
                showIf: $xoauthOnly,
                group: 'Authentication',
            ),
            new CredentialField(
                name: 'host',
                label: 'IMAP Host',
                type: 'text',
                target: 'connection',
                required: true,
                showIf: $basicOnly,
                help: 'e.g. imap.example.com',
                group: 'Server',
            ),
            new CredentialField(
                name: 'port',
                label: 'Port',
                type: 'number',
                target: 'connection',
                default: 993,
                showIf: $basicOnly,
                group: 'Server',
            ),
            new CredentialField(
                name: 'encryption',
                label: 'Encryption',
                type: 'select',
                target: 'connection',
                default: 'ssl',
                options: ['ssl' => 'SSL/TLS', 'tls' => 'TLS (implicit)', 'starttls' => 'STARTTLS', 'none' => 'None'],
                showIf: $basicOnly,
                group: 'Server',
            ),
            new CredentialField(
                name: 'validate_cert',
                label: 'Validate Certificate',
                type: 'checkbox',
                target: 'connection',
                default: true,
                showIf: $basicOnly,
                group: 'Server',
            ),
            new CredentialField(
                name: 'username',
                label: 'Username / Email',
                type: 'text',
                target: 'connection',
                required: true,
                help: 'Full email address / mailbox login',
                group: 'Credentials',
            ),
            new CredentialField(
                name: 'password',
                label: 'Password',
                type: 'password',
                target: 'secret',
                required: true,
                secret: true,
                showIf: $basicOnly,
                help: 'Password or app-specific password',
                group: 'Credentials',
            ),
        ];

        return array_map(static fn (CredentialField $f) => $f->toArray(), $fields);
    }

    /**
     * Live mailbox/label paths for an installation — the data source behind the
     * admin folder picker. Reuses the connector's own client builder so basic
     * AND xoauth2 (with token refresh) both work; the host never rebuilds the
     * client. The returned paths are verbatim, so a picked value round-trips 1:1
     * with config_json.folders.include / .exclude.
     *
     * @return list<string>
     */
    public function listAvailableFolders(int $installationId): array
    {
        $client = $this->makeClient($installationId);

        try {
            return $client->listMailboxes();
        } finally {
            // Safe even if connect failed inside listMailboxes() — close() is a
            // no-op when the client never connected.
            $client->close();
        }
    }

    /**
     * The editable post-install sync settings the host renders as a generic,
     * schema-driven editor. Every field targets config_json (dotted name = nested
     * path the connector reads back at sync time) and is never secret. Folder
     * fields are live multiselects backed by {@see listAvailableFolders()}.
     *
     * @return list<array<string,mixed>>
     */
    public function connectionSettingsSchema(): array
    {
        // Defaults derive from the SAME config block the sync engine reads
        // (resolveConfig: config('connectors.providers.imap.defaults')), so the
        // schema advertises a host's overridden defaults rather than silently
        // re-imposing the package defaults when the generic editor saves a value
        // for a key that was absent from config_json.
        $defaults = (array) config('connectors.providers.imap.defaults', []);
        $attachments = (array) ($defaults['attachments'] ?? []);
        $limits = (array) ($defaults['limits'] ?? []);

        $fields = [
            // Folders — which mailboxes/labels to sync. include wins when non-empty.
            new CredentialField(
                name: 'folders.include', label: 'Folders to sync', type: 'multiselect',
                target: 'config', default: [], discovery: 'folders', group: 'Folders',
                help: 'Whitelist. Leave empty to sync every folder except the excluded ones.',
            ),
            new CredentialField(
                name: 'folders.exclude', label: 'Folders to skip', type: 'multiselect',
                target: 'config',
                default: $defaults['folders_exclude'] ?? ['Trash', 'Spam', 'Junk', '[Gmail]/Spam', '[Gmail]/Trash'],
                discovery: 'folders', group: 'Folders',
                help: 'Blacklist. Ignored when "Folders to sync" is set.',
            ),

            // Sync window — how far back to walk.
            new CredentialField(
                name: 'date_window_days', label: 'Sync window (days)', type: 'number',
                target: 'config', default: (int) ($defaults['date_window_days'] ?? 365), group: 'Sync window',
                help: 'How many days back to import. 0 = all history.',
            ),

            // Scope — which messages within the window.
            new CredentialField(
                name: 'only_unseen', label: 'Only unread messages', type: 'checkbox',
                target: 'config', default: false, group: 'Scope',
            ),
            new CredentialField(
                name: 'only_flagged', label: 'Only flagged messages', type: 'checkbox',
                target: 'config', default: false, group: 'Scope',
            ),
            new CredentialField(
                name: 'reconcile_deletions', label: 'Remove docs for deleted emails', type: 'checkbox',
                target: 'config', default: false, group: 'Scope',
                help: 'Soft-delete a KB doc when its source email disappears upstream (UID diff).',
            ),

            // Content — how each email is rendered.
            new CredentialField(
                name: 'body_format', label: 'Body format', type: 'select',
                target: 'config', default: (string) ($defaults['body_format'] ?? 'prefer_text'), group: 'Content',
                options: ['prefer_text' => 'Prefer plain text', 'prefer_html' => 'Prefer HTML → Markdown'],
            ),
            new CredentialField(
                name: 'skip_auto_generated', label: 'Skip auto-generated mail', type: 'checkbox',
                target: 'config', default: (bool) ($defaults['skip_auto_generated'] ?? true), group: 'Content',
                help: 'Skip bulk / list / auto-submitted messages (Precedence, Auto-Submitted, List-Unsubscribe).',
            ),
            new CredentialField(
                name: 'strip_quoted_history', label: 'Strip quoted reply history', type: 'checkbox',
                target: 'config', default: false, group: 'Content',
            ),
            new CredentialField(
                name: 'redact_pii', label: 'Redact PII before ingest', type: 'checkbox',
                target: 'config', default: false, group: 'Content',
            ),

            // Filtering — sender / recipient / subject allow & deny lists.
            new CredentialField(
                name: 'senders.include', label: 'Only from senders', type: 'tags',
                target: 'config', default: [], group: 'Filtering',
                help: 'Full email or bare domain. Empty = any sender.',
            ),
            new CredentialField(
                name: 'senders.exclude', label: 'Exclude senders', type: 'tags',
                target: 'config', default: [], group: 'Filtering',
            ),
            new CredentialField(
                name: 'recipients.include', label: 'Only to recipients', type: 'tags',
                target: 'config', default: [], group: 'Filtering',
            ),
            new CredentialField(
                name: 'recipients.exclude', label: 'Exclude recipients', type: 'tags',
                target: 'config', default: [], group: 'Filtering',
            ),
            new CredentialField(
                name: 'subject.include_keywords', label: 'Subject must contain', type: 'tags',
                target: 'config', default: [], group: 'Filtering',
                help: 'Case-insensitive substring match. Empty = any subject.',
            ),
            new CredentialField(
                name: 'subject.exclude_keywords', label: 'Subject must not contain', type: 'tags',
                target: 'config', default: [], group: 'Filtering',
            ),

            // Attachments.
            new CredentialField(
                name: 'attachments.enabled', label: 'Ingest attachments', type: 'checkbox',
                target: 'config', default: (bool) ($attachments['enabled'] ?? true), group: 'Attachments',
            ),
            new CredentialField(
                name: 'attachments.allowed_extensions', label: 'Allowed attachment types', type: 'tags',
                target: 'config',
                default: $attachments['allowed_extensions'] ?? ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'csv', 'md', 'rtf', 'odt'],
                group: 'Attachments',
            ),
            new CredentialField(
                name: 'attachments.max_size_mb', label: 'Max attachment size (MB)', type: 'number',
                target: 'config', default: (int) ($attachments['max_size_mb'] ?? 25), group: 'Attachments',
            ),
            new CredentialField(
                name: 'attachments.max_per_email', label: 'Max attachments per email', type: 'number',
                target: 'config', default: (int) ($attachments['max_per_email'] ?? 20), group: 'Attachments',
            ),
            new CredentialField(
                name: 'attachments.skip_inline', label: 'Skip inline attachments', type: 'checkbox',
                target: 'config', default: (bool) ($attachments['skip_inline'] ?? true), group: 'Attachments',
            ),

            // Limits — sync safety bounds.
            new CredentialField(
                name: 'limits.max_messages_per_sync', label: 'Max messages per sync', type: 'number',
                target: 'config', default: (int) ($limits['max_messages_per_sync'] ?? 5000), group: 'Limits',
            ),
        ];

        return array_map(static fn (CredentialField $f) => $f->toArray(), $fields);
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
        if ($this->authMode($installationId) === 'xoauth2') {
            $p = $this->xoauthProviderConfig($installationId);
            $revokeUrl = $p['revoke_url'] ?? null;

            if (is_string($revokeUrl) && $revokeUrl !== '') {
                $token = $this->vault->getAccessToken($installationId)
                    ?? $this->vault->getRefreshToken($installationId);
                if ($token !== null) {
                    try {
                        Http::asForm()->post($revokeUrl, ['token' => $token]);
                    } catch (\Throwable) {
                        // Best-effort: revoke failure must never block disconnect.
                    }
                }
            }
        }

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
        $projectKey = $this->resolveProjectKey($installation);

        $client = $this->makeClient($installationId);
        $walker = new MailboxWalker($client, $config);
        $filter = new MessageFilter($config);
        $attachmentPolicy = new AttachmentPolicy((array) ($config['attachments'] ?? []));
        $preferText = (string) ($config['body_format'] ?? 'prefer_text') === 'prefer_text';
        $stripQuoted = (bool) ($config['strip_quoted_history'] ?? false);
        $maxMessages = (int) ($config['limits']['max_messages_per_sync'] ?? 5000);

        $state = (array) ($this->vault->getExtra($installationId)['mailboxes_state'] ?? []);
        $added = 0;
        $removed = 0;
        $errors = [];
        $processed = 0;

        try {
            // Surface (but never fail on) included folders that no longer exist
            // upstream — the sync proceeds for the folders that DO exist, and each
            // stale entry lands in the SyncResult errors[] (visible in the host
            // sync-run observability) + the log. E.g. an operator whitelists a,b,c
            // then deletes b from webmail → sync a,c, flag b as missing.
            foreach ($walker->missingIncludedMailboxes() as $missing) {
                $note = sprintf("folder '%s' in folders.include not found upstream — skipped", $missing);
                $errors[] = $note;
                Log::warning('[connector-imap] '.$note, ['installation_id' => $installationId]);
            }

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

                $thisRunKeys = array_map(
                    fn (int $uid) => $this->docKey($mailbox, $r['uidValidity'], $uid),
                    $r['uids'],
                );

                if ($full) {
                    $mailboxState['ingested_keys'] = $thisRunKeys;
                }

                if (($config['reconcile_deletions'] ?? false) === true) {
                    $seen = array_map('strval', (array) ($mailboxState['ingested_keys'] ?? []));
                    $currentUids = $client->searchUids($mailbox, null, null);
                    $currentKeys = array_map(
                        fn (int $uid) => $this->docKey($mailbox, $r['uidValidity'], $uid),
                        $currentUids,
                    );
                    $vanished = array_diff($seen, $currentKeys);
                    foreach ($vanished as $goneKey) {
                        if ($this->softDeleteByMetadataKey($installation, 'imap_doc_key', $goneKey)) {
                            $removed++;
                        }
                    }
                    $state[$mailbox]['ingested_keys'] = array_slice(
                        array_unique(array_merge($currentKeys, $thisRunKeys)), -1000
                    );
                } else {
                    // keep a capped recent set for a future reconcile run
                    $prior = array_map('strval', (array) ($mailboxState['ingested_keys'] ?? []));
                    $state[$mailbox]['ingested_keys'] = array_slice(
                        array_unique(array_merge($prior, $thisRunKeys)), -1000
                    );
                }
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
            documentsRemoved: $removed,
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

    private function docKey(string $mailbox, int $uidValidity, int $uid): string
    {
        return $mailbox.':'.$uidValidity.':'.$uid;
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

    public function refreshTokenIfExpired(int $installationId): ?string
    {
        if ($this->authMode($installationId) !== 'xoauth2') {
            // Basic-auth: password stored as accessToken, never expires.
            return $this->vault->getAccessToken($installationId);
        }

        $access = $this->vault->getAccessToken($installationId);
        if ($access !== null) {
            return $access; // Still valid.
        }

        $refresh = $this->vault->getRefreshToken($installationId);
        if ($refresh === null) {
            return null;
        }

        $p = $this->xoauthProviderConfig($installationId);

        $resp = Http::asForm()->acceptJson()->post((string) ($p['token_url'] ?? ''), [
            'client_id' => $p['client_id'] ?? '',
            'client_secret' => $p['client_secret'] ?? '',
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
        ]);

        if (! $resp->successful()) {
            throw new ConnectorAuthException('XOAUTH2 token refresh failed: HTTP '.$resp->status());
        }

        $payload = (array) $resp->json();
        $expiresAt = isset($payload['expires_in'])
            ? Carbon::now()->addSeconds((int) $payload['expires_in'])
            : null;
        $newRefresh = is_string($payload['refresh_token'] ?? null)
            ? $payload['refresh_token']
            : $refresh;

        $this->vault->setCredentials(
            $installationId,
            accessToken: (string) $payload['access_token'],
            refreshToken: $newRefresh,
            expiresAt: $expiresAt,
            extra: $this->vault->getExtra($installationId),
        );

        $this->emitAudit('token_refreshed', installationId: $installationId);

        return (string) $payload['access_token'];
    }

    protected function makeClient(int $installationId): ImapClientInterface
    {
        $secret = $this->authMode($installationId) === 'xoauth2'
            ? (string) ($this->refreshTokenIfExpired($installationId) ?? '')
            : (string) ($this->vault->getAccessToken($installationId) ?? '');

        return $this->makeClientWithPassword($installationId, $secret);
    }

    protected function makeClientWithPassword(int $installationId, string $secret): ImapClientInterface
    {
        $config = (array) ($this->loadInstallation($installationId)->config_json ?? []);
        $connection = (array) ($config['connection'] ?? []);

        return $this->factory->make($connection, $secret, (string) ($config['auth_mode'] ?? 'basic'));
    }

    private function xoauthAuthorizeUrl(int $installationId, string $state): string
    {
        $p = $this->xoauthProviderConfig($installationId);

        return ((string) ($p['authorize_url'] ?? '')).'?'.http_build_query([
            'client_id' => $p['client_id'] ?? '',
            'redirect_uri' => $p['redirect_uri'] ?? '',
            'response_type' => 'code',
            'scope' => $p['scopes'] ?? '',
            'access_type' => 'offline',
            'state' => $state,
        ]);
    }

    private function handleXoauthCallback(int $installationId, Request $request): void
    {
        $code = (string) $request->input('code', '');
        if ($code === '') {
            throw new ConnectorAuthException('XOAUTH2 callback: missing authorization code.');
        }

        $p = $this->xoauthProviderConfig($installationId);

        $resp = Http::asForm()->acceptJson()->post((string) ($p['token_url'] ?? ''), [
            'client_id' => $p['client_id'] ?? '',
            'client_secret' => $p['client_secret'] ?? '',
            'redirect_uri' => $p['redirect_uri'] ?? '',
            'grant_type' => 'authorization_code',
            'code' => $code,
            'scope' => $p['scopes'] ?? '',
        ]);

        if (! $resp->successful()) {
            throw new ConnectorAuthException('XOAUTH2 token exchange failed: HTTP '.$resp->status());
        }

        $payload = (array) $resp->json();

        $expiresAt = isset($payload['expires_in'])
            ? Carbon::now()->addSeconds((int) $payload['expires_in'])
            : null;

        $config = (array) ($this->loadInstallation($installationId)->config_json ?? []);
        $provider = (string) ($config['xoauth2_provider'] ?? 'google');

        $this->vault->setCredentials(
            $installationId,
            accessToken: (string) ($payload['access_token'] ?? ''),
            refreshToken: is_string($payload['refresh_token'] ?? null)
                ? $payload['refresh_token']
                : null,
            expiresAt: $expiresAt,
            extra: ['auth_mode' => 'xoauth2', 'provider' => $provider],
        );

        $this->emitAudit('installed', installationId: $installationId, metadata: [
            'auth_mode' => 'xoauth2',
            'provider' => $provider,
        ]);
    }

    /**
     * Resolve the provider config block for the given installation.
     * Provider is determined by `config_json.xoauth2_provider` (default 'google').
     * Config lives under `connectors.providers.imap.xoauth2.<provider>`.
     *
     * NOTE: for XOAUTH2, the IMAP username (mailbox address) MUST be set in
     * `config_json.connection.username` — the connector does not infer it from
     * the OAuth token response. Ensure this field is populated before the sync runs.
     *
     * @return array<string,mixed>
     */
    private function xoauthProviderConfig(int $installationId): array
    {
        $config = (array) ($this->loadInstallation($installationId)->config_json ?? []);
        $provider = (string) ($config['xoauth2_provider'] ?? 'google');

        return (array) config('connectors.providers.imap.xoauth2.'.$provider, []);
    }
}
