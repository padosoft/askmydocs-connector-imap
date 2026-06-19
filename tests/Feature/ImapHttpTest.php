<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Feature;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\ImapConnector;
use Padosoft\AskMyDocsConnectorImap\Tests\Support\FakeImapClient;
use Padosoft\AskMyDocsConnectorImap\Tests\Support\SpyIngestionContract;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

/**
 * Tests for the optional publishable HTTP layer (routes.enabled = true).
 *
 * Routes are registered inside ImapServiceProvider::boot() only when
 * connectors.providers.imap.routes.enabled is true.
 * Because Testbench boots providers AFTER defineEnvironment, we set the
 * config flag there so it is true before boot() runs, ensuring the route
 * group is registered.
 */
final class ImapHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Must be set BEFORE boot() so the route group is registered.
        $app['config']->set('connectors.providers.imap.routes.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Bind a fake factory so handleOAuthCallback's ping() call succeeds.
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

        // Bind spy ingestion so BaseConnector's dispatchIngestion does not throw.
        $this->app->bind(ConnectorIngestionContract::class, SpyIngestionContract::class);
    }

    private function basicInstallation(): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'config_json' => [
                'auth_mode' => 'basic',
                'connection' => [
                    'host' => 'imap.test',
                    'port' => 993,
                    'encryption' => 'ssl',
                    'username' => 'support@example.com',
                ],
            ],
            'status' => 'pending',
        ]);
    }

    // -------------------------------------------------------------------------
    // test_basic_credential_form_renders
    // -------------------------------------------------------------------------

    public function test_basic_credential_form_renders(): void
    {
        $inst = $this->basicInstallation();

        $prefix = config('connectors.providers.imap.routes.prefix');
        $response = $this->get("/{$prefix}/{$inst->id}/credentials");

        $response->assertStatus(200);
        $response->assertSee('host', false);
        $response->assertSee('username', false);
        $response->assertSee('password', false);
    }

    // -------------------------------------------------------------------------
    // test_basic_credentials_post_stores_password
    // -------------------------------------------------------------------------

    public function test_basic_credentials_post_stores_password(): void
    {
        $inst = $this->basicInstallation();

        // Obtain a real state from the connector so handleOAuthCallback accepts it.
        /** @var ImapConnector $connector */
        $connector = $this->app->make(ImapConnector::class);
        $formUrl = $connector->initiateOAuth($inst->id);
        parse_str((string) parse_url($formUrl, PHP_URL_QUERY), $q);
        $state = (string) ($q['state'] ?? '');

        $prefix = config('connectors.providers.imap.routes.prefix');

        $response = $this->withoutMiddleware(VerifyCsrfToken::class)
            ->post("/{$prefix}/{$inst->id}/credentials", [
                'host' => 'imap.test',
                'port' => '993',
                'encryption' => 'ssl',
                'username' => 'support@example.com',
                'password' => 's3cret!',
                'state' => $state,
            ]);

        // Should redirect back (or to success URL) with success.
        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Vault should now hold the password as the access token.
        /** @var OAuthCredentialVault $vault */
        $vault = $this->app->make(OAuthCredentialVault::class);
        $this->assertSame('s3cret!', $vault->getAccessToken($inst->id));
    }

    // -------------------------------------------------------------------------
    // test_routes_absent_when_disabled — separate class below, but we can use
    // a workaround here: temporarily override the router state.
    // The cleanest way in Testbench is to verify the route list directly.
    // -------------------------------------------------------------------------

    public function test_routes_present_when_enabled(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $routes = $router->getRoutes();

        $found = false;
        foreach ($routes->getRoutes() as $route) {
            if (str_contains($route->uri(), 'credentials')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Credential routes should be registered when routes.enabled=true');
    }
}
