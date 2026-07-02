<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

use Webklex\PHPIMAP\ClientManager;

final class ImapClientFactory implements ImapClientFactoryInterface
{
    public function make(array $connection, string $secret, string $authMode): ImapClientInterface
    {
        $cm = new ClientManager;
        $client = $cm->make([
            'host' => (string) ($connection['host'] ?? ''),
            'port' => (int) ($connection['port'] ?? 993),
            'encryption' => (string) ($connection['encryption'] ?? 'ssl'),
            'validate_cert' => (bool) ($connection['validate_cert'] ?? true),
            'username' => (string) ($connection['username'] ?? ''),
            'password' => $secret,
            // webklex uses 'oauth' string for XOAuth2; null means basic LOGIN.
            // Both the delegated ('xoauth2') and the Microsoft 365 app-only
            // ('xoauth2_client_credentials') modes authenticate via SASL XOAUTH2
            // with a bearer access token as the secret.
            'authentication' => in_array($authMode, ['xoauth2', 'xoauth2_client_credentials'], true) ? 'oauth' : null,
        ]);

        return new WebklexImapClient($client);
    }
}
