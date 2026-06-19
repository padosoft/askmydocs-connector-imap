<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm;
use Padosoft\AskMyDocsConnectorImap\ImapConnector;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class CredentialFormSchemaTest extends TestCase
{
    private function schema(): array
    {
        return $this->app->make(ImapConnector::class)->credentialFormSchema();
    }

    private function field(string $name): array
    {
        foreach ($this->schema() as $field) {
            if ($field['name'] === $name) {
                return $field;
            }
        }
        $this->fail("Field '{$name}' not found in credentialFormSchema().");
    }

    public function test_connector_implements_supports_credential_form(): void
    {
        $this->assertInstanceOf(SupportsCredentialForm::class, $this->app->make(ImapConnector::class));
    }

    public function test_schema_is_non_empty(): void
    {
        $this->assertNotEmpty($this->schema());
    }

    public function test_every_entry_has_eleven_keys(): void
    {
        $expected = ['name', 'label', 'type', 'target', 'required', 'secret', 'default', 'options', 'showIf', 'help', 'group'];
        sort($expected);

        foreach ($this->schema() as $entry) {
            $keys = array_keys($entry);
            sort($keys);
            $this->assertSame($expected, $keys, "Field '{$entry['name']}' does not have the expected 11 keys.");
        }
    }

    public function test_password_field_is_secret_and_targets_secret(): void
    {
        $password = $this->field('password');
        $this->assertTrue($password['secret']);
        $this->assertSame('secret', $password['target']);
    }

    public function test_auth_mode_field_targets_auth_mode_and_has_basic_and_xoauth2_options(): void
    {
        $authMode = $this->field('auth_mode');
        $this->assertSame('auth_mode', $authMode['target']);
        $this->assertArrayHasKey('basic', $authMode['options']);
        $this->assertArrayHasKey('xoauth2', $authMode['options']);
    }

    public function test_connection_fields_target_connection(): void
    {
        foreach (['host', 'port', 'encryption', 'username'] as $name) {
            $field = $this->field($name);
            $this->assertSame('connection', $field['target'], "Field '{$name}' should target 'connection'.");
        }
    }
}
