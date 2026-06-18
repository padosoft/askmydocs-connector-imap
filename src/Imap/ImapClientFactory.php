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
            // webklex uses 'oauth' string for XOAuth2; null means basic LOGIN
            'authentication' => $authMode === 'xoauth2' ? 'oauth' : null,
        ]);

        return new WebklexImapClient($client);
    }
}
