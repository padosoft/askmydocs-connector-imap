<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorCredential;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
use Padosoft\AskMyDocsConnectorImap\ImapConnector;
use Padosoft\AskMyDocsConnectorImap\Tests\Support\FakeImapClient;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Microsoft 365 app-only (OAuth2 client-credentials) IMAP auth.
 *
 * Covers the unattended, admin-consented flow (IMAP.AccessAsApp) as opposed to
 * the delegated user-sign-in flow exercised by {@see ImapXoauthTest}.
 */
final class ImapClientCredentialsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{connection: array<string,mixed>, secret: string, authMode: string}|null */
    private ?array $lastFactoryCall = null;

    private const TOKEN_URL = 'https://login.microsoftonline.com/contoso-tenant-id/oauth2/v2.0/token';

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->app->bind(
            ImapClientFactoryInterface::class,
            fn () => new class($test) implements ImapClientFactoryInterface
            {
                public function __construct(private ImapClientCredentialsTest $test) {}

                public function make(array $connection, string $secret, string $authMode): ImapClientInterface
                {
                    $this->test->recordFactoryCall($connection, $secret, $authMode);

                    return new FakeImapClient(['INBOX' => []]);
                }
            }
        );
    }

    /** @param array<string,mixed> $connection */
    public function recordFactoryCall(array $connection, string $secret, string $authMode): void
    {
        $this->lastFactoryCall = ['connection' => $connection, 'secret' => $secret, 'authMode' => $authMode];
    }

    private function installation(array $configOverrides = []): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'config_json' => array_merge([
                'auth_mode' => 'xoauth2_client_credentials',
                'ms_tenant_id' => 'contoso-tenant-id',
                'ms_client_id' => 'contoso-client-id',
                'connection' => [
                    'username' => 'shared-mailbox@contoso.com',
                ],
            ], $configOverrides),
            'status' => 'pending',
        ]);
    }

    private function fakeTokenEndpoint(string $accessToken = 'app-only-access-token', int $expiresIn = 3600): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'access_token' => $accessToken,
                'token_type' => 'Bearer',
                'expires_in' => $expiresIn,
                // NOTE: no refresh_token — client-credentials never returns one.
            ], 200),
        ]);
    }

    public function test_schema_exposes_app_only_auth_mode_and_microsoft_fields(): void
    {
        $schema = $this->app->make(ImapConnector::class)->credentialFormSchema();

        $byName = [];
        foreach ($schema as $field) {
            $byName[$field['name']] = $field;
        }

        $this->assertArrayHasKey('xoauth2_client_credentials', $byName['auth_mode']['options']);

        $this->assertSame('config', $byName['ms_tenant_id']['target']);
        $this->assertSame('config', $byName['ms_client_id']['target']);

        // The secret NEVER lands in config_json.
        $this->assertSame('secret', $byName['ms_client_secret']['target']);
        $this->assertTrue($byName['ms_client_secret']['secret']);

        // All three are gated behind the app-only auth mode.
        foreach (['ms_tenant_id', 'ms_client_id', 'ms_client_secret'] as $name) {
            $this->assertSame(
                ['field' => 'auth_mode', 'equals' => 'xoauth2_client_credentials'],
                $byName[$name]['showIf'],
            );
        }
    }

    public function test_initiate_returns_credential_form_url_not_a_redirect(): void
    {
        $connector = $this->app->make(ImapConnector::class);
        $inst = $this->installation();

        $url = $connector->initiateOAuth($inst->id);

        // App-only has no interactive provider redirect — it collects a secret
        // via the credential form, exactly like basic-auth.
        $this->assertStringNotContainsString('login.microsoftonline.com', $url);
        $this->assertStringContainsString('installation='.$inst->id, $url);
    }

    public function test_callback_mints_app_only_token_and_stores_secret_as_refresh_material(): void
    {
        $this->fakeTokenEndpoint();

        $connector = $this->app->make(ImapConnector::class);
        $inst = $this->installation();

        $url = $connector->initiateOAuth($inst->id);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $req = Request::create('/credentials', 'POST', [
            'state' => (string) ($q['state'] ?? ''),
            'ms_client_secret' => 'super-secret-value',
        ]);
        $connector->handleOAuthCallback($inst->id, $req);

        /** @var OAuthCredentialVault $vault */
        $vault = $this->app->make(OAuthCredentialVault::class);

        // Access token stored + retrievable (not expired).
        $this->assertSame('app-only-access-token', $vault->getAccessToken($inst->id));

        // The client secret is the durable material — stored in the vault's
        // refresh slot (encrypted), NEVER in the plaintext config_json.
        $this->assertSame('super-secret-value', $vault->getRefreshToken($inst->id));
        $inst->refresh();
        $this->assertArrayNotHasKey('ms_client_secret', (array) $inst->config_json);

        $row = ConnectorCredential::query()->where('connector_installation_id', $inst->id)->first();
        $this->assertNotNull($row?->expires_at);
        $this->assertSame('xoauth2_client_credentials', $vault->getExtra($inst->id)['auth_mode'] ?? null);

        // The token request used the client-credentials grant against the
        // tenant-specific endpoint with the .default scope.
        Http::assertSent(function ($request) {
            if ((string) $request->url() !== self::TOKEN_URL) {
                return false;
            }
            $body = (string) $request->body();

            return str_contains($body, 'grant_type=client_credentials')
                && str_contains($body, rawurlencode('https://outlook.office365.com/.default'));
        });
    }

    public function test_refresh_remints_from_stored_secret_when_access_token_expired(): void
    {
        $this->fakeTokenEndpoint(accessToken: 're-minted-access-token');

        $connector = $this->app->make(ImapConnector::class);
        $inst = $this->installation();

        /** @var OAuthCredentialVault $vault */
        $vault = $this->app->make(OAuthCredentialVault::class);

        // Seed an EXPIRED access token + the client secret in the refresh slot.
        $vault->setCredentials(
            $inst->id,
            accessToken: 'expired-access-token',
            refreshToken: 'stored-client-secret',
            expiresAt: Carbon::now()->subMinute(),
            extra: ['auth_mode' => 'xoauth2_client_credentials', 'provider' => 'microsoft'],
        );

        $this->assertNull($vault->getAccessToken($inst->id)); // expired

        $result = $connector->refreshTokenIfExpired($inst->id);

        $this->assertSame('re-minted-access-token', $result);
        $this->assertSame('re-minted-access-token', $vault->getAccessToken($inst->id));
        // Secret is retained (there is no rotating refresh token).
        $this->assertSame('stored-client-secret', $vault->getRefreshToken($inst->id));
    }

    public function test_make_client_feeds_fresh_token_and_default_exchange_host(): void
    {
        $this->fakeTokenEndpoint(accessToken: 'live-token');

        $connector = $this->app->make(ImapConnector::class);
        $inst = $this->installation();

        /** @var OAuthCredentialVault $vault */
        $vault = $this->app->make(OAuthCredentialVault::class);
        // Expired access token + the client secret in the refresh slot: the
        // health probe must re-mint via client-credentials before connecting.
        $vault->setCredentials(
            $inst->id,
            accessToken: 'stale-token',
            refreshToken: 'stored-client-secret',
            expiresAt: Carbon::now()->subMinute(),
            extra: ['auth_mode' => 'xoauth2_client_credentials', 'provider' => 'microsoft'],
        );

        $this->assertSame('healthy', $connector->health($inst->id)->state);

        // makeClient resolved the app-only token (not a raw stored password) and
        // the factory received the fixed Exchange Online host/port/encryption
        // even though config_json.connection only had the username.
        $this->assertNotNull($this->lastFactoryCall);
        $this->assertSame('live-token', $this->lastFactoryCall['secret']);
        $this->assertSame('xoauth2_client_credentials', $this->lastFactoryCall['authMode']);
        $this->assertSame('outlook.office365.com', $this->lastFactoryCall['connection']['host']);
        $this->assertSame(993, $this->lastFactoryCall['connection']['port']);
        $this->assertSame('ssl', $this->lastFactoryCall['connection']['encryption']);
    }

    public function test_callback_throws_with_microsoft_error_detail_when_token_endpoint_rejects(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response([
                'error' => 'invalid_client',
                'error_description' => "AADSTS7000215: Invalid client secret provided.\r\nTrace ID: abc-123",
            ], 401),
        ]);

        $connector = $this->app->make(ImapConnector::class);
        $inst = $this->installation();

        $url = $connector->initiateOAuth($inst->id);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $req = Request::create('/credentials', 'POST', [
            'state' => (string) ($q['state'] ?? ''),
            'ms_client_secret' => 'bad-secret',
        ]);

        try {
            $connector->handleOAuthCallback($inst->id, $req);
            $this->fail('Expected ConnectorAuthException.');
        } catch (ConnectorAuthException $e) {
            // Microsoft's error + first line of error_description is surfaced…
            $this->assertStringContainsString('invalid_client', $e->getMessage());
            $this->assertStringContainsString('Invalid client secret provided.', $e->getMessage());
            // …but not the noisy trailing trace lines, and never the secret.
            $this->assertStringNotContainsString('Trace ID', $e->getMessage());
            $this->assertStringNotContainsString('bad-secret', $e->getMessage());
        }
    }

    public function test_app_only_forces_exchange_host_over_a_stale_config(): void
    {
        $this->fakeTokenEndpoint(accessToken: 'live-token');

        $connector = $this->app->make(ImapConnector::class);
        // An installation switched from basic-auth carries a stale, non-Microsoft
        // host that the app-only schema hides from the operator.
        $inst = $this->installation(['connection' => [
            'username' => 'shared-mailbox@contoso.com',
            'host' => 'imap.attacker.example.com',
            'port' => 143,
            'encryption' => 'starttls',
        ]]);

        /** @var OAuthCredentialVault $vault */
        $vault = $this->app->make(OAuthCredentialVault::class);
        $vault->setCredentials(
            $inst->id,
            accessToken: 'stale-token',
            refreshToken: 'stored-client-secret',
            expiresAt: Carbon::now()->subMinute(),
            extra: ['auth_mode' => 'xoauth2_client_credentials', 'provider' => 'microsoft'],
        );

        $connector->health($inst->id);

        // The freshly-minted Microsoft bearer token must NEVER be sent to the
        // stale host — the Exchange endpoint is forced, not merely defaulted.
        $this->assertNotNull($this->lastFactoryCall);
        $this->assertSame('outlook.office365.com', $this->lastFactoryCall['connection']['host']);
        $this->assertSame(993, $this->lastFactoryCall['connection']['port']);
        $this->assertSame('ssl', $this->lastFactoryCall['connection']['encryption']);
        $this->assertSame('live-token', $this->lastFactoryCall['secret']);
    }

    public function test_app_only_login_rejection_surfaces_actionable_checklist(): void
    {
        $this->fakeTokenEndpoint();

        // A factory whose client rejects the login the way webklex does — ping()
        // throws ConnectorAuthException rather than returning false.
        $this->app->bind(
            ImapClientFactoryInterface::class,
            fn () => new class implements ImapClientFactoryInterface
            {
                public function make(array $connection, string $secret, string $authMode): ImapClientInterface
                {
                    return new class implements ImapClientInterface
                    {
                        public function listMailboxes(): array
                        {
                            return [];
                        }

                        public function selectMailbox(string $name): MailboxState
                        {
                            throw new \RuntimeException('n/a');
                        }

                        public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
                        {
                            return [];
                        }

                        public function fetchMessage(string $mailbox, int $uid): ImapMessage
                        {
                            throw new \RuntimeException('n/a');
                        }

                        public function ping(): bool
                        {
                            throw new ConnectorAuthException('IMAP authentication failed: rejected');
                        }

                        public function close(): void {}
                    };
                }
            }
        );

        $connector = $this->app->make(ImapConnector::class);
        $inst = $this->installation();

        $url = $connector->initiateOAuth($inst->id);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $req = Request::create('/credentials', 'POST', [
            'state' => (string) ($q['state'] ?? ''),
            'ms_client_secret' => 'a-secret',
        ]);

        try {
            $connector->handleOAuthCallback($inst->id, $req);
            $this->fail('Expected ConnectorAuthException.');
        } catch (ConnectorAuthException $e) {
            $this->assertStringContainsString('New-ServicePrincipal', $e->getMessage());
            // The original webklex auth error is preserved as the cause.
            $this->assertInstanceOf(ConnectorAuthException::class, $e->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{0: array<string,mixed>}>
     */
    public static function missingIdentifierProvider(): iterable
    {
        yield 'missing client id' => [['ms_client_id' => '']];
        yield 'missing tenant id' => [['ms_tenant_id' => '']];
    }

    #[DataProvider('missingIdentifierProvider')]
    public function test_callback_requires_tenant_and_client_id(array $override): void
    {
        $this->fakeTokenEndpoint();

        $connector = $this->app->make(ImapConnector::class);
        $inst = $this->installation($override);

        $url = $connector->initiateOAuth($inst->id);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $req = Request::create('/credentials', 'POST', [
            'state' => (string) ($q['state'] ?? ''),
            'ms_client_secret' => 'a-secret',
        ]);

        $this->expectException(ConnectorAuthException::class);
        $connector->handleOAuthCallback($inst->id, $req);
    }

    public function test_transient_token_error_is_retryable_not_an_auth_failure(): void
    {
        // A 503 from the identity endpoint is transient (R42): it must NOT be
        // reported as an auth failure, or the sync job would stop retrying and
        // flag good credentials as permanently bad.
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response('upstream down', 503),
        ]);

        $connector = $this->app->make(ImapConnector::class);
        $inst = $this->installation();

        $url = $connector->initiateOAuth($inst->id);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $req = Request::create('/credentials', 'POST', [
            'state' => (string) ($q['state'] ?? ''),
            'ms_client_secret' => 'a-secret',
        ]);

        $this->expectException(ConnectorApiException::class);
        $connector->handleOAuthCallback($inst->id, $req);
    }

    public function test_disconnect_clears_credentials_without_a_revoke_call(): void
    {
        $connector = $this->app->make(ImapConnector::class);
        $inst = $this->installation();

        /** @var OAuthCredentialVault $vault */
        $vault = $this->app->make(OAuthCredentialVault::class);
        $vault->setCredentials(
            $inst->id,
            accessToken: 'a',
            refreshToken: 'secret',
            expiresAt: Carbon::now()->addHour(),
            extra: ['auth_mode' => 'xoauth2_client_credentials', 'provider' => 'microsoft'],
        );

        Http::fake();
        $connector->disconnect($inst->id);

        // Microsoft has no client-credentials revoke endpoint — local clear only.
        Http::assertNothingSent();
        $this->assertNull($vault->getAccessToken($inst->id));
        $this->assertNull($vault->getRefreshToken($inst->id));
    }
}
