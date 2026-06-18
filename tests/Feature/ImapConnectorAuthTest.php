<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\ImapConnector;
use Padosoft\AskMyDocsConnectorImap\Tests\Support\FakeImapClient;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class ImapConnectorAuthTest extends TestCase
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
    }

    private function installation(): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'config_json' => ['auth_mode' => 'basic', 'connection' => ['host' => 'imap.test', 'port' => 993, 'encryption' => 'ssl', 'username' => 'support@us.com']],
            'status' => 'pending',
        ]);
    }

    public function test_key_and_display_name(): void
    {
        $c = $this->app->make(ImapConnector::class);
        $this->assertSame('imap', $c->key());
        $this->assertSame('Email (IMAP)', $c->displayName());
    }

    public function test_basic_auth_callback_stores_password_in_vault(): void
    {
        $c = $this->app->make(ImapConnector::class);
        $inst = $this->installation();

        $url = $c->initiateOAuth($inst->id);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $req = Request::create('/cb', 'POST', [
            'state' => (string) ($q['state'] ?? ''),
            'password' => 's3cret',
        ]);
        $c->handleOAuthCallback($inst->id, $req);

        $vault = $this->app->make(OAuthCredentialVault::class);
        $this->assertSame('s3cret', $vault->getAccessToken($inst->id));
    }
}
