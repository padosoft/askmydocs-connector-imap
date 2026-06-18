<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsConnectorBase\BaseConnector;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\SyncResult;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;

class ImapConnector extends BaseConnector
{
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
        try {
            $client = $this->makeClient($installationId);
            $ok = $client->ping();
            $client->close();

            return $ok ? HealthStatus::healthy() : HealthStatus::errored('IMAP ping failed');
        } catch (\Throwable $e) {
            return HealthStatus::errored($e->getMessage());
        }
    }

    public function syncFull(int $installationId): SyncResult
    {
        // Implemented in Task 11.
        return SyncResult::empty();
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        // Implemented in Task 11.
        return SyncResult::empty();
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

        /** @var ImapClientFactoryInterface $factory */
        $factory = app(ImapClientFactoryInterface::class);

        return $factory->make($connection, $secret, (string) ($config['auth_mode'] ?? 'basic'));
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
