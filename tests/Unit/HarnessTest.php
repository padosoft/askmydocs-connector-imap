<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Tests\Support\FakeImapClient;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class HarnessTest extends TestCase
{
    public function test_fake_client_lists_and_searches(): void
    {
        $msg = new ImapMessage(
            uid: 10, uidValidity: 1, mailbox: 'INBOX', messageId: '<a@x>',
            inReplyTo: null, references: [], fromName: 'A', fromEmail: 'a@x',
            to: [], cc: [], date: null, subject: 'S', flags: [], labels: [],
            textBody: 'hi', htmlBody: null, rawHeaders: [], attachments: [],
        );
        $client = new FakeImapClient(['INBOX' => [$msg]]);

        $this->assertSame(['INBOX'], $client->listMailboxes());
        $this->assertSame([10], $client->searchUids('INBOX', null, 5));
        $this->assertSame([], $client->searchUids('INBOX', null, 10));
    }
}
