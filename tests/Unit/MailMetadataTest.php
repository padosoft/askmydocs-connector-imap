<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Carbon\Carbon;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Support\MailMetadata;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class MailMetadataTest extends TestCase
{
    public function test_build_includes_imap_source_fields(): void
    {
        $m = new ImapMessage(
            uid: 42, uidValidity: 7, mailbox: 'INBOX', messageId: '<id@x>',
            inReplyTo: null, references: ['<r1@x>'], fromName: 'Mario', fromEmail: 'mario@acme.com',
            to: [['name' => '', 'email' => 'support@us.com']], cc: [], date: Carbon::parse('2026-06-15T12:00:00Z'),
            subject: 'Ciao', flags: ['\\Seen'], labels: ['support'],
            textBody: 'b', htmlBody: null, rawHeaders: [], attachments: [],
        );

        $meta = (new MailMetadata)->build(99, $m);
        $json = json_encode($meta);

        $this->assertStringContainsString('mario@acme.com', $json);
        $this->assertStringContainsString('INBOX', $json);
        $this->assertStringContainsString('42', $json);
        $this->assertStringContainsString('support', $json);
    }
}
