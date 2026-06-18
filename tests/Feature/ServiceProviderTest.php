<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Feature;

use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_provider_merges_defaults(): void
    {
        $this->assertSame(25, config('connectors.providers.imap.defaults.attachments.max_size_mb'));
        $this->assertSame(365, config('connectors.providers.imap.defaults.date_window_days'));
        $this->assertContains('Trash', config('connectors.providers.imap.defaults.folders_exclude'));
    }
}
