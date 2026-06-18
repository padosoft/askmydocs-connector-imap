<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

interface ImapClientFactoryInterface
{
    /** @param array<string,mixed> $connection */
    public function make(array $connection, string $secret, string $authMode): ImapClientInterface;
}
