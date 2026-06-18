# IMAP Connector Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `padosoft/askmydocs-connector-imap`, an AskMyDocs connector that ingests email over IMAP (body + headers + attachments) as RAG knowledge.

**Architecture:** A Laravel package extending `BaseConnector` from `padosoft/askmydocs-connector-base`. It uses `webklex/php-imap` (behind a testable factory interface) to walk mailboxes, renders each email to markdown, ingests document attachments as separate KB documents, and persists an incremental UID watermark in the encrypted credential vault. Authentication is dual: basic-auth (primary) and design-ready XOAUTH2.

**Tech Stack:** PHP 8.3–8.5, Laravel 12/13, `webklex/php-imap` ^6, PHPUnit, Orchestra Testbench, Pint, PHPStan level 8.

## Global Constraints

- Package name: `padosoft/askmydocs-connector-imap`; PSR-4 root `Padosoft\AskMyDocsConnectorImap\` → `src/`.
- Connector FQCN declared in `extra.askmydocs.connectors`: `Padosoft\AskMyDocsConnectorImap\ImapConnector`.
- Laravel provider declared in `extra.laravel.providers`: `Padosoft\AskMyDocsConnectorImap\ImapServiceProvider`.
- `declare(strict_types=1);` in every PHP file (Pint `declare_strict_types`).
- PHP `^8.3`; Laravel `^12.0|^13.0`; require `padosoft/askmydocs-connector-base: ^1.1`.
- Provider config merged under `connectors.providers.imap` (NOT a top-level key).
- Connector `key()` returns `'imap'`; license Apache-2.0.
- All vault secrets via `OAuthCredentialVault`; non-secret connection params in `connector_installations.config_json`.
- Ingestion ONLY via `$this->dispatchIngestion(...)`; metadata ONLY via `SourceAwareMetadataBuilder`.
- Exceptions: `ConnectorAuthException` (no retry), `ConnectorApiException` (retryable), `ConnectorPaginationLimitException` (partial sync).
- Default knobs (verbatim): `attachments.max_size_mb=25`, `attachments.max_per_email=20`, `date_window_days=365`, `limits.max_messages_per_sync=5000`, `limits.max_message_size_mb=50`, allowed extensions `pdf,doc,docx,ppt,pptx,xls,xlsx,txt,csv,md,rtf,odt`, default excluded folders `Trash,Spam,Junk,[Gmail]/Spam,[Gmail]/Trash`.

---

## File Structure

- `composer.json` — package manifest, deps, autoload, auto-discovery.
- `config/imap.php` — provider config (xoauth2 creds, credential form URL, defaults).
- `src/ImapServiceProvider.php` — merge config, publish config + icon.
- `src/ImapConnector.php` — the 10-method contract; orchestrates sync.
- `src/Imap/ImapClientFactory.php` — `ImapClientFactoryInterface` + webklex implementation.
- `src/Imap/Contracts.php` — small value/contract types (`ImapMessage`, `ImapAttachment`, `MailboxState`).
- `src/Imap/EmailToMarkdown.php` — render a message to markdown.
- `src/Imap/AttachmentPolicy.php` — decide which attachments to ingest.
- `src/Imap/MessageFilter.php` — apply `config_json` filters to a message.
- `src/Imap/MailboxWalker.php` — folder selection + lazy UID-batch iteration + watermark math.
- `src/Support/MailMetadata.php` — build metadata via `SourceAwareMetadataBuilder`.
- `tests/TestCase.php`, `tests/fixtures/*.eml`, `tests/Support/FakeImapClient.php`, `tests/Unit/*`, `tests/Feature/*`, `tests/Live/*`.
- `pint.json`, `phpstan.neon.dist`, `.github/workflows/tests.yml`, `README.md`, `docs/{LESSON,PROGRESS}.md`.

> **Note for implementers:** the exact public API of `padosoft/askmydocs-connector-base` (e.g. `BaseConnector` constructor, `dispatchIngestion` signature, `SourceAwareMetadataBuilder::build` argument names, `OAuthCredentialVault` methods) must be read from the installed `vendor/padosoft/askmydocs-connector-base/src` after Task 1, and the calls in later tasks aligned to it. The signatures used below match the base README + the confluence/notion/google-drive connectors; verify and adjust if the base version differs.

---

### Task 1: Package scaffold + composer install

**Files:**
- Create: `composer.json`
- Create: `pint.json`
- Create: `phpstan.neon.dist`
- Create: `phpunit.xml`
- Create: `.gitignore`

**Interfaces:**
- Consumes: nothing.
- Produces: an installable package with `vendor/` present, `Padosoft\AskMyDocsConnectorImap\` autoloaded, and `webklex/php-imap` + base available.

- [ ] **Step 1: Write `composer.json`**

```json
{
    "name": "padosoft/askmydocs-connector-imap",
    "description": "IMAP email connector for AskMyDocs — ingest mailboxes (body, headers, attachments) as RAG knowledge.",
    "type": "library",
    "license": "Apache-2.0",
    "require": {
        "php": "^8.3",
        "illuminate/support": "^12.0|^13.0",
        "illuminate/http": "^12.0|^13.0",
        "illuminate/contracts": "^12.0|^13.0",
        "nesbot/carbon": "^2.0|^3.0",
        "webklex/php-imap": "^6.0",
        "padosoft/askmydocs-connector-base": "^1.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0|^12.0",
        "orchestra/testbench": "^10.0|^11.0",
        "mockery/mockery": "^1.6",
        "phpstan/phpstan": "^2.0",
        "laravel/pint": "^1.18"
    },
    "autoload": {
        "psr-4": { "Padosoft\\AskMyDocsConnectorImap\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Padosoft\\AskMyDocsConnectorImap\\Tests\\": "tests/" }
    },
    "extra": {
        "laravel": {
            "providers": ["Padosoft\\AskMyDocsConnectorImap\\ImapServiceProvider"]
        },
        "askmydocs": {
            "connectors": ["Padosoft\\AskMyDocsConnectorImap\\ImapConnector"]
        }
    },
    "scripts": {
        "test": "vendor/bin/phpunit",
        "format": "vendor/bin/pint",
        "analyse": "vendor/bin/phpstan analyse"
    },
    "minimum-stability": "dev",
    "prefer-stable": true,
    "config": { "sort-packages": true }
}
```

- [ ] **Step 2: Write `pint.json`**

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true
    }
}
```

- [ ] **Step 3: Write `phpstan.neon.dist`**

```neon
parameters:
    level: 8
    paths:
        - src
    excludePaths:
        - tests/Live/*
```

- [ ] **Step 4: Write `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
        <testsuite name="Live">
            <directory>tests/Live</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 5: Write `.gitignore`**

```
/vendor
/composer.lock
.phpunit.result.cache
/coverage
```

- [ ] **Step 6: Install dependencies**

Run: `composer install`
Expected: `vendor/` created; no errors. If `padosoft/askmydocs-connector-base` is not on Packagist yet, add a `path` or `vcs` repository entry pointing at the sibling `../askmydocs-connector-base` and re-run. Confirm with:
Run: `ls vendor/webklex/php-imap && ls vendor/padosoft/askmydocs-connector-base`
Expected: both directories listed.

- [ ] **Step 7: Read the base package API**

Run: `ls vendor/padosoft/askmydocs-connector-base/src vendor/padosoft/askmydocs-connector-base/src/Auth vendor/padosoft/askmydocs-connector-base/src/Support`
Read `BaseConnector.php`, `ConnectorInterface.php`, `SyncResult.php`, `HealthStatus.php`, `Auth/OAuthCredentialVault.php`, and the `SourceAwareMetadataBuilder`. Note the exact constructor + method signatures; later tasks must match them.

- [ ] **Step 8: Commit**

```bash
git add composer.json pint.json phpstan.neon.dist phpunit.xml .gitignore
git commit -m "chore: scaffold imap connector package"
```

---

### Task 2: Test harness (TestCase + fixtures + fake client)

**Files:**
- Create: `tests/TestCase.php`
- Create: `src/Imap/Contracts.php`
- Create: `tests/Support/FakeImapClient.php`
- Create: `tests/fixtures/plain.eml`
- Create: `tests/fixtures/html-with-pdf.eml`

**Interfaces:**
- Produces:
  - `Padosoft\AskMyDocsConnectorImap\Imap\ImapAttachment` — readonly: `string $filename, string $mimeType, int $sizeBytes, bool $isInline, string $contents`.
  - `Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage` — readonly: `int $uid, int $uidValidity, string $mailbox, string $messageId, ?string $inReplyTo, array $references, string $fromName, string $fromEmail, array $to, array $cc, ?\Carbon\Carbon $date, string $subject, array $flags, array $labels, ?string $textBody, ?string $htmlBody, array $rawHeaders, array $attachments`.
  - `Padosoft\AskMyDocsConnectorImap\Imap\MailboxState` — readonly: `int $uidValidity, int $lastUid`.
  - `Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface` with: `listMailboxes(): array`, `selectMailbox(string $name): MailboxState`, `searchUids(string $mailbox, ?\Carbon\Carbon $since, ?int $sinceUid): array`, `fetchMessage(string $mailbox, int $uid): ImapMessage`, `ping(): bool`, `close(): void`.
  - `Padosoft\AskMyDocsConnectorImap\Tests\Support\FakeImapClient implements ImapClientInterface` (test double seeded with `ImapMessage[]`).

- [ ] **Step 1: Write `src/Imap/Contracts.php`** (value objects + client interface)

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

use Carbon\Carbon;

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

final class ImapMessage
{
    /**
     * @param  list<string>  $references
     * @param  list<array{name:string,email:string}>  $to
     * @param  list<array{name:string,email:string}>  $cc
     * @param  list<string>  $flags
     * @param  list<string>  $labels
     * @param  array<string,string>  $rawHeaders
     * @param  list<ImapAttachment>  $attachments
     */
    public function __construct(
        public readonly int $uid,
        public readonly int $uidValidity,
        public readonly string $mailbox,
        public readonly string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly string $fromName,
        public readonly string $fromEmail,
        public readonly array $to,
        public readonly array $cc,
        public readonly ?Carbon $date,
        public readonly string $subject,
        public readonly array $flags,
        public readonly array $labels,
        public readonly ?string $textBody,
        public readonly ?string $htmlBody,
        public readonly array $rawHeaders,
        public readonly array $attachments,
    ) {}

    public function hasAttachments(): bool
    {
        return $this->attachments !== [];
    }
}

