<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsFolderDiscovery;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
use Padosoft\AskMyDocsConnectorImap\ImapConnector;
use Padosoft\AskMyDocsConnectorImap\Tests\Support\FakeImapClient;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class FolderDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function seedClient(ImapClientInterface $client): void
    {
        $this->app->bind(ImapClientFactoryInterface::class, fn () => new class($client) implements ImapClientFactoryInterface
        {
            public function __construct(private ImapClientInterface $c) {}

            public function make(array $connection, string $secret, string $authMode): ImapClientInterface
            {
                return $this->c;
            }
        });
    }

    private function installation(): ConnectorInstallation
    {
        $inst = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'config_json' => [
                'auth_mode' => 'basic',
                'connection' => ['host' => 'h', 'username' => 'u'],
            ],
            'status' => 'active',
        ]);
        $this->app->make(OAuthCredentialVault::class)->setCredentials(
            $inst->id, accessToken: 'pw', refreshToken: null, expiresAt: null, extra: ['auth_mode' => 'basic'],
        );

        return $inst;
    }

    public function test_connector_implements_supports_folder_discovery(): void
    {
        $this->assertInstanceOf(SupportsFolderDiscovery::class, $this->app->make(ImapConnector::class));
    }

    public function test_lists_the_live_mailboxes_verbatim(): void
    {
        $inst = $this->installation();
        $client = new FakeImapClient(['INBOX' => [], 'Archive' => [], '[Gmail]/Sent Mail' => []]);
        $this->seedClient($client);

        $folders = $this->app->make(ImapConnector::class)->listAvailableFolders($inst->id);

        $this->assertSame(['INBOX', 'Archive', '[Gmail]/Sent Mail'], $folders);
        $this->assertTrue($client->closed, 'The client must be closed after discovery.');
    }

    public function test_empty_mailbox_set_is_a_valid_result(): void
    {
        $inst = $this->installation();
        $this->seedClient(new FakeImapClient([]));

        $this->assertSame([], $this->app->make(ImapConnector::class)->listAvailableFolders($inst->id));
    }

    public function test_unreachable_server_surfaces_as_connector_api_exception(): void
    {
        $inst = $this->installation();
        $client = new class implements ImapClientInterface
        {
            public bool $closed = false;

            public function listMailboxes(): array
            {
                throw new ConnectorApiException('IMAP connect failed: timeout');
            }

            public function selectMailbox(string $name): MailboxState
            {
                throw new \LogicException('not exercised');
            }

            public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
            {
                return [];
            }

            public function fetchMessage(string $mailbox, int $uid): ImapMessage
            {
                throw new \LogicException('not exercised');
            }

            public function ping(): bool
            {
                return false;
            }

            public function close(): void
            {
                $this->closed = true;
            }
        };
        $this->seedClient($client);

        $this->expectException(ConnectorApiException::class);
        try {
            $this->app->make(ImapConnector::class)->listAvailableFolders($inst->id);
        } finally {
            $this->assertTrue($client->closed, 'The client must be closed even when listMailboxes() throws.');
        }
    }
}
