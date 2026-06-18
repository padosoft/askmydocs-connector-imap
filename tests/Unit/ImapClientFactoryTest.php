<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactory;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class ImapClientFactoryTest extends TestCase
{
    public function test_make_returns_client_without_connecting(): void
    {
        $client = (new ImapClientFactory)->make(
            ['host' => 'imap.test', 'port' => 993, 'encryption' => 'ssl', 'username' => 'u'],
            'secret',
            'basic',
        );

        $this->assertInstanceOf(ImapClientInterface::class, $client);
    }
}