final class MailboxState
{
    public function __construct(
        public readonly int $uidValidity,
        public readonly int $lastUid,
    ) {}
}

interface ImapClientInterface
{
    /** @return list<string> */
    public function listMailboxes(): array;

    public function selectMailbox(string $name): MailboxState;

    /** @return list<int> ascending UIDs matching the window */
    public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array;

    public function fetchMessage(string $mailbox, int $uid): ImapMessage;

    public function ping(): bool;

    public function close(): void;
}
```

- [ ] **Step 2: Write `tests/Support/FakeImapClient.php`**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Support;

use Carbon\Carbon;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxState;

final class FakeImapClient implements ImapClientInterface
{
    /** @param array<string, list<ImapMessage>> $byMailbox */
    public function __construct(
        private array $byMailbox = [],
        private int $uidValidity = 1,
        public bool $closed = false,
    ) {}

    public function listMailboxes(): array
    {
        return array_keys($this->byMailbox);
    }

    public function selectMailbox(string $name): MailboxState
    {
        $messages = $this->byMailbox[$name] ?? [];
        $lastUid = 0;
        foreach ($messages as $m) {
            $lastUid = max($lastUid, $m->uid);
        }

        return new MailboxState($this->uidValidity, $lastUid);
    }

    public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
    {
        $uids = [];
        foreach ($this->byMailbox[$mailbox] ?? [] as $m) {
            if ($sinceUid !== null && $m->uid <= $sinceUid) {
                continue;
            }
            if ($since !== null && $m->date !== null && $m->date->lessThan($since)) {
                continue;
            }
            $uids[] = $m->uid;
        }
        sort($uids);

        return $uids;
    }

    public function fetchMessage(string $mailbox, int $uid): ImapMessage
    {
        foreach ($this->byMailbox[$mailbox] ?? [] as $m) {
            if ($m->uid === $uid) {
                return $m;
            }
        }
        throw new \RuntimeException("No fake message uid={$uid} in {$mailbox}");
    }

    public function ping(): bool
    {
        return true;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
```

- [ ] **Step 3: Write `tests/TestCase.php`**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\AskMyDocsConnectorBase\ConnectorServiceProvider;
use Padosoft\AskMyDocsConnectorImap\ImapServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ConnectorServiceProvider::class,
            ImapServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
```

> If `ConnectorServiceProvider` exposes its own base FQCN that differs, fix the import per the Task 1 Step 7 reading.

- [ ] **Step 4: Write the two fixture .eml files**

`tests/fixtures/plain.eml`:

```
From: Mario Rossi <mario@acme.com>
To: support@us.com
Subject: Richiesta assistenza ordine 12345
Date: Mon, 15 Jun 2026 14:30:00 +0200
Message-ID: <plain-1@acme.com>
Content-Type: text/plain; charset=UTF-8

Buongiorno, ho un problema con l'ordine 12345.
Grazie, Mario
```

`tests/fixtures/html-with-pdf.eml`:

```
From: Anna Bianchi <anna@acme.com>
To: support@us.com
Cc: boss@us.com
Subject: Fattura allegata
Date: Tue, 16 Jun 2026 09:00:00 +0200
Message-ID: <html-1@acme.com>
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="b1"

--b1
Content-Type: text/html; charset=UTF-8

<p>In allegato la <b>fattura</b>.</p>
--b1
Content-Type: application/pdf; name="fattura.pdf"
Content-Disposition: attachment; filename="fattura.pdf"
Content-Transfer-Encoding: base64

JVBERi0xLjQKJ-Rndo==
--b1--
```

- [ ] **Step 5: Sanity test that the harness boots**

Create `tests/Unit/HarnessTest.php`:

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Tests\Support\FakeImapClient;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class HarnessTest extends TestCase
{
    public function test_fake_client_lists_and_searches(): void
    {
        $msg = new ImapMessage(
            uid: 10, uidValidity: 1, mailbox: 'INBOX', messageId: '<a@x>',
            inReplyTo: null, references: [], fromName: 'A', fromEmail: 'a@x',
            to: [], cc: [], date: null, subject: 'S', flags: [], labels: [],
            textBody: 'hi', htmlBody: null, rawHeaders: [], attachments: [],
        );
        $client = new FakeImapClient(['INBOX' => [$msg]]);

        $this->assertSame(['INBOX'], $client->listMailboxes());
        $this->assertSame([10], $client->searchUids('INBOX', null, 5));
        $this->assertSame([], $client->searchUids('INBOX', null, 10));
    }
}
```

- [ ] **Step 6: Run it**

Run: `vendor/bin/phpunit --testsuite=Unit`
Expected: PASS (1 test). If `ConnectorServiceProvider` import fails, correct it from Task 1 Step 7 and re-run.

- [ ] **Step 7: Commit**

```bash
git add src/Imap/Contracts.php tests/
git commit -m "test: add imap test harness, value objects and fake client"
```

---

### Task 3: EmailToMarkdown

**Files:**
- Create: `src/Imap/EmailToMarkdown.php`
- Test: `tests/Unit/EmailToMarkdownTest.php`

**Interfaces:**
- Consumes: `ImapMessage`.
- Produces: `EmailToMarkdown::render(ImapMessage $m, bool $preferText = true, bool $stripQuoted = false): string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Padosoft\AskMyDocsConnectorImap\Imap\EmailToMarkdown;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapAttachment;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class EmailToMarkdownTest extends TestCase
{
    private function message(?string $text, ?string $html, array $attachments = []): ImapMessage
    {
        return new ImapMessage(
            uid: 1, uidValidity: 1, mailbox: 'INBOX', messageId: '<m@x>',
            inReplyTo: null, references: [], fromName: 'Mario Rossi', fromEmail: 'mario@acme.com',
            to: [['name' => '', 'email' => 'support@us.com']], cc: [], date: null,
            subject: 'Richiesta', flags: [], labels: [], textBody: $text, htmlBody: $html,
            rawHeaders: [], attachments: $attachments,
        );
    }

    public function test_renders_header_block_and_text_body(): void
    {
        $md = (new EmailToMarkdown)->render($this->message('Ciao mondo', null));

        $this->assertStringContainsString('# Richiesta', $md);
        $this->assertStringContainsString('Mario Rossi <mario@acme.com>', $md);
        $this->assertStringContainsString('support@us.com', $md);
        $this->assertStringContainsString('Ciao mondo', $md);
    }

    public function test_falls_back_to_html_converted_to_markdown(): void
    {
        $md = (new EmailToMarkdown)->render($this->message(null, '<p>In <b>allegato</b></p>'));

        $this->assertStringContainsString('allegato', $md);
        $this->assertStringNotContainsString('<p>', $md);
    }

    public function test_lists_attachment_filenames(): void
    {
        $att = new ImapAttachment('fattura.pdf', 'application/pdf', 10, false, 'x');
        $md = (new EmailToMarkdown)->render($this->message('hi', null, [$att]));

        $this->assertStringContainsString('fattura.pdf', $md);
    }

    public function test_strip_quoted_history_removes_reply_chain(): void
    {
        $body = "Risposta nuova\n> vecchia citazione\n> altra riga";
        $md = (new EmailToMarkdown)->render($this->message($body, null), true, true);

        $this->assertStringContainsString('Risposta nuova', $md);
        $this->assertStringNotContainsString('vecchia citazione', $md);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/EmailToMarkdownTest.php`
Expected: FAIL ("Class EmailToMarkdown not found").

- [ ] **Step 3: Write minimal implementation**

```php
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

    /** @param array{name:string,email:string} ... */
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/EmailToMarkdownTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Imap/EmailToMarkdown.php tests/Unit/EmailToMarkdownTest.php
git commit -m "feat: render email to markdown"
```

---

### Task 4: AttachmentPolicy

**Files:**
- Create: `src/Imap/AttachmentPolicy.php`
- Test: `tests/Unit/AttachmentPolicyTest.php`

