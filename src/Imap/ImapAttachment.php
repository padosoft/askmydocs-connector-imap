<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

final class ImapAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly bool $isInline,
        public readonly string $contents,
    ) {}
}
