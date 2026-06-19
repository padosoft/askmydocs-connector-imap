<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

/**
 * Verifies that the IMAP HTTP routes are NOT registered when routes.enabled is false.
 *
 * This is a separate test class (own file) so PHPUnit discovers it independently
 * from ImapHttpTest (which boots with routes.enabled=true).
 *
 * defineEnvironment here does NOT set routes.enabled — it stays at the package
 * default of false, so boot() skips loadRoutesFrom and the routes never exist.
 */
final class ImapHttpRoutesDisabledTest extends TestCase
{
    use RefreshDatabase;

    // Intentionally no override — routes.enabled defaults to false from config/imap.php.

    public function test_routes_absent_when_disabled(): void
    {
        $prefix = config('connectors.providers.imap.routes.prefix', 'admin/connectors/imap');

        $response = $this->get("/{$prefix}/1/credentials");

        $response->assertStatus(404);
    }
}