**Interfaces:**
- Consumes: `ImapAttachment`.
- Produces: `AttachmentPolicy::__construct(array $config)` where `$config` is the resolved `attachments` block; `accepts(ImapAttachment $a): bool`; `limit(): int` (max_per_email).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Padosoft\AskMyDocsConnectorImap\Imap\AttachmentPolicy;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapAttachment;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class AttachmentPolicyTest extends TestCase
{
    private function policy(array $over = []): AttachmentPolicy
    {
        return new AttachmentPolicy(array_merge([
            'enabled' => true,
            'allowed_extensions' => ['pdf', 'docx'],
            'max_size_mb' => 1,
            'max_per_email' => 5,
            'skip_inline' => true,
        ], $over));
    }

    private function att(string $name, int $size, bool $inline = false): ImapAttachment
    {
        return new ImapAttachment($name, 'application/octet-stream', $size, $inline, 'x');
    }

    public function test_accepts_allowed_extension_within_size(): void
    {
        $this->assertTrue($this->policy()->accepts($this->att('a.pdf', 500)));
    }

    public function test_rejects_disallowed_extension(): void
    {
        $this->assertFalse($this->policy()->accepts($this->att('a.zip', 10)));
    }

    public function test_rejects_oversize(): void
    {
        $this->assertFalse($this->policy()->accepts($this->att('a.pdf', 2 * 1024 * 1024)));
    }

    public function test_rejects_inline_when_skip_inline(): void
    {
        $this->assertFalse($this->policy()->accepts($this->att('a.pdf', 10, true)));
    }

    public function test_disabled_rejects_everything(): void
    {
        $this->assertFalse($this->policy(['enabled' => false])->accepts($this->att('a.pdf', 10)));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/AttachmentPolicyTest.php`
Expected: FAIL ("Class AttachmentPolicy not found").

- [ ] **Step 3: Write minimal implementation**

```php
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
        $this->allowed = array_map('strtolower', (array) ($config['allowed_extensions'] ?? []));
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/AttachmentPolicyTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Imap/AttachmentPolicy.php tests/Unit/AttachmentPolicyTest.php
git commit -m "feat: attachment allowlist + size cap policy"
```

---

### Task 5: MessageFilter

**Files:**
- Create: `src/Imap/MessageFilter.php`
- Test: `tests/Unit/MessageFilterTest.php`

**Interfaces:**
- Consumes: `ImapMessage`.
- Produces: `MessageFilter::__construct(array $config)` (resolved `config_json`); `passes(ImapMessage $m): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MessageFilter;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class MessageFilterTest extends TestCase
{
    private function msg(array $over = []): ImapMessage
    {
        return new ImapMessage(
            uid: $over['uid'] ?? 1, uidValidity: 1, mailbox: 'INBOX',
            messageId: '<m@x>', inReplyTo: null, references: [],
            fromName: '', fromEmail: $over['from'] ?? 'mario@acme.com',
            to: [], cc: [], date: null, subject: $over['subject'] ?? 'Ciao',
            flags: $over['flags'] ?? [], labels: [], textBody: 'b', htmlBody: null,
            rawHeaders: $over['headers'] ?? [], attachments: [],
        );
    }

    public function test_sender_exclude_by_domain(): void
    {
        $f = new MessageFilter(['senders' => ['exclude' => ['acme.com']]]);
        $this->assertFalse($f->passes($this->msg(['from' => 'x@acme.com'])));
        $this->assertTrue($f->passes($this->msg(['from' => 'x@other.com'])));
    }

    public function test_sender_include_allowlist(): void
    {
        $f = new MessageFilter(['senders' => ['include' => ['vip@acme.com']]]);
        $this->assertTrue($f->passes($this->msg(['from' => 'vip@acme.com'])));
        $this->assertFalse($f->passes($this->msg(['from' => 'other@acme.com'])));
    }

    public function test_subject_exclude_keyword(): void
    {
        $f = new MessageFilter(['subject' => ['exclude_keywords' => ['newsletter']]]);
        $this->assertFalse($f->passes($this->msg(['subject' => 'Our NEWSLETTER'])));
    }

    public function test_only_flagged(): void
    {
        $f = new MessageFilter(['only_flagged' => true]);
        $this->assertFalse($f->passes($this->msg(['flags' => []])));
        $this->assertTrue($f->passes($this->msg(['flags' => ['\\Flagged']])));
    }

    public function test_skip_auto_generated(): void
    {
        $f = new MessageFilter(['skip_auto_generated' => true]);
        $this->assertFalse($f->passes($this->msg(['headers' => ['precedence' => 'bulk']])));
        $this->assertFalse($f->passes($this->msg(['headers' => ['auto-submitted' => 'auto-replied']])));
        $this->assertTrue($f->passes($this->msg(['headers' => []])));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/MessageFilterTest.php`
Expected: FAIL ("Class MessageFilter not found").

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

final class MessageFilter
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config) {}

    public function passes(ImapMessage $m): bool
    {
        if (($this->config['only_flagged'] ?? false) === true && ! in_array('\\Flagged', $m->flags, true)) {
            return false;
        }
        if (($this->config['only_unseen'] ?? false) === true && in_array('\\Seen', $m->flags, true)) {
            return false;
        }
        if (($this->config['skip_auto_generated'] ?? true) === true && $this->isAutoGenerated($m)) {
            return false;
        }
        if (! $this->senderPasses($m->fromEmail)) {
            return false;
        }
        if (! $this->subjectPasses($m->subject)) {
            return false;
        }

        return true;
    }

    private function senderPasses(string $from): bool
    {
        $from = strtolower($from);
        $include = array_map('strtolower', (array) ($this->config['senders']['include'] ?? []));
        $exclude = array_map('strtolower', (array) ($this->config['senders']['exclude'] ?? []));

        foreach ($exclude as $needle) {
            if ($this->addrMatches($from, $needle)) {
                return false;
            }
        }
        if ($include !== []) {
            foreach ($include as $needle) {
                if ($this->addrMatches($from, $needle)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private function addrMatches(string $address, string $needle): bool
    {
        // needle is a full address or a bare domain
        return $address === $needle || str_ends_with($address, '@'.$needle);
    }

    private function subjectPasses(string $subject): bool
    {
        $subject = mb_strtolower($subject);
        $include = (array) ($this->config['subject']['include_keywords'] ?? []);
        $exclude = (array) ($this->config['subject']['exclude_keywords'] ?? []);

        foreach ($exclude as $kw) {
            if ($kw !== '' && str_contains($subject, mb_strtolower((string) $kw))) {
                return false;
            }
        }
        if ($include !== []) {
            foreach ($include as $kw) {
                if ($kw !== '' && str_contains($subject, mb_strtolower((string) $kw))) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private function isAutoGenerated(ImapMessage $m): bool
    {
        $headers = array_change_key_case($m->rawHeaders, CASE_LOWER);
        $precedence = strtolower((string) ($headers['precedence'] ?? ''));
        if (in_array($precedence, ['bulk', 'list', 'junk'], true)) {
            return true;
        }
        if (($headers['auto-submitted'] ?? 'no') !== 'no' && isset($headers['auto-submitted'])) {
            return strtolower((string) $headers['auto-submitted']) !== 'no';
        }
        if (isset($headers['list-unsubscribe'])) {
            return true;
        }

        return false;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/MessageFilterTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Imap/MessageFilter.php tests/Unit/MessageFilterTest.php
git commit -m "feat: per-installation message filters"
```

---

### Task 6: MailboxWalker (folder selection + watermark math)

**Files:**
- Create: `src/Imap/MailboxWalker.php`
- Test: `tests/Unit/MailboxWalkerTest.php`

**Interfaces:**
- Consumes: `ImapClientInterface`, `MailboxState`, `config_json`.
- Produces:
  - `MailboxWalker::__construct(ImapClientInterface $client, array $config)`.
  - `selectedMailboxes(): list<string>` — apply folders include/exclude to `client->listMailboxes()`.
  - `windowSince(): ?Carbon` — now minus `date_window_days` (null if 0).
  - `incrementalUids(string $mailbox, array $state, ?Carbon $since): array{uids:list<int>, uidValidity:int}` — given prior `$state` (`['uidvalidity'=>int,'last_uid'=>int]` or `[]`), returns UIDs to fetch and the live uidValidity. If uidValidity changed/unknown, ignore `last_uid` (full rescan within window).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxWalker;
use Padosoft\AskMyDocsConnectorImap\Tests\Support\FakeImapClient;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class MailboxWalkerTest extends TestCase
{
    private function client(): FakeImapClient
    {
        $mk = fn (int $uid) => new ImapMessage(
            uid: $uid, uidValidity: 1, mailbox: 'INBOX', messageId: "<$uid@x>",
            inReplyTo: null, references: [], fromName: '', fromEmail: 'a@x',
            to: [], cc: [], date: null, subject: 'S', flags: [], labels: [],
            textBody: 'b', htmlBody: null, rawHeaders: [], attachments: [],
        );

        return new FakeImapClient([
            'INBOX' => [$mk(1), $mk(2), $mk(3)],
            'Trash' => [$mk(9)],
        ], uidValidity: 1);
    }

    public function test_excludes_configured_folders(): void
    {
        $w = new MailboxWalker($this->client(), ['folders' => ['exclude' => ['Trash']]]);
        $this->assertSame(['INBOX'], $w->selectedMailboxes());
    }

    public function test_include_list_wins(): void
    {
        $w = new MailboxWalker($this->client(), ['folders' => ['include' => ['Trash']]]);
        $this->assertSame(['Trash'], $w->selectedMailboxes());
    }

    public function test_incremental_uses_last_uid_when_uidvalidity_matches(): void
    {
        $w = new MailboxWalker($this->client(), []);
        $r = $w->incrementalUids('INBOX', ['uidvalidity' => 1, 'last_uid' => 1], null);
        $this->assertSame([2, 3], $r['uids']);
        $this->assertSame(1, $r['uidValidity']);
    }

    public function test_incremental_rescans_when_uidvalidity_changed(): void
    {
        $w = new MailboxWalker($this->client(), []);
        $r = $w->incrementalUids('INBOX', ['uidvalidity' => 999, 'last_uid' => 2], null);
        $this->assertSame([1, 2, 3], $r['uids']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/MailboxWalkerTest.php`
Expected: FAIL ("Class MailboxWalker not found").

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

use Carbon\Carbon;

final class MailboxWalker
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private ImapClientInterface $client,
        private array $config,
    ) {}

    /** @return list<string> */
    public function selectedMailboxes(): array
    {
        $all = $this->client->listMailboxes();
        $include = (array) ($this->config['folders']['include'] ?? []);
        $exclude = (array) ($this->config['folders']['exclude'] ?? []);

        if ($include !== []) {
            return array_values(array_filter($all, static fn ($m) => in_array($m, $include, true)));
        }

        return array_values(array_filter($all, static fn ($m) => ! in_array($m, $exclude, true)));
    }

    public function windowSince(): ?Carbon
    {
        $days = (int) ($this->config['date_window_days'] ?? 365);

        return $days > 0 ? Carbon::now()->subDays($days) : null;
    }

    /**
     * @param  array{uidvalidity?:int,last_uid?:int}  $state
     * @return array{uids:list<int>, uidValidity:int}
     */
    public function incrementalUids(string $mailbox, array $state, ?Carbon $since): array
    {
        $live = $this->client->selectMailbox($mailbox);
        $sameValidity = isset($state['uidvalidity']) && (int) $state['uidvalidity'] === $live->uidValidity;
        $sinceUid = $sameValidity ? (int) ($state['last_uid'] ?? 0) : null;

        $uids = $this->client->searchUids($mailbox, $since, $sinceUid);

        return ['uids' => $uids, 'uidValidity' => $live->uidValidity];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/MailboxWalkerTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Imap/MailboxWalker.php tests/Unit/MailboxWalkerTest.php
git commit -m "feat: mailbox selection and incremental UID watermark math"
```

---

### Task 7: MailMetadata builder

**Files:**
- Create: `src/Support/MailMetadata.php`
- Test: `tests/Unit/MailMetadataTest.php`

**Interfaces:**
- Consumes: `ImapMessage`, the base `SourceAwareMetadataBuilder`.
- Produces: `MailMetadata::build(int $installationId, ImapMessage $m): array` (the metadata array passed to `dispatchIngestion`).

> **Read first:** open `vendor/padosoft/askmydocs-connector-base/src/Support/Metadata/SourceAwareMetadataBuilder.php` and match the exact `build(...)` argument names. The test below asserts only on fields this connector controls, so it is robust to the builder's internal shape — but the call must compile.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Carbon\Carbon;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Support\MailMetadata;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class MailMetadataTest extends TestCase
{
    public function test_build_includes_imap_source_fields(): void
    {
        $m = new ImapMessage(
            uid: 42, uidValidity: 7, mailbox: 'INBOX', messageId: '<id@x>',
            inReplyTo: null, references: ['<r1@x>'], fromName: 'Mario', fromEmail: 'mario@acme.com',
            to: [['name' => '', 'email' => 'support@us.com']], cc: [], date: Carbon::parse('2026-06-15T12:00:00Z'),
            subject: 'Ciao', flags: ['\\Seen'], labels: ['support'],
            textBody: 'b', htmlBody: null, rawHeaders: [], attachments: [],
        );

        $meta = (new MailMetadata)->build(99, $m);
        $json = json_encode($meta);

        $this->assertStringContainsString('mario@acme.com', $json);
        $this->assertStringContainsString('INBOX', $json);
        $this->assertStringContainsString('42', $json);
        $this->assertStringContainsString('support', $json);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/MailMetadataTest.php`
Expected: FAIL ("Class MailMetadata not found").

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Support;

use Padosoft\AskMyDocsConnectorBase\Support\Metadata\SourceAwareMetadataBuilder;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;

final class MailMetadata
{
    /** @return array<string,mixed> */
    public function build(int $installationId, ImapMessage $m): array
    {
        $sourceFields = [
            'mailbox' => $m->mailbox,
            'uid' => $m->uid,
            'uidvalidity' => $m->uidValidity,
            'message_id' => $m->messageId,
            'in_reply_to' => $m->inReplyTo,
            'references' => $m->references,
            'from_name' => $m->fromName,
            'from_email' => $m->fromEmail,
            'to' => $m->to,
            'cc' => $m->cc,
            'date' => $m->date?->toIso8601String(),
            'subject' => $m->subject,
            'flags' => $m->flags,
            'labels' => $m->labels,
            'has_attachments' => $m->hasAttachments(),
            'attachment_count' => count($m->attachments),
        ];

        return (new SourceAwareMetadataBuilder)->build(
            base: [
                'connector' => 'imap',
                'installation_id' => $installationId,
                'imap_uid' => (string) $m->uid,
                'imap_mailbox' => $m->mailbox,
                'imap_message_id' => $m->messageId,
            ],
            sourceKey: 'imap',
            sourceFields: $sourceFields,
            tags: array_values(array_unique(array_merge($m->labels, [$m->mailbox]))),
            statusActive: true,
            lastModified: $m->date?->toIso8601String(),
            owner: $m->fromEmail,
        );
    }
}
```

> If the base builder's argument names differ (read in Step "Read first"), adjust the named arguments here and in nothing else.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/MailMetadataTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add src/Support/MailMetadata.php tests/Unit/MailMetadataTest.php
git commit -m "feat: build source-aware metadata for emails"
```

---

### Task 8: Config + ServiceProvider

**Files:**
- Create: `config/imap.php`
- Create: `src/ImapServiceProvider.php`
- Create: `public/icons/imap.svg`
- Test: `tests/Feature/ServiceProviderTest.php`

**Interfaces:**
- Produces: config readable at `connectors.providers.imap.*`; `ImapServiceProvider` registered.

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ServiceProviderTest.php`
Expected: FAIL (config null).

- [ ] **Step 3: Write `config/imap.php`**

```php
<?php

declare(strict_types=1);

return [
    'xoauth2' => [
        'google' => [
            'client_id' => env('CONNECTOR_IMAP_GOOGLE_CLIENT_ID'),
            'client_secret' => env('CONNECTOR_IMAP_GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => env('CONNECTOR_IMAP_GOOGLE_REDIRECT_URI'),
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'imap_host' => 'imap.gmail.com',
        ],
        'microsoft' => [
            'client_id' => env('CONNECTOR_IMAP_MS_CLIENT_ID'),
            'client_secret' => env('CONNECTOR_IMAP_MS_CLIENT_SECRET'),
            'redirect_uri' => env('CONNECTOR_IMAP_MS_REDIRECT_URI'),
            'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'imap_host' => 'outlook.office365.com',
        ],
    ],
    'credential_form_url' => env(
        'CONNECTOR_IMAP_CREDENTIAL_FORM_URL',
        env('APP_URL', 'http://localhost').'/admin/connectors/imap/credentials'
    ),
    'defaults' => [
        'date_window_days' => 365,
        'folders_exclude' => ['Trash', 'Spam', 'Junk', '[Gmail]/Spam', '[Gmail]/Trash'],
        'skip_auto_generated' => true,
        'body_format' => 'prefer_text',
        'attachments' => [
            'enabled' => true,
            'allowed_extensions' => ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'csv', 'md', 'rtf', 'odt'],
            'max_size_mb' => 25,
            'max_per_email' => 20,
            'skip_inline' => true,
        ],
        'limits' => [
            'max_messages_per_sync' => 5000,
            'max_message_size_mb' => 50,
        ],
    ],
];
```

- [ ] **Step 4: Write `src/ImapServiceProvider.php`**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap;

use Illuminate\Support\ServiceProvider;

final class ImapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/imap.php', 'connectors.providers.imap');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/imap.php' => config_path('connectors-imap.php'),
            ], 'connector-imap-config');

            $this->publishes([
                __DIR__.'/../public/icons' => public_path('connectors'),
            ], 'connector-imap-assets');
        }
    }
}
```

- [ ] **Step 5: Write a minimal `public/icons/imap.svg`**

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M2 4h20v16H2zm2 2v.5l8 5 8-5V6H4zm16 3.2-8 5-8-5V18h16z"/></svg>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ServiceProviderTest.php`
Expected: PASS (1 test).

- [ ] **Step 7: Commit**

```bash
git add config/imap.php src/ImapServiceProvider.php public/icons/imap.svg tests/Feature/ServiceProviderTest.php
git commit -m "feat: provider config and service provider"
```

---

### Task 9: ImapConnector — identity + auth (basic) + health + disconnect

**Files:**
- Create: `src/ImapConnector.php`
- Test: `tests/Feature/ImapConnectorAuthTest.php`

**Interfaces:**
- Consumes: `BaseConnector` (constructor + helpers per Task 1 reading), `OAuthCredentialVault`, `ImapClientFactoryInterface` (Task 10 provides the real one; here inject via a protected `makeClient()` seam so tests can override).
- Produces: `ImapConnector` implementing `key()`, `displayName()`, `iconUrl()`, `oauthScopes()`, `initiateOAuth()`, `handleOAuthCallback()`, `disconnect()`, `health()`. Sync methods are stubbed to `SyncResult::empty()`-equivalent until Task 11.
- Produces seam: `protected function makeClient(int $installationId): ImapClientInterface`.

> **Read first:** confirm `BaseConnector`'s constructor params and the helper names (`issueOAuthState`, `consumeOAuthState`, `loadInstallation`, `dispatchIngestion`, `maybeRedactContent`, `emitAudit`, `softDeleteByMetadataKey`) and `OAuthCredentialVault::setCredentials/getAccessToken/getExtra/setExtraKey/clearCredentials` from Task 1 Step 7. Adjust calls if names differ.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Feature;

use Illuminate\Http\Request;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\ImapConnector;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class ImapConnectorAuthTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private function installation(): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'config_json' => ['auth_mode' => 'basic', 'connection' => ['host' => 'imap.test', 'port' => 993, 'encryption' => 'ssl', 'username' => 'support@us.com']],
            'status' => 'pending',
        ]);
    }

    public function test_key_and_display_name(): void
    {
        $c = $this->app->make(ImapConnector::class);
        $this->assertSame('imap', $c->key());
        $this->assertSame('Email (IMAP)', $c->displayName());
    }

    public function test_basic_auth_callback_stores_password_in_vault(): void
    {
        $c = $this->app->make(ImapConnector::class);
        $inst = $this->installation();

        $url = $c->initiateOAuth($inst->id);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $req = Request::create('/cb', 'POST', [
            'state' => (string) ($q['state'] ?? ''),
            'password' => 's3cret',
        ]);
        $c->handleOAuthCallback($inst->id, $req);

        $vault = $this->app->make(\Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault::class);
        $this->assertSame('s3cret', $vault->getAccessToken($inst->id));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ImapConnectorAuthTest.php`
Expected: FAIL ("Class ImapConnector not found").

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Padosoft\AskMyDocsConnectorBase\BaseConnector;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\SyncResult;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;

class ImapConnector extends BaseConnector
{
    public function key(): string
    {
        return 'imap';
    }

    public function displayName(): string
    {
        return 'Email (IMAP)';
    }

    public function iconUrl(): string
    {
        return asset('connectors/imap.svg');
    }

    public function oauthScopes(): array
    {
        // Used only in xoauth2 mode; provider-specific scopes resolved at runtime.
        return [];
    }

    public function initiateOAuth(int $installationId): string
    {
        $state = $this->issueOAuthState($installationId);
        $mode = $this->authMode($installationId);

        if ($mode === 'xoauth2') {
            return $this->xoauthAuthorizeUrl($installationId, $state);
        }

        $form = (string) config('connectors.providers.imap.credential_form_url');

        return $form.'?installation='.$installationId.'&state='.$state;
    }

    public function handleOAuthCallback(int $installationId, Request $request): void
    {
        $state = (string) $request->input('state', '');
        if (! $this->consumeOAuthState($installationId, $state)) {
            throw new ConnectorAuthException('IMAP credential callback: invalid or expired state.');
        }

        if ($this->authMode($installationId) === 'xoauth2') {
            $this->handleXoauthCallback($installationId, $request);

            return;
        }

        $password = (string) $request->input('password', '');
        if ($password === '') {
            throw new ConnectorAuthException('IMAP credential callback: missing password.');
        }

        // Verify the credentials actually log in before persisting.
        $client = $this->makeClientWithPassword($installationId, $password);
        if (! $client->ping()) {
            $client->close();
            throw new ConnectorAuthException('IMAP login failed with provided credentials.');
        }
        $client->close();

        $this->vault->setCredentials(
            $installationId,
            accessToken: $password,
            refreshToken: null,
            expiresAt: null,
            extra: ['auth_mode' => 'basic'],
        );

        $this->emitAudit('installed', installationId: $installationId, metadata: ['auth_mode' => 'basic']);
    }

    public function disconnect(int $installationId): void
    {
        $this->vault->clearCredentials($installationId);
    }

    public function health(int $installationId): HealthStatus
    {
        try {
            $client = $this->makeClient($installationId);
            $ok = $client->ping();
            $client->close();

            return $ok ? HealthStatus::healthy() : HealthStatus::errored('IMAP ping failed');
        } catch (\Throwable $e) {
            return HealthStatus::errored($e->getMessage());
        }
    }

    public function syncFull(int $installationId): SyncResult
    {
        // Implemented in Task 11.
        return new SyncResult(0, 0, 0, [], Carbon::now());
    }

    public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
    {
        // Implemented in Task 11.
        return new SyncResult(0, 0, 0, [], Carbon::now());
    }

    protected function authMode(int $installationId): string
    {
        $config = (array) ($this->loadInstallation($installationId)->config_json ?? []);

        return (string) ($config['auth_mode'] ?? 'basic');
    }

    protected function makeClient(int $installationId): ImapClientInterface
    {
        $password = (string) ($this->vault->getAccessToken($installationId) ?? '');

        return $this->makeClientWithPassword($installationId, $password);
    }

    protected function makeClientWithPassword(int $installationId, string $secret): ImapClientInterface
    {
        $config = (array) ($this->loadInstallation($installationId)->config_json ?? []);
        $connection = (array) ($config['connection'] ?? []);

        /** @var ImapClientFactoryInterface $factory */
        $factory = app(ImapClientFactoryInterface::class);

        return $factory->make($connection, $secret, (string) ($config['auth_mode'] ?? 'basic'));
    }

    private function xoauthAuthorizeUrl(int $installationId, string $state): string
    {
        $config = (array) ($this->loadInstallation($installationId)->config_json ?? []);
        $provider = (string) ($config['xoauth2_provider'] ?? 'google');
        $p = (array) config('connectors.providers.imap.xoauth2.'.$provider, []);

        return ((string) ($p['authorize_url'] ?? '')).'?'.http_build_query([
            'client_id' => $p['client_id'] ?? '',
            'redirect_uri' => $p['redirect_uri'] ?? '',
            'response_type' => 'code',
            'scope' => $provider === 'google' ? 'https://mail.google.com/' : 'https://outlook.office.com/IMAP.AccessAsUser.All offline_access',
            'access_type' => 'offline',
            'state' => $state,
        ]);
    }

    private function handleXoauthCallback(int $installationId, Request $request): void
    {
        // Design-ready: exchange code -> tokens. Full implementation deferred (see plan Task 13).
        throw new ConnectorAuthException('XOAUTH2 mode is configured but token exchange is not enabled in this build.');
    }
}
```

> The test injects a fake factory via the container; see Step 4.

- [ ] **Step 4: Bind a fake factory in the test setUp**

Add to `tests/Feature/ImapConnectorAuthTest.php` a `setUp` that binds `ImapClientFactoryInterface` to a stub returning a `FakeImapClient`. The interface is created in Task 10, so for THIS task create a tiny inline interface stand-in: instead, define `ImapClientFactoryInterface` now as part of this task (move it earlier):

Create `src/Imap/ImapClientFactoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

interface ImapClientFactoryInterface
{
    /** @param array<string,mixed> $connection */
    public function make(array $connection, string $secret, string $authMode): ImapClientInterface;
}
```

Then in the test `setUp`:

```php
protected function setUp(): void
{
    parent::setUp();
    $this->app->bind(
        \Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface::class,
        fn () => new class implements \Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface {
            public function make(array $connection, string $secret, string $authMode): \Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface
            {
                return new \Padosoft\AskMyDocsConnectorImap\Tests\Support\FakeImapClient(['INBOX' => []]);
            }
        }
    );
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ImapConnectorAuthTest.php`
Expected: PASS (2 tests). Fix base-API name mismatches if the autoloader complains.

- [ ] **Step 6: Commit**

```bash
git add src/ImapConnector.php src/Imap/ImapClientFactoryInterface.php tests/Feature/ImapConnectorAuthTest.php
git commit -m "feat: imap connector identity, basic-auth, health, disconnect"
```

---

### Task 10: Real webklex ImapClientFactory

**Files:**
- Create: `src/Imap/ImapClientFactory.php` (implements `ImapClientFactoryInterface`, returns a webklex-backed `ImapClientInterface`)
- Create: `src/Imap/WebklexImapClient.php`
- Modify: `src/ImapServiceProvider.php` (bind `ImapClientFactoryInterface` → `ImapClientFactory`)
- Test: `tests/Unit/ImapClientFactoryTest.php` (construction only — no network)

**Interfaces:**
- Consumes: `webklex/php-imap` `ClientManager`/`Client`.
- Produces: `ImapClientFactory::make(array $connection, string $secret, string $authMode): ImapClientInterface`.

> **Read first:** open `vendor/webklex/php-imap/src/ClientManager.php` and `Client.php` to confirm the v6 API used below (`make(array)`, `connect()`, `getFolders()`, `getFolder()`, `query()->all()/since()`, message `getUid()`, `getAttachments()`, header accessors). Adjust method names to the installed version.

- [ ] **Step 1: Write `src/Imap/WebklexImapClient.php`**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

use Carbon\Carbon;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorApiException;
use Webklex\PHPIMAP\Client;

final class WebklexImapClient implements ImapClientInterface
{
    private bool $connected = false;

    public function __construct(private Client $client) {}

    private function ensure(): void
    {
        if (! $this->connected) {
            try {
                $this->client->connect();
                $this->connected = true;
            } catch (\Throwable $e) {
                throw new ConnectorApiException('IMAP connect failed: '.$e->getMessage(), previous: $e);
            }
        }
    }

    public function listMailboxes(): array
    {
        $this->ensure();
        $names = [];
        foreach ($this->client->getFolders(false) as $folder) {
            $names[] = $folder->path;
        }

        return $names;
    }

    public function selectMailbox(string $name): MailboxState
    {
        $this->ensure();
        $folder = $this->client->getFolder($name);
        $status = $folder->getStatus(); // ['uidvalidity' => int, 'uidnext' => int, ...]
        $uidValidity = (int) ($status['uidvalidity'] ?? 0);
        $lastUid = max(0, ((int) ($status['uidnext'] ?? 1)) - 1);

        return new MailboxState($uidValidity, $lastUid);
    }

    public function searchUids(string $mailbox, ?Carbon $since, ?int $sinceUid): array
    {
        $this->ensure();
        $folder = $this->client->getFolder($mailbox);
        $query = $folder->query();
        if ($since !== null) {
            $query = $query->since($since);
        }
        $uids = [];
        foreach ($query->setFetchBody(false)->get() as $message) {
            $uid = (int) $message->getUid();
            if ($sinceUid !== null && $uid <= $sinceUid) {
                continue;
            }
            $uids[] = $uid;
        }
        sort($uids);

        return $uids;
    }

    public function fetchMessage(string $mailbox, int $uid): ImapMessage
    {
        $this->ensure();
        $folder = $this->client->getFolder($mailbox);
        $message = $folder->query()->getMessageByUid($uid);

        $attachments = [];
        foreach ($message->getAttachments() as $a) {
            $attachments[] = new ImapAttachment(
                filename: (string) $a->getName(),
                mimeType: (string) $a->getMimeType(),
                sizeBytes: (int) $a->getSize(),
                isInline: (string) $a->getDisposition() === 'inline',
                contents: (string) $a->getContent(),
            );
        }

        $from = $message->getFrom()[0] ?? null;

        return new ImapMessage(
            uid: $uid,
            uidValidity: (int) ($folder->getStatus()['uidvalidity'] ?? 0),
            mailbox: $mailbox,
            messageId: (string) $message->getMessageId(),
            inReplyTo: ($v = (string) $message->getInReplyTo()) !== '' ? $v : null,
            references: array_values(array_filter(preg_split('/\s+/', (string) $message->getReferences()) ?: [])),
            fromName: $from?->personal ?? '',
            fromEmail: $from?->mail ?? '',
            to: $this->addresses($message->getTo()),
            cc: $this->addresses($message->getCc()),
            date: $message->getDate()?->toDate() ? Carbon::instance($message->getDate()->toDate()) : null,
            subject: (string) $message->getSubject(),
            flags: array_values((array) $message->getFlags()->all()),
            labels: [],
            textBody: $message->hasTextBody() ? (string) $message->getTextBody() : null,
            htmlBody: $message->hasHTMLBody() ? (string) $message->getHTMLBody() : null,
            rawHeaders: $this->headers($message),
            attachments: $attachments,
        );
    }

    public function ping(): bool
    {
        $this->ensure();

        return $this->client->isConnected();
    }

    public function close(): void
    {
        if ($this->connected) {
            $this->client->disconnect();
            $this->connected = false;
        }
    }

    /** @return list<array{name:string,email:string}> */
    private function addresses(mixed $attribute): array
    {
        $out = [];
        foreach ((array) ($attribute?->toArray() ?? []) as $addr) {
            $out[] = ['name' => (string) ($addr->personal ?? ''), 'email' => (string) ($addr->mail ?? '')];
        }

        return $out;
    }

    /** @return array<string,string> */
    private function headers(mixed $message): array
    {
        $out = [];
        foreach (['precedence', 'auto-submitted', 'list-unsubscribe'] as $h) {
            $val = $message->getHeader()->get($h);
            if ($val !== null && $val !== '') {
                $out[$h] = (string) $val;
            }
        }

        return $out;
    }
}
```

- [ ] **Step 2: Write `src/Imap/ImapClientFactory.php`**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Imap;

use Webklex\PHPIMAP\ClientManager;

final class ImapClientFactory implements ImapClientFactoryInterface
{
    public function make(array $connection, string $secret, string $authMode): ImapClientInterface
    {
        $cm = new ClientManager();
        $client = $cm->make([
            'host' => (string) ($connection['host'] ?? ''),
            'port' => (int) ($connection['port'] ?? 993),
            'encryption' => (string) ($connection['encryption'] ?? 'ssl'),
            'validate_cert' => (bool) ($connection['validate_cert'] ?? true),
            'username' => (string) ($connection['username'] ?? ''),
            'password' => $secret,
            'authentication' => $authMode === 'xoauth2' ? 'oauth' : null,
        ]);

        return new WebklexImapClient($client);
    }
}
```

- [ ] **Step 3: Bind in `ImapServiceProvider::register()`**

Add to `register()`:

```php
$this->app->bind(
    \Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface::class,
    \Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactory::class,
);
```

- [ ] **Step 4: Write a construction-only test**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Unit;

use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactory;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class ImapClientFactoryTest extends TestCase
{
    public function test_make_returns_client_without_connecting(): void
    {
        $client = (new ImapClientFactory)->make(
            ['host' => 'imap.test', 'port' => 993, 'encryption' => 'ssl', 'username' => 'u'],
            'secret',
            'basic',
        );

        $this->assertInstanceOf(ImapClientInterface::class, $client);
    }
}
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit tests/Unit/ImapClientFactoryTest.php`
Expected: PASS (1 test). If webklex method names differ, fix per Step "Read first".

- [ ] **Step 6: Run Pint + PHPStan**

Run: `vendor/bin/pint && vendor/bin/phpstan analyse`
Expected: no style errors; PHPStan green (add targeted ignores for webklex dynamic calls in `phpstan.neon.dist` only if unavoidable).

- [ ] **Step 7: Commit**

```bash
git add src/Imap/WebklexImapClient.php src/Imap/ImapClientFactory.php src/ImapServiceProvider.php tests/Unit/ImapClientFactoryTest.php phpstan.neon.dist
git commit -m "feat: webklex-backed imap client factory"
```

---

### Task 11: Sync orchestration (full + incremental + attachments + watermark)

**Files:**
- Modify: `src/ImapConnector.php` (replace the two stub sync methods + add private ingest helpers)
- Test: `tests/Feature/ImapSyncTest.php`

**Interfaces:**
- Consumes: `MailboxWalker`, `EmailToMarkdown`, `AttachmentPolicy`, `MessageFilter`, `MailMetadata`, `ImapClientInterface`, base `dispatchIngestion`, `vault->getExtra/setExtraKey`.
- Produces: working `syncFull` / `syncIncremental` returning real `SyncResult` counts; markdown + attachment files written via the host `resolveKbSourcePath`/`dispatchIngestion`.

> **Read first:** confirm how notion/google-drive call `resolveKbSourcePath()` + `Storage::disk()->put()` + `dispatchIngestion()` in `vendor`, and mirror that exact sequence.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapAttachment;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\ImapConnector;
use Padosoft\AskMyDocsConnectorImap\Tests\Support\FakeImapClient;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class ImapSyncTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private function seedClient(ImapClientInterface $client): void
    {
        $this->app->bind(ImapClientFactoryInterface::class, fn () => new class($client) implements ImapClientFactoryInterface {
            public function __construct(private ImapClientInterface $c) {}
            public function make(array $connection, string $secret, string $authMode): ImapClientInterface { return $this->c; }
        });
    }

    private function installation(): ConnectorInstallation
    {
        $inst = ConnectorInstallation::create([
            'tenant_id' => 'default',
            'connector_name' => 'imap',
            'config_json' => ['auth_mode' => 'basic', 'project_key' => 'kb-imap', 'connection' => ['host' => 'h', 'username' => 'u']],
            'status' => 'active',
        ]);
        $this->app->make(OAuthCredentialVault::class)->setCredentials($inst->id, accessToken: 'pw', refreshToken: null, expiresAt: null, extra: ['auth_mode' => 'basic']);

        return $inst;
    }

    public function test_sync_full_dispatches_email_and_attachment(): void
    {
        Storage::fake('local');
        $att = new ImapAttachment('fattura.pdf', 'application/pdf', 100, false, '%PDF-1.4');
        $msg = new ImapMessage(
            uid: 5, uidValidity: 1, mailbox: 'INBOX', messageId: '<m@x>', inReplyTo: null, references: [],
            fromName: 'Mario', fromEmail: 'mario@acme.com', to: [], cc: [], date: Carbon::now(),
            subject: 'Ciao', flags: [], labels: [], textBody: 'corpo', htmlBody: null, rawHeaders: [], attachments: [$att],
        );
        $this->seedClient(new FakeImapClient(['INBOX' => [$msg]], uidValidity: 1));
        $inst = $this->installation();

        $result = $this->app->make(ImapConnector::class)->syncFull($inst->id);

        // one email document + one attachment document
        $this->assertSame(2, $result->documentsAdded);
        $this->assertSame([], $result->errors);
    }

    public function test_incremental_persists_uid_watermark(): void
    {
        Storage::fake('local');
        $mk = fn (int $uid) => new ImapMessage(
            uid: $uid, uidValidity: 1, mailbox: 'INBOX', messageId: "<$uid@x>", inReplyTo: null, references: [],
            fromName: '', fromEmail: 'a@x', to: [], cc: [], date: Carbon::now(), subject: 'S', flags: [], labels: [],
            textBody: 'b', htmlBody: null, rawHeaders: [], attachments: [],
        );
        $this->seedClient(new FakeImapClient(['INBOX' => [$mk(1), $mk(2)]], uidValidity: 1));
        $inst = $this->installation();

        $this->app->make(ImapConnector::class)->syncIncremental($inst->id, Carbon::now()->subDay());

        $state = $this->app->make(OAuthCredentialVault::class)->getExtra($inst->id);
        $this->assertSame(2, $state['mailboxes_state']['INBOX']['last_uid']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ImapSyncTest.php`
Expected: FAIL (counts are 0 from the stubs).

- [ ] **Step 3: Replace the stub sync methods + add helpers**

Replace `syncFull`/`syncIncremental` in `src/ImapConnector.php` and add the private helpers below. Add the imports at the top:

```php
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorPaginationLimitException;
use Padosoft\AskMyDocsConnectorImap\Imap\AttachmentPolicy;
use Padosoft\AskMyDocsConnectorImap\Imap\EmailToMarkdown;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\Imap\MailboxWalker;
use Padosoft\AskMyDocsConnectorImap\Imap\MessageFilter;
use Padosoft\AskMyDocsConnectorImap\Support\MailMetadata;
```

Methods:

```php
public function syncFull(int $installationId): SyncResult
{
    return $this->runSync($installationId, null, true);
}

public function syncIncremental(int $installationId, ?Carbon $since): SyncResult
{
    if ($since === null) {
        return $this->syncFull($installationId);
    }

    return $this->runSync($installationId, $since, false);
}

private function runSync(int $installationId, ?Carbon $since, bool $full): SyncResult
{
    $installation = $this->loadInstallation($installationId);
    $config = $this->resolveConfig((array) ($installation->config_json ?? []));
    $projectKey = (string) ($config['project_key'] ?? ('connector-'.$this->key()));

    $client = $this->makeClient($installationId);
    $walker = new MailboxWalker($client, $config);
    $filter = new MessageFilter($config);
    $attachmentPolicy = new AttachmentPolicy((array) ($config['attachments'] ?? []));
    $preferText = (string) ($config['body_format'] ?? 'prefer_text') === 'prefer_text';
    $stripQuoted = (bool) ($config['strip_quoted_history'] ?? false);
    $maxMessages = (int) ($config['limits']['max_messages_per_sync'] ?? 5000);

    $state = (array) ($this->vault->getExtra($installationId)['mailboxes_state'] ?? []);
    $added = 0;
    $errors = [];
    $processed = 0;

    try {
        foreach ($walker->selectedMailboxes() as $mailbox) {
            $mailboxState = $full ? [] : (array) ($state[$mailbox] ?? []);
            $window = $walker->windowSince();
            $effectiveSince = $since !== null && $window !== null ? $since->max($window) : ($since ?? $window);
            $r = $walker->incrementalUids($mailbox, $mailboxState, $effectiveSince);
            $maxUid = (int) ($mailboxState['last_uid'] ?? 0);

            foreach ($r['uids'] as $uid) {
                if ($processed >= $maxMessages) {
                    throw new ConnectorPaginationLimitException(maxPages: $maxMessages);
                }
                $processed++;
                try {
                    $message = $client->fetchMessage($mailbox, $uid);
                    if (! $filter->passes($message)) {
                        $maxUid = max($maxUid, $uid);

                        continue;
                    }
                    $added += $this->ingestMessage($installation, $projectKey, $config, $message, $attachmentPolicy, $preferText, $stripQuoted);
                    $maxUid = max($maxUid, $uid);
                } catch (\Throwable $e) {
                    $errors[] = sprintf('%s uid %d: %s', $mailbox, $uid, $e->getMessage());
                }
            }

            $state[$mailbox] = ['uidvalidity' => $r['uidValidity'], 'last_uid' => $maxUid];
        }
    } catch (ConnectorPaginationLimitException $e) {
        $errors[] = sprintf('sync truncated at max_messages_per_sync=%d', $maxMessages);
    } finally {
        $client->close();
    }

    $this->vault->setExtraKey($installationId, 'mailboxes_state', $state);

    return new SyncResult(
        documentsAdded: $full ? $added : 0,
        documentsUpdated: $full ? 0 : $added,
        documentsRemoved: 0,
        errors: $errors,
        completedAt: Carbon::now(),
    );
}

/** @param array<string,mixed> $config */
private function ingestMessage(
    object $installation,
    string $projectKey,
    array $config,
    ImapMessage $message,
    AttachmentPolicy $policy,
    bool $preferText,
    bool $stripQuoted,
): int {
    $count = 0;
    $mailboxSlug = Str::slug($message->mailbox) ?: 'folder';

    $markdown = (new EmailToMarkdown)->render($message, $preferText, $stripQuoted);
    if (($config['redact_pii'] ?? false) === true) {
        $markdown = $this->maybeRedactContent($markdown);
    }

    $relative = sprintf('%s/connectors/imap/installation-%d/%s/%d.md', $projectKey, $installation->id, $mailboxSlug, $message->uid);
    $paths = $this->resolveKbSourcePath($relative);
    Storage::disk($paths['disk'])->put($paths['absolute'], $markdown);

    $this->dispatchIngestion(
        projectKey: $projectKey,
        relativePath: $paths['relative'],
        disk: $paths['disk'],
        title: $message->subject !== '' ? $message->subject : '(no subject)',
        metadata: (new MailMetadata)->build($installation->id, $message),
        mimeType: 'text/markdown',
        tenantId: $installation->tenant_id,
    );
    $count++;

    $emitted = 0;
    foreach ($message->attachments as $attachment) {
        if ($emitted >= $policy->limit()) {
            break;
        }
        if (! $policy->accepts($attachment)) {
            continue;
        }
        $emitted++;
        $safe = Str::slug(pathinfo($attachment->filename, PATHINFO_FILENAME)) ?: 'file';
        $ext = pathinfo($attachment->filename, PATHINFO_EXTENSION);
        $attRelative = sprintf('%s/connectors/imap/installation-%d/%s/%d/%s.%s', $projectKey, $installation->id, $mailboxSlug, $message->uid, $safe, $ext);
        $attPaths = $this->resolveKbSourcePath($attRelative);
        Storage::disk($attPaths['disk'])->put($attPaths['absolute'], $attachment->contents);

        $meta = (new MailMetadata)->build($installation->id, $message);
        $meta['attachment_of_message_id'] = $message->messageId;
        $meta['attachment_filename'] = $attachment->filename;

        $this->dispatchIngestion(
            projectKey: $projectKey,
            relativePath: $attPaths['relative'],
            disk: $attPaths['disk'],
            title: $attachment->filename,
            metadata: $meta,
            mimeType: $attachment->mimeType !== '' ? $attachment->mimeType : 'application/octet-stream',
            tenantId: $installation->tenant_id,
        );
        $count++;
    }

    return $count;
}

/** @param array<string,mixed> $config @return array<string,mixed> */
private function resolveConfig(array $config): array
{
    $defaults = (array) config('connectors.providers.imap.defaults', []);
    $config['attachments'] = array_merge((array) ($defaults['attachments'] ?? []), (array) ($config['attachments'] ?? []));
    $config['limits'] = array_merge((array) ($defaults['limits'] ?? []), (array) ($config['limits'] ?? []));
    $config['date_window_days'] ??= $defaults['date_window_days'] ?? 365;
    $config['skip_auto_generated'] ??= $defaults['skip_auto_generated'] ?? true;
    $config['body_format'] ??= $defaults['body_format'] ?? 'prefer_text';
    $config['folders']['exclude'] = $config['folders']['exclude'] ?? ($defaults['folders_exclude'] ?? []);

    return $config;
}
```

> Match `resolveKbSourcePath()` return keys (`disk`/`absolute`/`relative`) and `dispatchIngestion()` argument names to the base package read in the "Read first" note. If the base exposes these as inherited protected methods with different names, rename the calls only.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ImapSyncTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the whole suite + Pint + PHPStan**

Run: `vendor/bin/phpunit && vendor/bin/pint --test && vendor/bin/phpstan analyse`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/ImapConnector.php tests/Feature/ImapSyncTest.php
git commit -m "feat: imap sync orchestration with attachments and uid watermark"
```

---

### Task 12: Optional deletion reconciliation

**Files:**
- Modify: `src/ImapConnector.php` (reconciliation pass gated by `config_json.reconcile_deletions`)
- Test: `tests/Feature/ImapReconcileTest.php`

**Interfaces:**
- Consumes: `softDeleteByMetadataKey(installation, 'imap_uid', (string)$uid)` from base.
- Produces: when `reconcile_deletions=true`, soft-deletes documents whose UID is no longer present in the selected mailbox UID set; increments `documentsRemoved`.

> **Read first:** confirm `softDeleteByMetadataKey` exists on `BaseConnector` and whether a *bulk* "list ingested remote-ids for installation" helper exists. If NOT, this task's reconciliation can only soft-delete UIDs it can enumerate. Per the admin→core backfill policy, if a bulk enumeration helper is needed, STOP and add it to `padosoft/askmydocs-connector-base` first (separate PR), then return here. For v1 the simple approach below diff-checks the *current* UID set against the stored `mailboxes_state` ingested set, which we extend to keep a capped recent-UID list.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientInterface;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapMessage;
use Padosoft\AskMyDocsConnectorImap\ImapConnector;
use Padosoft\AskMyDocsConnectorImap\Tests\Support\FakeImapClient;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class ImapReconcileTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_reconcile_soft_deletes_vanished_uids(): void
    {
        Storage::fake('local');
        $mk = fn (int $uid) => new ImapMessage(
            uid: $uid, uidValidity: 1, mailbox: 'INBOX', messageId: "<$uid@x>", inReplyTo: null, references: [],
            fromName: '', fromEmail: 'a@x', to: [], cc: [], date: Carbon::now(), subject: 'S', flags: [], labels: [],
            textBody: 'b', htmlBody: null, rawHeaders: [], attachments: [],
        );

        // First sync sees uids 1,2.
        $client = new FakeImapClient(['INBOX' => [$mk(1), $mk(2)]], uidValidity: 1);
        $this->app->bind(ImapClientFactoryInterface::class, fn () => new class($client) implements ImapClientFactoryInterface {
            public function __construct(public ImapClientInterface $c) {}
            public function make(array $connection, string $secret, string $authMode): ImapClientInterface { return $this->c; }
        });

        $inst = ConnectorInstallation::create([
            'tenant_id' => 'default', 'connector_name' => 'imap', 'status' => 'active',
            'config_json' => ['auth_mode' => 'basic', 'project_key' => 'kb', 'reconcile_deletions' => true, 'connection' => ['host' => 'h', 'username' => 'u']],
        ]);
        $this->app->make(OAuthCredentialVault::class)->setCredentials($inst->id, accessToken: 'pw', refreshToken: null, expiresAt: null, extra: []);

        $connector = $this->app->make(ImapConnector::class);
        $connector->syncFull($inst->id);

        // Now uid 2 disappeared from the server.
        $client2 = new FakeImapClient(['INBOX' => [$mk(1)]], uidValidity: 1);
        $this->app->bind(ImapClientFactoryInterface::class, fn () => new class($client2) implements ImapClientFactoryInterface {
            public function __construct(public ImapClientInterface $c) {}
            public function make(array $connection, string $secret, string $authMode): ImapClientInterface { return $this->c; }
        });

        $result = $connector->syncIncremental($inst->id, Carbon::now()->subDay());
        $this->assertGreaterThanOrEqual(1, $result->documentsRemoved);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/ImapReconcileTest.php`
Expected: FAIL (`documentsRemoved` is 0).

- [ ] **Step 3: Implement reconciliation**

In `runSync`, after building `$state[$mailbox]`, add (still inside the mailbox loop):

```php
if (($config['reconcile_deletions'] ?? false) === true) {
    $seen = (array) ($mailboxState['ingested_uids'] ?? []);
    $current = $client->searchUids($mailbox, null, null);
    $vanished = array_diff($seen, $current);
    foreach ($vanished as $goneUid) {
        if ($this->softDeleteByMetadataKey($installation, 'imap_uid', (string) $goneUid)) {
            $removed++;
        }
    }
    $state[$mailbox]['ingested_uids'] = array_values(array_slice(
        array_unique(array_merge($current, $r['uids'])), -1000
    ));
} else {
    // keep a capped recent set for a future reconcile run
    $prior = (array) ($mailboxState['ingested_uids'] ?? []);
    $state[$mailbox]['ingested_uids'] = array_values(array_slice(
        array_unique(array_merge($prior, $r['uids'])), -1000
    ));
}
```

Add `$removed = 0;` near `$added = 0;`, and change the `SyncResult` to pass `documentsRemoved: $removed`. For `syncFull`, seed `ingested_uids` from the fetched UIDs so a later incremental can diff against them (the `else` branch already does this because full sync re-runs the same loop with `$mailboxState = []`, so prior is empty — instead, in full sync set `ingested_uids` to `$r['uids']`). Adjust:

```php
// after the foreach over uids, before reconcile block:
if ($full) {
    $mailboxState['ingested_uids'] = $r['uids'];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/ImapReconcileTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Run full suite**

Run: `vendor/bin/phpunit && vendor/bin/pint --test && vendor/bin/phpstan analyse`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add src/ImapConnector.php tests/Feature/ImapReconcileTest.php
git commit -m "feat: optional deletion reconciliation for imap"
```

---

### Task 13: CI, Live suite, README, docs

**Files:**
- Create: `.github/workflows/tests.yml`
- Create: `tests/Live/ImapLiveTest.php`
- Create: `README.md`
- Create: `docs/LESSON.md`, `docs/PROGRESS.md`

**Interfaces:** none (delivery + docs).

- [ ] **Step 1: Write `.github/workflows/tests.yml`**

```yaml
name: CI

on:
  push: { branches: [main] }
  pull_request: { branches: [main] }

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['8.3', '8.4', '8.5']
        laravel: ['12.*', '13.*']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none
      - name: Constrain Laravel
        run: composer require "illuminate/support:${{ matrix.laravel }}" --no-update --no-interaction
      - run: composer update --prefer-dist --no-interaction --no-progress
      - name: PHPUnit (Unit + Feature)
        run: vendor/bin/phpunit --testsuite=Unit,Feature --no-coverage
      - name: Pint
        run: vendor/bin/pint --test
      - name: PHPStan
        run: vendor/bin/phpstan analyse
```

- [ ] **Step 2: Write `tests/Live/ImapLiveTest.php`**

```php
<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap\Tests\Live;

use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactory;
use Padosoft\AskMyDocsConnectorImap\Tests\TestCase;

final class ImapLiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CONNECTOR_IMAP_LIVE') !== '1') {
            $this->markTestSkipped('Set CONNECTOR_IMAP_LIVE=1 and IMAP_* env to run live tests.');
        }
    }

    public function test_live_login_and_list_folders(): void
    {
        $client = (new ImapClientFactory)->make([
            'host' => getenv('IMAP_HOST'),
            'port' => (int) (getenv('IMAP_PORT') ?: 993),
            'encryption' => getenv('IMAP_ENCRYPTION') ?: 'ssl',
            'username' => getenv('IMAP_USERNAME'),
        ], (string) getenv('IMAP_PASSWORD'), 'basic');

        $this->assertTrue($client->ping());
        $this->assertNotEmpty($client->listMailboxes());
        $client->close();
    }
}
```

- [ ] **Step 3: Write `README.md`**

Use the house structure (mirror `askmydocs-connector-google-drive/README.md`):
Why · Features · Architecture · Installation (`composer require padosoft/askmydocs-connector-imap`, `vendor:publish --tag=connector-imap-config`/`--tag=connector-imap-assets`) · Credential setup (basic host/port/encryption/username + Gmail/O365 app-password; XOAUTH2 design-ready) · Activation inside AskMyDocs (pick connector → fill IMAP form → test → activate) · What gets ingested (table: email→markdown doc with header block + body; attachments→separate KB docs; metadata fields list) · Sync semantics (full, incremental UID watermark per UIDVALIDITY, optional reconciliation, append-mostly) · Config reference (every `config_json` knob with default) · Testing (Unit/Feature + `CONNECTOR_IMAP_LIVE=1`) · Troubleshooting (login fails, app-password, folder names, oversize attachments skipped, nothing ingested → check filters) · License Apache-2.0.

- [ ] **Step 4: Seed `docs/LESSON.md` and `docs/PROGRESS.md`**

`docs/PROGRESS.md`:

```markdown
# PROGRESS — askmydocs-connector-imap

- [x] Scaffold + base wiring
- [x] EmailToMarkdown, AttachmentPolicy, MessageFilter, MailboxWalker, MailMetadata
- [x] Config + ServiceProvider
- [x] Connector: identity, basic-auth, health, disconnect
- [x] webklex client factory
- [x] Sync full/incremental + attachments + watermark
- [x] Optional deletion reconciliation
- [x] CI + Live suite + README
- [ ] XOAUTH2 full token exchange (deferred — see design §5)
```

`docs/LESSON.md`:

```markdown
# LESSON — askmydocs-connector-imap

- The base contract is OAuth-centric; IMAP reuses initiateOAuth/handleOAuthCallback
  as a *credential acquisition* seam (basic-auth posts host/port/user to the
  callback; password → vault, connection params → config_json).
- webklex/php-imap chosen over ext-imap (unbundled in PHP 8.4+). All webklex calls
  are isolated behind ImapClientInterface so the rest of the package is testable
  with a FakeImapClient and .eml fixtures.
- Incremental sync keys off UIDVALIDITY + UID watermark per mailbox in vault
  extra_json; UIDVALIDITY change forces a window-bounded rescan.
```

- [ ] **Step 5: Run the whole suite once more**

Run: `vendor/bin/phpunit && vendor/bin/pint --test && vendor/bin/phpstan analyse`
Expected: all green (Live suite skipped).

- [ ] **Step 6: Commit**

```bash
git add .github tests/Live README.md docs/LESSON.md docs/PROGRESS.md
git commit -m "docs: CI, live suite, README and package docs"
```

---

## Self-Review

**Spec coverage:**
- §1 scope (email→md, attachments, dual auth, filters, incremental, reconcile) → Tasks 3–12. ✓
- §3 deps (webklex, base) → Task 1. ✓
- §4 layout → Tasks map 1:1 to files. ✓
- §5 auth (basic primary, xoauth2 design-ready) → Task 9 (basic complete; xoauth2 authorize URL present, token exchange explicitly deferred in Task 9 + PROGRESS). ✓
- §6 email document + metadata → Tasks 3, 7, 11. ✓
- §7 attachments (allowlist + max_size_mb + max_per_email + skip_inline) → Tasks 4, 11. ✓
- §8 filters (folders/senders/recipients/date/subject/auto-gen/etc.) → Tasks 5, 6, 11. (`recipients` filter not yet wired — see gap below.)
- §9 incremental + reconciliation → Tasks 6, 11, 12. ✓
- §10 errors → Tasks 10, 11 (Api/Auth/PaginationLimit). ✓
- §11 testing → every task TDD + Task 13 Live/CI. ✓
- §12 config reference → Task 8. ✓
- §13 README → Task 13. ✓

**Gap found & resolved:** `recipients.include/exclude` from §8 had no task. It is a thin
addition to `MessageFilter`. Fold into Task 5 by adding a `recipientPasses()` mirroring
`senderPasses()` over `$m->to`+`$m->cc`, plus one test `test_recipient_exclude`. (Implementer:
copy the sender logic, iterate the merged to+cc emails, exclude if any matches an exclude
needle, and require at least one include match when an include list is set.)

**Placeholder scan:** no TBD/TODO in steps; every code step shows full code. XOAUTH2 token
exchange is an explicit, documented deferral (throws a clear exception), not a silent stub.

**Type consistency:** `ImapMessage`/`ImapAttachment`/`MailboxState`/`ImapClientInterface`/
`ImapClientFactoryInterface` signatures are identical across Tasks 2, 9, 10, 11, 12.
`MailMetadata::build(int,$ImapMessage)`, `AttachmentPolicy::accepts/limit`,
`MessageFilter::passes`, `MailboxWalker::{selectedMailboxes,windowSince,incrementalUids}`
match their call sites in Task 11.
