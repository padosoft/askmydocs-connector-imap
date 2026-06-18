<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MessageFilter;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class MessageFilterTest extends TestCase
{
    private function msg(array $over = []): ImapMessage
    {
        return new ImapMessage(
            uid: $over['uid'] ?? 1, uidValidity: 1, mailbox: 'INBOX',
            messageId: '<m@x>', inReplyTo: null, references: [],
            fromName: '', fromEmail: $over['from'] ?? 'mario@acme.com',
            to: $over['to'] ?? [], cc: $over['cc'] ?? [], date: null, subject: $over['subject'] ?? 'Ciao',
            flags: $over['flags'] ?? [], labels: [], textBody: 'b', htmlBody: null,
            rawHeaders: $over['headers'] ?? [], attachments: [],
        );
    }

    public function test_sender_exclude_by_domain(): void
    {
        $f = new MessageFilter(['senders' => ['exclude' => ['acme.com']]]);
        $this->assertFalse($f->passes($this->msg(['from' => 'x@acme.com'])));
        $this->assertTrue($f->passes($this->msg(['from' => 'x@other.com'])));
    }

    public function test_sender_include_allowlist(): void
    {
        $f = new MessageFilter(['senders' => ['include' => ['vip@acme.com']]]);
        $this->assertTrue($f->passes($this->msg(['from' => 'vip@acme.com'])));
        $this->assertFalse($f->passes($this->msg(['from' => 'other@acme.com'])));
    }

    public function test_subject_exclude_keyword(): void
    {
        $f = new MessageFilter(['subject' => ['exclude_keywords' => ['newsletter']]]);
        $this->assertFalse($f->passes($this->msg(['subject' => 'Our NEWSLETTER'])));
    }

    public function test_only_flagged(): void
    {
        $f = new MessageFilter(['only_flagged' => true]);
        $this->assertFalse($f->passes($this->msg(['flags' => []])));
        $this->assertTrue($f->passes($this->msg(['flags' => ['\\Flagged']])));
    }

    public function test_skip_auto_generated(): void
    {
        $f = new MessageFilter(['skip_auto_generated' => true]);
        $this->assertFalse($f->passes($this->msg(['headers' => ['precedence' => 'bulk']])));
        $this->assertFalse($f->passes($this->msg(['headers' => ['auto-submitted' => 'auto-replied']])));
        $this->assertTrue($f->passes($this->msg(['headers' => []])));
    }

    public function test_recipient_exclude_by_domain(): void
    {
        $f = new MessageFilter(['recipients' => ['exclude' => ['blocked.com']]]);
        // to contains blocked.com → fails
        $this->assertFalse($f->passes($this->msg([
            'to' => [['name' => 'X', 'email' => 'x@blocked.com']],
        ])));
        // to contains safe domain → passes
        $this->assertTrue($f->passes($this->msg([
            'to' => [['name' => 'Y', 'email' => 'y@safe.com']],
        ])));
    }

    public function test_recipient_include_allowlist(): void
    {
        $f = new MessageFilter(['recipients' => ['include' => ['vip@allowed.com']]]);
        // cc matches → passes
        $this->assertTrue($f->passes($this->msg([
            'cc' => [['name' => 'VIP', 'email' => 'vip@allowed.com']],
        ])));
        // neither to nor cc matches → fails
        $this->assertFalse($f->passes($this->msg([
            'to' => [['name' => 'Other', 'email' => 'other@allowed.com']],
        ])));
    }
}
