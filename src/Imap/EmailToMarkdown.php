<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

final class EmailToMarkdown
{
    public function render(ImapMessage $m, bool $preferText = true, bool $stripQuoted = false): string
    {
        $body = $this->resolveBody($m, $preferText);
        if ($stripQuoted) {
            $body = $this->stripQuoted($body);
        }

        $lines = [];
        $lines[] = '# '.($m->subject !== '' ? $m->subject : '(no subject)');
        $lines[] = '';
        $lines[] = '| Field | Value |';
        $lines[] = '|------|--------|';
        $lines[] = '| From | '.$this->addr($m->fromName, $m->fromEmail).' |';
        $lines[] = '| To | '.$this->addrList($m->to).' |';
        if ($m->cc !== []) {
            $lines[] = '| Cc | '.$this->addrList($m->cc).' |';
        }
        $lines[] = '| Date | '.($m->date?->toDateTimeString() ?? '').' |';
        $lines[] = '| Folder | '.$m->mailbox.' |';
        $lines[] = '| Message-ID | '.$m->messageId.' |';
        if ($m->hasAttachments()) {
            $names = array_map(static fn (ImapAttachment $a): string => $a->filename, $m->attachments);
            $lines[] = '| Attachments | '.implode(', ', $names).' |';
        }
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = trim($body);

        return implode("\n", $lines)."\n";
    }

    private function resolveBody(ImapMessage $m, bool $preferText): string
    {
        if ($preferText && is_string($m->textBody) && trim($m->textBody) !== '') {
            return $m->textBody;
        }
        if (is_string($m->htmlBody) && trim($m->htmlBody) !== '') {
            return $this->htmlToMarkdown($m->htmlBody);
        }

        return (string) ($m->textBody ?? '');
    }

    private function htmlToMarkdown(string $html): string
    {
        $html = preg_replace('#<\s*br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</\s*(p|div|h[1-6]|li)\s*>#i', "\n\n", $html) ?? $html;
        $text = strip_tags($html);

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function stripQuoted(string $body): string
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '>')) {
                continue;
            }
            $out[] = $line;
        }

        return trim(implode("\n", $out));
    }

    private function addr(string $name, string $email): string
    {
        return $name !== '' ? "{$name} <{$email}>" : $email;
    }

    /** @param list<array{name:string,email:string}> $list */
    private function addrList(array $list): string
    {
        return implode(', ', array_map(fn (array $a): string => $this->addr($a['name'], $a['email']), $list));
    }
}
