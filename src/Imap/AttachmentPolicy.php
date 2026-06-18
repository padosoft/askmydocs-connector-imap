<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

final class AttachmentPolicy
{
    private bool $enabled;

    /** @var list<string> */
    private array $allowed;

    private int $maxBytes;

    private int $maxPerEmail;

    private bool $skipInline;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $this->enabled = (bool) ($config['enabled'] ?? true);
        $this->allowed = array_values(array_map('strtolower', (array) ($config['allowed_extensions'] ?? [])));
        $this->maxBytes = (int) ($config['max_size_mb'] ?? 25) * 1024 * 1024;
        $this->maxPerEmail = (int) ($config['max_per_email'] ?? 20);
        $this->skipInline = (bool) ($config['skip_inline'] ?? true);
    }

    public function accepts(ImapAttachment $a): bool
    {
        if (! $this->enabled) {
            return false;
        }
        if ($this->skipInline && $a->isInline) {
            return false;
        }
        if ($a->sizeBytes > $this->maxBytes) {
            return false;
        }
        $ext = strtolower(pathinfo($a->filename, PATHINFO_EXTENSION));

        return in_array($ext, $this->allowed, true);
    }

    public function limit(): int
    {
        return $this->maxPerEmail;
    }
}
