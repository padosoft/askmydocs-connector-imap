<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Live;

use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactory;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class ImapLiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CONNECTOR_IMAP_LIVE') !== '1') {
            $this->markTestSkipped('Set CONNECTOR_IMAP_LIVE=1 and IMAP_* env to run live tests.');
        }
    }

    public function test_live_login_and_list_folders(): void
    {
        $client = (new ImapClientFactory)->make([
            'host' => getenv('IMAP_HOST'),
            'port' => (int) (getenv('IMAP_PORT') ?: 993),
            'encryption' => getenv('IMAP_ENCRYPTION') ?: 'ssl',
            'username' => getenv('IMAP_USERNAME'),
        ], (string) getenv('IMAP_PASSWORD'), 'basic');

        $this->assertTrue($client->ping());
        $this->assertNotEmpty($client->listMailboxes());
        $client->close();
    }
}
