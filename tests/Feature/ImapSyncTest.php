<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapAttachment;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\ImapConnector;
use Padosoft\AskMyDocsConnectorImap\Tests\Support\FakeImapClient;
use Padosoft\AskMyDocsConnectorImap\Tests\Support\SpyIngestionContract;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class ImapSyncTest extends TestCase
{
    use RefreshDatabase;

    private SpyIngestionContract $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = new SpyIngestionContract;
        $this->app->instance(ConnectorIngestionContract::class, $this->spy);
    }

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
                'project_key' => 'kb-imap',
                'connection' => ['host' => 'h', 'username' => 'u'],
                'attachments' => [
                    'enabled' => true,
                    'allowed_extensions' => ['pdf'],
                    'max_size_mb' => 25,
                    'max_per_email' => 20,
                    'skip_inline' => false,
                ],
            ],
            'status' => 'active',
        ]);
        $this->app->make(OAuthCredentialVault::class)->setCredentials($inst->id, accessToken: 'pw', refreshToken: null, expiresAt: null, extra: ['auth_mode' => 'basic']);

        return $inst;
    }

    public function test_sync_full_dispatches_email_and_attachment(): void
    {
        Storage::fake('local');
        $att = new ImapAttachment('fattura.pdf', 'application/pdf', 100, false, '%PDF-1.4');
        $msg = new ImapMessage(
            uid: 5, uidValidity: 1, mailbox: 'INBOX', messageId: '<m@x>', inReplyTo: null, references: [],
            fromName: 'Mario', fromEmail: 'mario@acme.com', to: [], cc: [], date: Carbon::now(),
            subject: 'Ciao', flags: [], labels: [], textBody: 'corpo', htmlBody: null, rawHeaders: [], attachments: [$att],
        );
        $this->seedClient(new FakeImapClient(['INBOX' => [$msg]], uidValidity: 1));
        $inst = $this->installation();

        $result = $this->app->make(ImapConnector::class)->syncFull($inst->id);

        // one email document + one attachment document
        $this->assertSame(2, $result->documentsAdded);
        $this->assertSame([], $result->errors);
    }

    public function test_incremental_persists_uid_watermark(): void
    {
        Storage::fake('local');
        $mk = fn (int $uid) => new ImapMessage(
            uid: $uid, uidValidity: 1, mailbox: 'INBOX', messageId: "<$uid@x>", inReplyTo: null, references: [],
            fromName: '', fromEmail: 'a@x', to: [], cc: [], date: Carbon::now(), subject: 'S', flags: [], labels: [],
            textBody: 'b', htmlBody: null, rawHeaders: [], attachments: [],
        );
        $this->seedClient(new FakeImapClient(['INBOX' => [$mk(1), $mk(2)]], uidValidity: 1));
        $inst = $this->installation();

        $this->app->make(ImapConnector::class)->syncIncremental($inst->id, Carbon::now()->subDay());

        $state = $this->app->make(OAuthCredentialVault::class)->getExtra($inst->id);
        $this->assertSame(2, $state['mailboxes_state']['INBOX']['last_uid']);
    }
}
