<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsConnectionSettings;
use Padosoft\AskMyDocsConnectorImap\ImapConnector;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class ConnectionSettingsSchemaTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function schema(): array
    {
        return $this->app->make(ImapConnector::class)->connectionSettingsSchema();
    }

    /** @return array<string,mixed> */
    private function field(string $name): array
    {
        foreach ($this->schema() as $field) {
            if ($field['name'] === $name) {
                return $field;
            }
        }
        $this->fail("Field '{$name}' not found in connectionSettingsSchema().");
    }

    public function test_connector_implements_supports_connection_settings(): void
    {
        $this->assertInstanceOf(SupportsConnectionSettings::class, $this->app->make(ImapConnector::class));
    }

    public function test_schema_exposes_the_full_documented_config_surface(): void
    {
        $names = array_map(static fn (array $f) => $f['name'], $this->schema());

        foreach ([
            'folders.include', 'folders.exclude', 'date_window_days',
            'only_unseen', 'only_flagged', 'reconcile_deletions',
            'body_format', 'skip_auto_generated', 'strip_quoted_history', 'redact_pii',
            'senders.include', 'senders.exclude',
            'recipients.include', 'recipients.exclude',
            'subject.include_keywords', 'subject.exclude_keywords',
            'attachments.enabled', 'attachments.allowed_extensions',
            'attachments.max_size_mb', 'attachments.max_per_email', 'attachments.skip_inline',
            'limits.max_messages_per_sync',
        ] as $expected) {
            $this->assertContains($expected, $names, "Settings schema is missing '{$expected}'.");
        }
    }

    public function test_every_setting_targets_config_and_is_never_secret(): void
    {
        foreach ($this->schema() as $field) {
            $this->assertSame('config', $field['target'], "Field '{$field['name']}' must target config.");
            $this->assertFalse($field['secret'], "Field '{$field['name']}' must not be secret.");
        }
    }

    public function test_folder_fields_are_live_multiselects_backed_by_discovery(): void
    {
        foreach (['folders.include', 'folders.exclude'] as $name) {
            $field = $this->field($name);
            $this->assertSame('multiselect', $field['type']);
            $this->assertSame('folders', $field['discovery']);
        }
    }

    public function test_date_window_and_filter_defaults_match_the_engine(): void
    {
        $this->assertSame(365, $this->field('date_window_days')['default']);
        $this->assertSame([], $this->field('folders.include')['default']);
        $this->assertSame(
            ['Trash', 'Spam', 'Junk', '[Gmail]/Spam', '[Gmail]/Trash'],
            $this->field('folders.exclude')['default'],
        );
        $this->assertSame('prefer_text', $this->field('body_format')['default']);
        $this->assertTrue($this->field('skip_auto_generated')['default']);
    }

    public function test_defaults_track_host_overridden_config_not_hardcoded_values(): void
    {
        // A host that overrides the engine config defaults must see those values
        // in the schema, otherwise the generic editor would persist the package
        // default and silently override the host's configured sync window.
        config()->set('connectors.providers.imap.defaults.date_window_days', 90);
        config()->set('connectors.providers.imap.defaults.folders_exclude', ['Junk']);
        config()->set('connectors.providers.imap.defaults.attachments.max_size_mb', 5);

        $this->assertSame(90, $this->field('date_window_days')['default']);
        $this->assertSame(['Junk'], $this->field('folders.exclude')['default']);
        $this->assertSame(5, $this->field('attachments.max_size_mb')['default']);
    }

    public function test_message_filter_fields_are_tags(): void
    {
        foreach (['senders.include', 'recipients.exclude', 'subject.include_keywords'] as $name) {
            $this->assertSame('tags', $this->field($name)['type']);
        }
    }
}
