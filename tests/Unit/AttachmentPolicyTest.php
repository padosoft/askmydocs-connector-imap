<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Padosoft\AskMyDocsConnectorImap\Imap\AttachmentPolicy;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapAttachment;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class AttachmentPolicyTest extends TestCase
{
    private function policy(array $over = []): AttachmentPolicy
    {
        return new AttachmentPolicy(array_merge([
            'enabled' => true,
            'allowed_extensions' => ['pdf', 'docx'],
            'max_size_mb' => 1,
            'max_per_email' => 5,
            'skip_inline' => true,
        ], $over));
    }

    private function att(string $name, int $size, bool $inline = false): ImapAttachment
    {
        return new ImapAttachment($name, 'application/octet-stream', $size, $inline, 'x');
    }

    public function test_accepts_allowed_extension_within_size(): void
    {
        $this->assertTrue($this->policy()->accepts($this->att('a.pdf', 500)));
    }

    public function test_rejects_disallowed_extension(): void
    {
        $this->assertFalse($this->policy()->accepts($this->att('a.zip', 10)));
    }

    public function test_rejects_oversize(): void
    {
        $this->assertFalse($this->policy()->accepts($this->att('a.pdf', 2 * 1024 * 1024)));
    }

    public function test_rejects_inline_when_skip_inline(): void
    {
        $this->assertFalse($this->policy()->accepts($this->att('a.pdf', 10, true)));
    }

    public function test_disabled_rejects_everything(): void
    {
        $this->assertFalse($this->policy(['enabled' => false])->accepts($this->att('a.pdf', 10)));
    }
}
