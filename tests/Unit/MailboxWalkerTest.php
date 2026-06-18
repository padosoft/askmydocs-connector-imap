<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxWalker;
use Padosoft\AskMyDocsConnectorImap\Tests\Support\FakeImapClient;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class MailboxWalkerTest extends TestCase
{
    private function client(): FakeImapClient
    {
        $mk = fn (int $uid) => new ImapMessage(
            uid: $uid, uidValidity: 1, mailbox: 'INBOX', messageId: "<$uid@x>",
            inReplyTo: null, references: [], fromName: '', fromEmail: 'a@x',
            to: [], cc: [], date: null, subject: 'S', flags: [], labels: [],
            textBody: 'b', htmlBody: null, rawHeaders: [], attachments: [],
        );

        return new FakeImapClient([
            'INBOX' => [$mk(1), $mk(2), $mk(3)],
            'Trash' => [$mk(9)],
        ], uidValidity: 1);
    }

    public function test_excludes_configured_folders(): void
    {
        $w = new MailboxWalker($this->client(), ['folders' => ['exclude' => ['Trash']]]);
        $this->assertSame(['INBOX'], $w->selectedMailboxes());
    }

    public function test_include_list_wins(): void
    {
        $w = new MailboxWalker($this->client(), ['folders' => ['include' => ['Trash']]]);
        $this->assertSame(['Trash'], $w->selectedMailboxes());
    }

    public function test_incremental_uses_last_uid_when_uidvalidity_matches(): void
    {
        $w = new MailboxWalker($this->client(), []);
        $r = $w->incrementalUids('INBOX', ['uidvalidity' => 1, 'last_uid' => 1], null);
        $this->assertSame([2, 3], $r['uids']);
        $this->assertSame(1, $r['uidValidity']);
    }

    public function test_incremental_rescans_when_uidvalidity_changed(): void
    {
        $w = new MailboxWalker($this->client(), []);
        $r = $w->incrementalUids('INBOX', ['uidvalidity' => 999, 'last_uid' => 2], null);
        $this->assertSame([1, 2, 3], $r['uids']);
    }
}
