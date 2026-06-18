<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Padosoft\AskMyDocsConnectorImap\Imap\EmailToMarkdown;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapAttachment;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class EmailToMarkdownTest extends TestCase
{
    private function message(?string $text, ?string $html, array $attachments = []): ImapMessage
    {
        return new ImapMessage(
            uid: 1, uidValidity: 1, mailbox: 'INBOX', messageId: '<m@x>',
            inReplyTo: null, references: [], fromName: 'Mario Rossi', fromEmail: 'mario@acme.com',
            to: [['name' => '', 'email' => 'support@us.com']], cc: [], date: null,
            subject: 'Richiesta', flags: [], labels: [], textBody: $text, htmlBody: $html,
            rawHeaders: [], attachments: $attachments,
        );
    }

    public function test_renders_header_block_and_text_body(): void
    {
        $md = (new EmailToMarkdown)->render($this->message('Ciao mondo', null));

        $this->assertStringContainsString('# Richiesta', $md);
        $this->assertStringContainsString('Mario Rossi <mario@acme.com>', $md);
        $this->assertStringContainsString('support@us.com', $md);
        $this->assertStringContainsString('Ciao mondo', $md);
    }

    public function test_falls_back_to_html_converted_to_markdown(): void
    {
        $md = (new EmailToMarkdown)->render($this->message(null, '<p>In <b>allegato</b></p>'));

        $this->assertStringContainsString('allegato', $md);
        $this->assertStringNotContainsString('<p>', $md);
    }

    public function test_lists_attachment_filenames(): void
    {
        $att = new ImapAttachment('fattura.pdf', 'application/pdf', 10, false, 'x');
        $md = (new EmailToMarkdown)->render($this->message('hi', null, [$att]));

        $this->assertStringContainsString('fattura.pdf', $md);
    }

    public function test_strip_quoted_history_removes_reply_chain(): void
    {
        $body = "Risposta nuova\n> vecchia citazione\n> altra riga";
        $md = (new EmailToMarkdown)->render($this->message($body, null), true, true);

        $this->assertStringContainsString('Risposta nuova', $md);
        $this->assertStringNotContainsString('vecchia citazione', $md);
    }
}
