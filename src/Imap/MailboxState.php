<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

final class MailboxState
{
    public function __construct(
        public readonly int $uidValidity,
        public readonly int $lastUid,
    ) {}
}
