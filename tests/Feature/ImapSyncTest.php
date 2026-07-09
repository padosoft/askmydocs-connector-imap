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
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;
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

    private function installationWithoutAttachments(): ConnectorInstallation
    {
        $inst = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'config_json' => [
                'auth_mode' => 'basic',
                'project_key' => 'kb-imap',
                'connection' => ['host' => 'h', 'username' => 'u'],
            ],
            'status' => 'active',
        ]);
        $this->app->make(OAuthCredentialVault::class)->setCredentials($inst->id, accessToken: 'pw', refreshToken: null, expiresAt: null, extra: ['auth_mode' => 'basic']);

        return $inst;
    }

    public function test_sync_full_uses_provider_default_attachment_allowlist(): void
    {
        Storage::fake('local');
        $att = new ImapAttachment('fattura.pdf', 'application/pdf', 100, false, '%PDF-1.4');
        $msg = new ImapMessage(
            uid: 7, uidValidity: 1, mailbox: 'INBOX', messageId: '<m2@x>', inReplyTo: null, references: [],
            fromName: 'Mario', fromEmail: 'mario@acme.com', to: [], cc: [], date: Carbon::now(),
            subject: 'Default allowlist', flags: [], labels: [], textBody: 'corpo', htmlBody: null, rawHeaders: [], attachments: [$att],
        );
        $this->seedClient(new FakeImapClient(['INBOX' => [$msg]], uidValidity: 1));
        $inst = $this->installationWithoutAttachments();

        $result = $this->app->make(ImapConnector::class)->syncFull($inst->id);

        // provider default allows pdf — 1 email + 1 attachment must both be ingested
        $this->assertSame(2, $result->documentsAdded);
        $this->assertSame([], $result->errors);
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

    public function test_missing_included_folder_is_surfaced_but_sync_continues(): void
    {
        Storage::fake('local');
        $msg = new ImapMessage(
            uid: 11, uidValidity: 1, mailbox: 'INBOX', messageId: '<mm@x>', inReplyTo: null, references: [],
            fromName: 'Mario', fromEmail: 'mario@acme.com', to: [], cc: [], date: Carbon::now(),
            subject: 'Hello', flags: [], labels: [], textBody: 'body', htmlBody: null, rawHeaders: [], attachments: [],
        );
        // Only INBOX exists upstream; the operator also whitelisted 'Archive'
        // (since deleted from webmail).
        $this->seedClient(new FakeImapClient(['INBOX' => [$msg]], uidValidity: 1));

        $inst = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'config_json' => [
                'auth_mode' => 'basic',
                'project_key' => 'kb-imap',
                'connection' => ['host' => 'h', 'username' => 'u'],
                'folders' => ['include' => ['INBOX', 'Archive']],
            ],
            'status' => 'active',
        ]);
        $this->app->make(OAuthCredentialVault::class)->setCredentials($inst->id, accessToken: 'pw', refreshToken: null, expiresAt: null, extra: ['auth_mode' => 'basic']);

        $result = $this->app->make(ImapConnector::class)->syncFull($inst->id);

        // INBOX is still ingested — the connector keeps working...
        $this->assertSame(1, $result->documentsAdded);
        // ...and the missing 'Archive' folder is surfaced as a non-fatal note.
        $this->assertNotEmpty($result->errors);
        $this->assertStringContainsString('Archive', implode("\n", $result->errors));
        $this->assertStringContainsString('not found upstream', implode("\n", $result->errors));
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

    public function test_sync_full_uses_default_project_when_project_key_is_empty(): void
    {
        config()->set('kb.ingest.default_project', 'tenant-kb');
        Storage::fake('local');
        $msg = new ImapMessage(
            uid: 9, uidValidity: 1, mailbox: 'INBOX', messageId: '<m3@x>', inReplyTo: null, references: [],
            fromName: 'Mario', fromEmail: 'mario@acme.com', to: [], cc: [], date: Carbon::now(),
            subject: 'Inherited project key', flags: [], labels: [], textBody: 'corpo', htmlBody: null, rawHeaders: [], attachments: [],
        );
        $this->seedClient(new FakeImapClient(['INBOX' => [$msg]], uidValidity: 1));

        $inst = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'config_json' => [
                'auth_mode' => 'basic',
                'project_key' => '',
                'connection' => ['host' => 'h', 'username' => 'u'],
            ],
            'status' => 'active',
        ]);
        $this->app->make(OAuthCredentialVault::class)->setCredentials($inst->id, accessToken: 'pw', refreshToken: null, expiresAt: null, extra: ['auth_mode' => 'basic']);

        $result = $this->app->make(ImapConnector::class)->syncFull($inst->id);

        $this->assertSame(1, $result->documentsAdded);
        $this->assertSame('tenant-kb', $this->spy->dispatches[0]['projectKey']);
    }

    public function test_transport_drop_on_one_folder_does_not_fail_whole_sync(): void
    {
        Storage::fake('local');
        $msg = new ImapMessage(
            uid: 3, uidValidity: 1, mailbox: 'INBOX', messageId: '<bp@x>', inReplyTo: null, references: [],
            fromName: 'Mario', fromEmail: 'mario@acme.com', to: [], cc: [], date: Carbon::now(),
            subject: 'survives the drop', flags: [], labels: [], textBody: 'body', htmlBody: null, rawHeaders: [], attachments: [],
        );

        // A client that drops the connection when the accented 'Attività' folder
        // is scanned (Exchange Online cutting a long IMAP session — the next write
        // hits a dead socket), but serves INBOX fine.
        $client = new class(['INBOX' => [$msg]]) implements ImapClientInterface
        {
            public int $closes = 0;

            /** @param array<string,list<ImapMessage>> $box */
            public function __construct(private array $box) {}

            public function listMailboxes(): array
            {
                return ['Attività', 'INBOX'];
            }

            public function selectMailbox(string $name): MailboxState
            {
                if ($name === 'Attività') {
                    throw new \RuntimeException('fwrite(): SSL: Broken pipe');
                }

                return new MailboxState(1, 0);
            }

            public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
            {
                return array_map(static fn (ImapMessage $m) => $m->uid, $this->box[$mailbox] ?? []);
            }

            public function fetchMessage(string $mailbox, int $uid): ImapMessage
            {
                foreach ($this->box[$mailbox] ?? [] as $m) {
                    if ($m->uid === $uid) {
                        return $m;
                    }
                }
                throw new \RuntimeException("no fake message uid={$uid}");
            }

            public function ping(): bool
            {
                return true;
            }

            public function close(): void
            {
                $this->closes++;
            }
        };

        $this->seedClient($client);
        $inst = $this->installation();

        $result = $this->app->make(ImapConnector::class)->syncFull($inst->id);

        // INBOX is still ingested despite the dropped 'Attività' folder...
        $this->assertSame(1, $result->documentsAdded);
        // ...the drop is surfaced as a NON-fatal error (run not aborted)...
        $this->assertNotEmpty($result->errors);
        $errors = implode("\n", $result->errors);
        $this->assertStringContainsString('Attività', $errors);
        $this->assertStringContainsString('Broken pipe', $errors);
        // ...the dead connection was dropped so the next folder reconnects...
        $this->assertGreaterThanOrEqual(1, $client->closes);
        // ...and the good folder's UID cursor is checkpointed for the next run.
        $state = $this->app->make(OAuthCredentialVault::class)->getExtra($inst->id);
        $this->assertSame(3, $state['mailboxes_state']['INBOX']['last_uid']);
        $this->assertArrayNotHasKey('Attività', $state['mailboxes_state']);
    }
}
