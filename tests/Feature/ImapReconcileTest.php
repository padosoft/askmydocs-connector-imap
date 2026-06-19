<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\ImapConnector;
use Padosoft\AskMyDocsConnectorImap\Tests\Support\FakeImapClient;
use Padosoft\AskMyDocsConnectorImap\Tests\Support\SpyIngestionContract;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class ImapReconcileTest extends TestCase
{
    use RefreshDatabase;

    private SpyIngestionContract $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = new SpyIngestionContract;
        $this->app->instance(ConnectorIngestionContract::class, $this->spy);
    }

    public function test_reconcile_soft_deletes_vanished_uids(): void
    {
        Storage::fake('local');
        $mk = fn (int $uid) => new ImapMessage(
            uid: $uid, uidValidity: 1, mailbox: 'INBOX', messageId: "<$uid@x>", inReplyTo: null, references: [],
            fromName: '', fromEmail: 'a@x', to: [], cc: [], date: Carbon::now(), subject: 'S', flags: [], labels: [],
            textBody: 'b', htmlBody: null, rawHeaders: [], attachments: [],
        );

        // First sync sees uids 1,2.
        $client = new FakeImapClient(['INBOX' => [$mk(1), $mk(2)]], uidValidity: 1);
        $this->app->bind(ImapClientFactoryInterface::class, fn () => new class($client) implements ImapClientFactoryInterface
        {
            public function __construct(public ImapClientInterface $c) {}

            public function make(array $connection, string $secret, string $authMode): ImapClientInterface
            {
                return $this->c;
            }
        });

        $inst = ConnectorInstallation::create([
            'tenant_id' => 'default', 'connector_name' => 'imap', 'status' => 'active',
            'config_json' => ['auth_mode' => 'basic', 'project_key' => 'kb', 'reconcile_deletions' => true, 'connection' => ['host' => 'h', 'username' => 'u']],
        ]);
        $this->app->make(OAuthCredentialVault::class)->setCredentials($inst->id, accessToken: 'pw', refreshToken: null, expiresAt: null, extra: []);

        $connector = $this->app->make(ImapConnector::class);
        $connector->syncFull($inst->id);

        // Now uid 2 disappeared from the server.
        $client2 = new FakeImapClient(['INBOX' => [$mk(1)]], uidValidity: 1);
        $this->app->bind(ImapClientFactoryInterface::class, fn () => new class($client2) implements ImapClientFactoryInterface
        {
            public function __construct(public ImapClientInterface $c) {}

            public function make(array $connection, string $secret, string $authMode): ImapClientInterface
            {
                return $this->c;
            }
        });

        // Re-resolve so the new factory (client2) is injected.
        $connector = $this->app->make(ImapConnector::class);
        $result = $connector->syncIncremental($inst->id, Carbon::now()->subDay());
        $this->assertGreaterThanOrEqual(1, $result->documentsRemoved);

        // Assert the soft-delete used the composite imap_doc_key, not the bare imap_uid.
        $this->assertNotEmpty($this->spy->softDeletes, 'Expected at least one soft-delete to be recorded.');
        $softDelete = $this->spy->softDeletes[0];
        $this->assertSame('imap_doc_key', $softDelete['metadataKey']);
        // The composite key for the vanished uid=2 in INBOX with uidvalidity=1 must be present.
        $this->assertStringContainsString('INBOX:', $softDelete['remoteId']);
        $this->assertStringContainsString(':2', $softDelete['remoteId']);
        $this->assertSame('INBOX:1:2', $softDelete['remoteId']);
    }
}
