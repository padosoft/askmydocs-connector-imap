<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorCredential;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\ImapConnector;
use Padosoft\AskMyDocsConnectorImap\Tests\Support\FakeImapClient;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class ImapXoauthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(
            ImapClientFactoryInterface::class,
            fn () => new class implements ImapClientFactoryInterface
            {
                public function make(array $connection, string $secret, string $authMode): ImapClientInterface
                {
                    return new FakeImapClient(['INBOX' => []]);
                }
            }
        );

        // Set Google provider config for tests
        config([
            'connectors.providers.imap.xoauth2.google' => [
                'client_id' => 'test-client-id',
                'client_secret' => 'test-client-secret',
                'redirect_uri' => 'https://app.test/oauth/callback',
                'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
                'imap_host' => 'imap.gmail.com',
                'scopes' => 'https://mail.google.com/',
                'revoke_url' => 'https://oauth2.googleapis.com/revoke',
            ],
        ]);
    }

    private function xoauthInstallation(): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'config_json' => [
                'auth_mode' => 'xoauth2',
                'xoauth2_provider' => 'google',
                'connection' => [
                    'host' => 'imap.gmail.com',
                    'port' => 993,
                    'encryption' => 'ssl',
                    'username' => 'user@gmail.com',
                ],
            ],
            'status' => 'pending',
        ]);
    }

    public function test_xoauth_initiate_builds_authorize_url_with_scopes_and_state(): void
    {
        $connector = $this->app->make(ImapConnector::class);
        $inst = $this->xoauthInstallation();

        $url = $connector->initiateOAuth($inst->id);

        $this->assertStringContainsString('accounts.google.com', $url);
        $this->assertStringContainsString('test-client-id', $url);
        $this->assertStringContainsString(urlencode('https://app.test/oauth/callback'), $url);
        $this->assertStringContainsString(urlencode('https://mail.google.com/'), $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertNotEmpty($q['state'] ?? '');
    }

    public function test_xoauth_callback_exchanges_code_and_stores_tokens(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'fresh-refresh-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
        ]);

        $connector = $this->app->make(ImapConnector::class);
        $inst = $this->xoauthInstallation();

        // Initiate to seed a state token
        $url = $connector->initiateOAuth($inst->id);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $state = (string) ($q['state'] ?? '');

        $req = Request::create('/oauth/callback', 'GET', [
            'state' => $state,
            'code' => 'auth-code-123',
        ]);
        $connector->handleOAuthCallback($inst->id, $req);

        /** @var OAuthCredentialVault $vault */
        $vault = $this->app->make(OAuthCredentialVault::class);

        // Access token stored and retrievable (not expired)
        $this->assertSame('fresh-access-token', $vault->getAccessToken($inst->id));

        // Refresh token stored
        $this->assertSame('fresh-refresh-token', $vault->getRefreshToken($inst->id));

        // expires_at is non-null (set to ~now+3600)
        $row = ConnectorCredential::query()
            ->where('connector_installation_id', $inst->id)
            ->first();
        $this->assertNotNull($row?->expires_at);

        // Audit was emitted — we can verify via Http::assertSent that the token endpoint was called
        Http::assertSent(fn ($req) => str_contains((string) $req->url(), 'oauth2.googleapis.com/token'));
    }

    public function test_refresh_token_if_expired_rotates_access_token(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'rotated-access-token',
                'refresh_token' => 'rotated-refresh-token',
                'expires_in' => 3600,
            ], 200),
        ]);

        $connector = $this->app->make(ImapConnector::class);
        $inst = $this->xoauthInstallation();

        /** @var OAuthCredentialVault $vault */
        $vault = $this->app->make(OAuthCredentialVault::class);

        // Seed an EXPIRED access token + a valid refresh token
        $vault->setCredentials(
            $inst->id,
            accessToken: 'expired-access-token',
            refreshToken: 'valid-refresh-token',
            expiresAt: Carbon::now()->subMinute(),   // already expired
            extra: ['auth_mode' => 'xoauth2', 'provider' => 'google'],
        );

        // After seeding, getAccessToken returns null (expired)
        $this->assertNull($vault->getAccessToken($inst->id));

        // refreshTokenIfExpired should exchange and return new token
        $result = $connector->refreshTokenIfExpired($inst->id);

        $this->assertSame('rotated-access-token', $result);

        // New token persisted
        $this->assertSame('rotated-access-token', $vault->getAccessToken($inst->id));
        $this->assertSame('rotated-refresh-token', $vault->getRefreshToken($inst->id));
    }

    public function test_disconnect_revokes_google_token(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/revoke' => Http::response('', 200),
        ]);

        $connector = $this->app->make(ImapConnector::class);
        $inst = $this->xoauthInstallation();

        /** @var OAuthCredentialVault $vault */
        $vault = $this->app->make(OAuthCredentialVault::class);

        // Seed valid credentials
        $vault->setCredentials(
            $inst->id,
            accessToken: 'access-to-revoke',
            refreshToken: 'refresh-to-revoke',
            expiresAt: Carbon::now()->addHour(),
            extra: ['auth_mode' => 'xoauth2', 'provider' => 'google'],
        );

        $connector->disconnect($inst->id);

        // Revoke endpoint was called
        Http::assertSent(fn ($req) => str_contains((string) $req->url(), 'oauth2.googleapis.com/revoke'));

        // Credentials cleared
        $this->assertNull($vault->getAccessToken($inst->id));
        $this->assertNull($vault->getRefreshToken($inst->id));
    }

    public function test_disconnect_does_not_fail_when_revoke_endpoint_errors(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/revoke' => Http::response('error', 400),
        ]);

        $connector = $this->app->make(ImapConnector::class);
        $inst = $this->xoauthInstallation();

        /** @var OAuthCredentialVault $vault */
        $vault = $this->app->make(OAuthCredentialVault::class);

        $vault->setCredentials(
            $inst->id,
            accessToken: 'access-token',
            refreshToken: null,
            expiresAt: Carbon::now()->addHour(),
            extra: ['auth_mode' => 'xoauth2', 'provider' => 'google'],
        );

        // Must not throw even when revoke returns 400
        $connector->disconnect($inst->id);

        // Credentials still cleared
        $this->assertNull($vault->getAccessToken($inst->id));
    }
}
