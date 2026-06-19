<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Feature;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;
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
 *
 * Since the default middleware now includes 'auth', behaviour tests use
 * actingAs() with a minimal in-memory User so the Authenticate middleware
 * passes. The dedicated unauthenticated test does NOT bypass auth.
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

    private function basicInstallation(string $tenantId = 'default'): ConnectorInstallation
    {
        // Set the TenantContext so BelongsToTenant auto-fills tenant_id on creation.
        app(TenantContext::class)->set($tenantId);

        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
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

    /**
     * Returns a minimal in-memory Authenticatable user (no DB table needed).
     */
    private function makeUser(): AuthUser
    {
        $user = new AuthUser;
        $user->id = 1;

        return $user;
    }

    // -------------------------------------------------------------------------
    // Config assertion: default middleware must include 'auth'
    // -------------------------------------------------------------------------

    public function test_default_middleware_config_includes_auth(): void
    {
        $middleware = config('connectors.providers.imap.routes.middleware');
        $this->assertIsArray($middleware);
        $this->assertContains('auth', $middleware, 'routes.middleware must include "auth" by default');
    }

    // -------------------------------------------------------------------------
    // test_basic_credential_form_renders
    // -------------------------------------------------------------------------

    public function test_basic_credential_form_renders(): void
    {
        $inst = $this->basicInstallation();

        $prefix = config('connectors.providers.imap.routes.prefix');

        // actingAs() satisfies the 'auth' middleware without hitting the database.
        $response = $this->actingAs($this->makeUser())
            ->get("/{$prefix}/{$inst->id}/credentials");

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

        // We bypass both CSRF and the session-bound state check so we can inject
        // the connector state directly. The session-state check is tested separately.
        $response = $this->actingAs($this->makeUser())
            ->withoutMiddleware(VerifyCsrfToken::class)
            ->withSession(["imap_oauth_state.{$inst->id}" => $state])
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
    // test_routes_present_when_enabled
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

    // -------------------------------------------------------------------------
    // SECURITY: test_unauthenticated_request_is_rejected
    // Proves that 'auth' in the default middleware blocks unauthenticated access.
    // This test must NOT use actingAs() or bypass Authenticate.
    // -------------------------------------------------------------------------

    public function test_unauthenticated_request_is_rejected(): void
    {
        $inst = $this->basicInstallation();

        $prefix = config('connectors.providers.imap.routes.prefix');

        // No actingAs() — request is unauthenticated.
        $response = $this->get("/{$prefix}/{$inst->id}/credentials");

        // Must NOT be 200: expect a redirect to login (302) or explicit 401/403.
        $this->assertNotSame(200, $response->getStatusCode(), 'Unauthenticated request must not return 200');
    }

    // -------------------------------------------------------------------------
    // SECURITY: test_cross_tenant_installation_returns_404
    // Proves the IDOR fix: a valid installation id belonging to a different
    // tenant must yield 404, not expose the wrong tenant's data.
    // -------------------------------------------------------------------------

    public function test_cross_tenant_installation_returns_404(): void
    {
        // Create an installation under tenant-a.
        $inst = $this->basicInstallation('tenant-a');

        // Switch the active tenant to tenant-b (simulates a different tenant's request).
        app(TenantContext::class)->set('tenant-b');

        $prefix = config('connectors.providers.imap.routes.prefix');

        // Authenticated user from tenant-b attempts to access tenant-a's installation by id.
        $response = $this->actingAs($this->makeUser())
            ->get("/{$prefix}/{$inst->id}/credentials");

        // Must be 404 — the installation is invisible to the wrong tenant.
        $response->assertStatus(404);
    }
}
