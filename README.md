<h1 align="center">askmydocs-connector-imap</h1>

<p align="center">
  <strong>IMAP email connector for AskMyDocs — dual auth, email-to-markdown, document attachments, rich filters, incremental UID watermark.</strong><br/>
  Drop-in Laravel package. <code>composer require</code> it from any AskMyDocs install and the IMAP connector appears in the admin UI on the next request.
</p>

<p align="center">
  <a href="https://github.com/padosoft/askmydocs-connector-imap/actions/workflows/tests.yml"><img alt="CI status" src="https://img.shields.io/github/actions/workflow/status/padosoft/askmydocs-connector-imap/tests.yml?branch=main&label=tests"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-imap"><img alt="Packagist version" src="https://img.shields.io/packagist/v/padosoft/askmydocs-connector-imap.svg?label=packagist"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-imap"><img alt="Total downloads" src="https://img.shields.io/packagist/dt/padosoft/askmydocs-connector-imap.svg?label=downloads"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-Apache--2.0-blue.svg"></a>
  <img alt="PHP version" src="https://img.shields.io/badge/php-8.3%20%7C%208.4%20%7C%208.5-777BB4">
  <img alt="Laravel version" src="https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20">
</p>

---

## Table of contents

1. [Why this package](#why-this-package)
2. [Features](#features)
3. [Architecture at a glance](#architecture-at-a-glance)
4. [Installation](#installation)
5. [Credential setup (junior-proof, step by step)](#credential-setup-junior-proof-step-by-step)
6. [Activation inside AskMyDocs](#activation-inside-askmydocs)
7. [What gets ingested](#what-gets-ingested)
8. [Sync semantics](#sync-semantics)
9. [Configuration reference](#configuration-reference)
10. [Testing](#testing)
11. [Live testsuite](#live-testsuite)
12. [Troubleshooting](#troubleshooting)
13. [License](#license)

---

## Why this package

[AskMyDocs](https://github.com/lopadova/AskMyDocs) is an enterprise-grade RAG + canonical knowledge compilation system. Out of the box it ingests markdown from disk, the chat UI, an HTTP API, and a Git-driven workflow — but the richest operational knowledge in most organisations flows through email: customer-service inboxes, support queues, shared mailboxes, internal mailing lists.

This package is the smallest possible surface for shipping that integration:

- An `ImapConnector` that implements `Padosoft\AskMyDocsConnectorBase\ConnectorInterface`.
- `EmailToMarkdown` that renders the full header block (From / To / Cc / Date / Folder / Message-ID / Attachments) plus text or HTML body into clean markdown.
- `AttachmentPolicy` + per-email ingestion that turns every qualifying document attachment into its own KB doc (separate chunking + retrieval).
- `MessageFilter` with twelve independent filter axes (sender, recipient, subject keywords, date window, flagged, unseen, auto-generated, folder include/exclude).
- Incremental sync via UIDVALIDITY + UID watermark per mailbox, persisted in the vault's encrypted `extra_json`.
- Optional deletion reconciliation (`reconcile_deletions`) that soft-deletes KB docs whose source UIDs have vanished from the mailbox.
- A `composer.json` that auto-registers via `extra.askmydocs.connectors`. Zero edits to host app config required.

> **`composer require padosoft/askmydocs-connector-imap`. Done.**

## Features

- **Zero-config installation** — composer-extra discovery auto-registers the connector at boot.
- **Basic-auth (production-ready)** — host / port / encryption / username / password (or app-password for Gmail and Outlook). Connection verified on install before credentials are stored.
- **XOAUTH2 design-ready** — authorize URL generation and state-token round-trip wired; the full token-exchange path is explicitly deferred to the next sprint (throws a clear exception when triggered, never silently falls back).
- **Email-to-markdown** — every message becomes a markdown document with a structured header block capturing From, To, Cc, Date, Folder, Message-ID, and Attachments filenames. HTML bodies are converted to plain text; text bodies used as-is.
- **Document attachments as separate KB docs** — each qualifying attachment is stored independently so the chunker and retriever treat it as a first-class document, not a blob hidden inside the email.
- **Allowlist + size cap** — attachments are accepted only when extension is in the allowlist (pdf, doc, docx, ppt, pptx, xls, xlsx, txt, csv, md, rtf, odt by default) and size is under 25 MB. Inline / embedded images are skipped.
- **Rich filters** — 12 orthogonal knobs: sender include/exclude, recipient include/exclude, subject keyword include/exclude, date window (default 365 days), only_unseen, only_flagged, skip_auto_generated (Precedence: bulk/list + Auto-Submitted + List-Unsubscribe headers), strip_quoted_history.
- **Incremental UID watermark** — tracks `uidvalidity + last_uid` per mailbox in the vault. UIDVALIDITY change forces a window-bounded rescan; normal incremental runs only fetch new UIDs.
- **Optional deletion reconciliation** — `reconcile_deletions: true` computes vanished UIDs and calls `softDeleteByRemoteId` on the host's ingestion contract.
- **Provider defaults** — `connectors.providers.imap.defaults` supplies safe defaults at the Laravel config level; per-installation `config_json` overrides only what differs. New installs need zero explicit config for most deployments.
- **Failure-loud exception taxonomy** — auth failures throw `ConnectorAuthException`, pagination cap throws `ConnectorPaginationLimitException`. No silent swallowing.
- **Per-tenant isolation** — every credential read and ingestion dispatch is scoped to the active `TenantContext`.
- **Test-friendly** — `FakeImapClient` + `.eml` fixtures for full deterministic feature coverage; opt-in live test when `CONNECTOR_IMAP_LIVE=1`.

## Architecture at a glance

```
                ┌──────────────────────────┐
Composer        │ padosoft/askmydocs-      │
require ───────▶│ connector-imap           │
                │ (this package)           │
                └────────────┬─────────────┘
                             │
                             │ auto-registered via composer
                             │ extra.askmydocs.connectors
                             ▼
                ┌──────────────────────────────┐
                │ padosoft/askmydocs-connector-│
                │ base v1.1.0+                 │
                │ ConnectorRegistry            │
                └────────────┬─────────────────┘
                             │
                             │ resolves ImapConnector
                             ▼
                ┌──────────────────────────────┐
                │ ImapClientFactory            │
                │  (webklex/php-imap v6)       │
                │  • ping()                    │
                │  • listMailboxes()           │
                │  • selectMailbox()           │
                │  • searchUids()              │
                │  • fetchMessage()            │
                └────────────┬─────────────────┘
                             │ per-UID
                             ▼
                ┌──────────────────────────────┐
                │ ImapConnector::runSync()     │
                │  MailboxWalker              │
                │   → selectedMailboxes       │
                │   → windowSince / incUids   │
                │  MessageFilter::passes()    │
                │  EmailToMarkdown::render()  │
                │  AttachmentPolicy::accepts()│
                └────────────┬─────────────────┘
                             │
                             │ ConnectorIngestionContract
                             │ (IoC bridge — host implements)
                             ▼
                ┌──────────────────────────────┐
                │ Host app (AskMyDocs):        │
                │  • Storage::put → KB disk    │
                │  • IngestDocumentJob         │
                │  • kb_canonical_audit row    │
                │  • PII redactor at boundary  │
                └──────────────────────────────┘
```

The IoC bridge is the key design decision: this package never imports `App\Jobs\IngestDocumentJob`, `App\Models\KnowledgeDocument`, or any other host class. It dispatches every host-side concern through `Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract`. The host binds its own implementation in a service provider; this package stays standalone-agnostic and runs inside AskMyDocs Community Edition, AskMyDocs Pro, or any third-party Laravel app that wants mailbox-backed RAG.

The IMAP library is `webklex/php-imap` v6 — chosen because `ext-imap` was unbundled in PHP 8.4+. All webklex calls are isolated behind `ImapClientInterface`, so every test uses `FakeImapClient` with deterministic `.eml` fixtures and never touches a real server.

## Installation

```bash
composer require padosoft/askmydocs-connector-imap
```

The package follows Laravel's auto-discovery convention so no manual provider registration is required. After install, run:

```bash
php artisan vendor:publish --tag=connector-imap-config   # optional — publishes config/connector-imap.php for env-var overrides
php artisan vendor:publish --tag=connector-imap-assets   # optional — copies imap.svg to public/connectors/
```

The `connector-base` migrations ship in the parent package (`padosoft/askmydocs-connector-base`) and auto-load via its service provider; no extra `migrate` step is needed.

## Credential setup (junior-proof, step by step)

The IMAP connector supports two authentication modes. **Basic-auth is the production-ready path**; XOAUTH2 is design-ready but the full token-exchange is deferred (see below).

---

### Basic-auth (recommended for most deployments)

Basic-auth uses your mailbox username + password (or, for providers that require it, an app-specific password). The connector verifies the credentials with a live IMAP ping before storing them — if `ping()` fails, the install is rejected immediately so you can fix credentials before the first sync.

#### Gmail

Google disables direct password login by default on accounts with 2-Step Verification enabled (which is the right choice for production). You must create an **App Password**:

1. Open <https://myaccount.google.com/security> and sign in with the mailbox account.
2. Under **"How you sign in to Google"**, click **"2-Step Verification"** and make sure it is enabled. (If it isn't, enable it first — App Passwords are only available when 2SV is on.)
3. Go back to <https://myaccount.google.com/security> and click **"App passwords"** (it appears only when 2SV is active). If you don't see it, search "App passwords" in the Google Account search box.
4. In the **"Select app"** dropdown, choose **"Mail"**. In **"Select device"**, choose **"Other (Custom name)"** and type `AskMyDocs`.
5. Click **"Generate"**. Google shows a 16-character password (e.g. `abcd efgh ijkl mnop`). Copy it immediately — it is shown only once.
6. Use these connection values in the AskMyDocs admin form:
   - **Host**: `imap.gmail.com`
   - **Port**: `993`
   - **Encryption**: `ssl`
   - **Username**: your Gmail address (e.g. `support@yourcompany.com`)
   - **Password**: the 16-character App Password (no spaces)

#### Outlook / Microsoft 365

Microsoft 365 requires Modern Authentication (OAuth) by default for new tenants, but IMAP basic-auth can be re-enabled per-mailbox via the Exchange admin centre if your security policy allows it. For shared mailboxes this is the most common enterprise path:

1. In the Microsoft 365 admin centre, go to **Users → Active users** and open the mailbox.
2. Under the **"Mail"** tab, confirm IMAP is shown as enabled.
3. In the Exchange admin centre (<https://admin.exchange.microsoft.com>), navigate to **Recipients → Mailboxes**, open the mailbox, go to **"Email apps"** and confirm **"IMAP"** is on.
4. For the password, use the mailbox account's Microsoft 365 password, or create a dedicated service account with a non-expiring password and assign it **"Send As"** + **"Full Access"** on the shared mailbox.
5. Connection values:
   - **Host**: `outlook.office365.com`
   - **Port**: `993`
   - **Encryption**: `ssl`
   - **Username**: the mailbox address or service account UPN
   - **Password**: the account password

#### Generic IMAP (Postfix, Dovecot, custom mail servers)

Use your server's IMAP hostname, port 993 (SSL) or 143 (STARTTLS), and the mailbox account credentials. The connector sends `CAPABILITY` + `LOGIN` on connect; any standard IMAP4rev1 server works.

---

### XOAUTH2 (design-ready, token exchange deferred)

> **Status: not yet enabled for production.** The authorize URL generation and CSRF state-token round-trip are implemented. The authorization code to token exchange step throws `ConnectorAuthException('XOAUTH2 mode is configured but token exchange is not enabled in this build.')` until a future sprint completes it.

When XOAUTH2 is eventually enabled, the flow for Google Gmail will use these env vars:

```dotenv
CONNECTOR_IMAP_GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
CONNECTOR_IMAP_GOOGLE_CLIENT_SECRET=your-client-secret
CONNECTOR_IMAP_GOOGLE_REDIRECT_URI=https://your-app.example.com/api/admin/connectors/imap/oauth/callback
```

For Microsoft 365:

```dotenv
CONNECTOR_IMAP_MS_CLIENT_ID=your-ms-app-id
CONNECTOR_IMAP_MS_CLIENT_SECRET=your-ms-client-secret
CONNECTOR_IMAP_MS_REDIRECT_URI=https://your-app.example.com/api/admin/connectors/imap/oauth/callback
```

---

### Provider-level env vars summary

```dotenv
# Credential form URL (where basic-auth credentials are entered in the UI)
CONNECTOR_IMAP_CREDENTIAL_FORM_URL=https://your-app.example.com/admin/connectors/imap/credentials

# XOAUTH2 — Google (design-ready, deferred)
CONNECTOR_IMAP_GOOGLE_CLIENT_ID=
CONNECTOR_IMAP_GOOGLE_CLIENT_SECRET=
CONNECTOR_IMAP_GOOGLE_REDIRECT_URI=

# XOAUTH2 — Microsoft 365 (design-ready, deferred)
CONNECTOR_IMAP_MS_CLIENT_ID=
CONNECTOR_IMAP_MS_CLIENT_SECRET=
CONNECTOR_IMAP_MS_REDIRECT_URI=
```

## Activation inside AskMyDocs

After `composer require` and the credential setup above:

1. Run the host app's admin UI.
2. Navigate to **Settings → Connectors**.
3. The **Email (IMAP)** card appears. Click **Install**.
4. Fill in the IMAP connection form: host, port, encryption, username, password (or app-password). The connector sends a live `ping()` before saving — fix any errors shown before proceeding.
5. On success the installation status flips to `active`. The first full sync fires within the cadence window (default 15 minutes; configurable via `CONNECTOR_DEFAULT_SYNC_CADENCE_MINUTES` in the host app). To trigger immediately, click **Sync now**.

## What gets ingested

### Email document

Each email that passes the filters becomes one markdown document ingested into the KB.

| Field | Source | Notes |
|---|---|---|
| Title | `Subject` header | Falls back to `(no subject)` if absent |
| Body | Text/plain or HTML converted to text | Controlled by `body_format`; `strip_quoted_history` removes reply chains |
| Header block | Top of markdown body | Rendered as a structured block with all fields below |

**Header block fields** (always present in the rendered markdown):

| Header field | IMAP source |
|---|---|
| `From` | `From` header — display name + email address |
| `To` | `To` header — semicolon-separated addresses |
| `Cc` | `Cc` header — semicolon-separated addresses |
| `Date` | `Date` header — ISO-8601 |
| `Folder` | IMAP mailbox name |
| `Message-ID` | `Message-ID` header |
| `Attachments` | Comma-separated filenames of all MIME attachments |

**Metadata fields** (stored under `metadata.converter_hints.imap`):

| Key | Value |
|---|---|
| `connector` | `imap` |
| `installation_id` | installation integer ID |
| `imap_uid` | IMAP UID (used for deletion reconciliation) |
| `mailbox` | IMAP folder name |
| `message_id` | `Message-ID` header value |
| `from_email` | Sender address |
| `subject` | Email subject |
| `date_sent` | ISO-8601 send date |
| `flags` | IMAP system flags (`\Seen`, `\Flagged`, etc.) |

### Attachments

Each qualifying attachment is stored and ingested as a **separate KB document**, titled by filename.

| Criterion | Default |
|---|---|
| Attachment ingestion | Enabled |
| Allowed extensions | `pdf`, `doc`, `docx`, `ppt`, `pptx`, `xls`, `xlsx`, `txt`, `csv`, `md`, `rtf`, `odt` |
| Maximum size per attachment | 25 MB |
| Maximum attachments per email | 20 |
| Skip inline / embedded images | Yes |

Attachments that exceed 25 MB, have a non-allowlisted extension, or are flagged as inline (Content-Disposition: inline) are silently skipped. The email document is still ingested; only that specific attachment is dropped.

Attachment metadata inherits all email fields above, plus:

| Key | Value |
|---|---|
| `attachment_of_message_id` | `Message-ID` of the parent email |
| `attachment_filename` | Original filename |

## Sync semantics

### Full sync

Called on first install or when explicitly triggered. Walks all selected mailboxes, fetches every UID matching the date window, processes each message, and at the end persists a fresh `uidvalidity + last_uid` watermark per mailbox into the vault's `extra_json.mailboxes_state`.

### Incremental sync

On subsequent runs the connector reads `mailboxes_state` from the vault. For each mailbox:

1. Calls `selectMailbox()` to get the live `UIDVALIDITY`.
2. If `UIDVALIDITY` matches the stored value, passes `last_uid` as a lower-bound to `SEARCH UID <last_uid+1>:*` — only new UIDs are fetched.
3. If `UIDVALIDITY` has changed (mailbox was deleted and recreated), clears the watermark and does a window-bounded rescan (same as full sync limited to `date_window_days`).

The result is an **append-mostly** sync: normal daily runs touch only new messages and complete in seconds even on large mailboxes.

### Deletion reconciliation

When `reconcile_deletions: true` is set in `config_json`, after processing new UIDs the connector calls `SEARCH` with no UID filter to get all current UIDs, diffs them against the stored `ingested_uids` list (capped at 1,000 most recent), and calls `softDeleteByRemoteId('imap_uid', $uid)` for each vanished UID. The host's ingestion contract handles the actual deletion of `knowledge_documents` rows.

This is disabled by default because the diff query is expensive on large mailboxes. Enable it only when you need the KB to reflect actual deletions.

### Sync cap

`max_messages_per_sync` (default 5,000) prevents runaway syncs on newly-connected large mailboxes. When the cap is hit, the watermark is saved for all processed mailboxes and a truncation note is added to `SyncResult.errors`; the next incremental run continues from where the cap was reached.

## Configuration reference

All knobs live in the installation's `config_json` column. Provider-level defaults in `config/connector-imap.php` (under `defaults`) are merged at sync time; you only need to set what differs from the default.

### Top-level knobs

| Key | Default | Description |
|---|---|---|
| `auth_mode` | `"basic"` | Authentication mode: `"basic"` (password / app-password) or `"xoauth2"` (design-ready, deferred) |
| `project_key` | `"connector-imap"` | KB project key under which documents are stored; usually matches the AskMyDocs project slug |
| `date_window_days` | `365` | How far back to look for messages. `0` disables the window (all history). Applies to both full and UIDVALIDITY-reset incremental runs |
| `only_unseen` | `false` | Only process messages not yet marked `\Seen` |
| `only_flagged` | `false` | Only process messages marked `\Flagged` |
| `skip_auto_generated` | `true` | Skip messages with `Precedence: bulk/list`, `Auto-Submitted:` (not `no`), or `List-Unsubscribe:` headers |
| `strip_quoted_history` | `false` | Remove quoted reply blocks from the body before rendering markdown |
| `body_format` | `"prefer_text"` | `"prefer_text"` uses `text/plain` when available, falls back to HTML converted to text. `"prefer_html"` inverts priority |
| `redact_pii` | `false` | Pass the rendered markdown through the host's PII redactor before storing |
| `reconcile_deletions` | `false` | Enable UID-diff deletion reconciliation (expensive on large mailboxes) |

### Connection

Under `config_json.connection`:

| Key | Default | Description |
|---|---|---|
| `connection.host` | — | IMAP hostname (e.g. `imap.gmail.com`, `outlook.office365.com`) |
| `connection.port` | `993` | IMAP port |
| `connection.encryption` | `"ssl"` | `"ssl"` (port 993) or `"tls"` (STARTTLS, port 143) |
| `connection.username` | — | Mailbox username / email address |
| `connection.validate_cert` | `true` | Validate the server's TLS certificate. Set `false` only for self-signed certs in dev |

### Folders

Under `config_json.folders`:

| Key | Default | Description |
|---|---|---|
| `folders.include` | `[]` | Explicit allowlist of folder names to sync. When non-empty, only these folders are synced and `folders.exclude` is ignored |
| `folders.exclude` | `["Trash","Spam","Junk","[Gmail]/Spam","[Gmail]/Trash"]` | Folders to skip when `folders.include` is empty |

### Senders / Recipients

Under `config_json.senders` and `config_json.recipients`:

| Key | Default | Description |
|---|---|---|
| `senders.include` | `[]` | Only ingest messages from these addresses / domains. Empty means no restriction |
| `senders.exclude` | `[]` | Never ingest messages from these addresses / domains |
| `recipients.include` | `[]` | Only ingest messages addressed to these recipients (To + Cc). Empty means no restriction |
| `recipients.exclude` | `[]` | Never ingest messages addressed to these recipients |

Addresses may be full addresses (`alice@example.com`) or bare domains (`example.com`). Domain matching uses `str_ends_with($address, '@'.$needle)`.

### Subject keywords

Under `config_json.subject`:

| Key | Default | Description |
|---|---|---|
| `subject.include_keywords` | `[]` | Only ingest messages whose subject contains at least one of these substrings (case-insensitive). Empty means no restriction |
| `subject.exclude_keywords` | `[]` | Never ingest messages whose subject contains any of these substrings |

### Attachments

Under `config_json.attachments`:

| Key | Default | Description |
|---|---|---|
| `attachments.enabled` | `true` | Master switch for attachment ingestion |
| `attachments.allowed_extensions` | `["pdf","doc","docx","ppt","pptx","xls","xlsx","txt","csv","md","rtf","odt"]` | Extension allowlist (case-insensitive) |
| `attachments.max_size_mb` | `25` | Maximum attachment size in megabytes |
| `attachments.max_per_email` | `20` | Maximum number of attachments ingested per email |
| `attachments.skip_inline` | `true` | Skip inline / embedded attachments (Content-Disposition: inline) |

### Limits

Under `config_json.limits`:

| Key | Default | Description |
|---|---|---|
| `limits.max_messages_per_sync` | `5000` | Hard cap on messages processed in a single sync run |
| `limits.max_message_size_mb` | `50` | Skip individual messages larger than this many megabytes |

## Testing

```bash
composer install
vendor/bin/phpunit --testsuite=Unit,Feature
```

The suite has three test suites:

| Suite | What it covers | Network |
|---|---|---|
| Unit | Pure-PHP logic: `MessageFilter`, `AttachmentPolicy`, `EmailToMarkdown`, `MailboxWalker`, `MailMetadata` | None |
| Feature | `ImapConnector` end-to-end against `FakeImapClient` + `.eml` fixtures: full sync, incremental, attachment ingestion, filter behaviour, health, disconnect, reconcile_deletions | None |
| Live | Opt-in — actually connects to a real IMAP server. Skipped unless `CONNECTOR_IMAP_LIVE=1` | Real |

CI runs Unit + Feature against PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 on every push and pull request.

Static analysis:

```bash
vendor/bin/phpstan analyse --memory-limit=512M
# or via composer script
composer analyse
```

Code style:

```bash
vendor/bin/pint --test   # check only
vendor/bin/pint          # auto-fix
```

## Live testsuite

The live suite is **opt-in** so CI never pays for real IMAP connections. To run it:

```bash
export CONNECTOR_IMAP_LIVE=1
export IMAP_HOST=imap.gmail.com
export IMAP_PORT=993
export IMAP_ENCRYPTION=ssl
export IMAP_USERNAME=support@yourcompany.com
export IMAP_PASSWORD=your-app-password

vendor/bin/phpunit --testsuite=Live
```

The live test calls `ping()` (which sends `CAPABILITY` + `LOGIN`) and `listMailboxes()`, asserting both succeed and the folder list is non-empty. It does not read message content and makes no writes to the mailbox.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| **Login fails on install** | Gmail with 2-Step Verification — direct password not accepted | Create a Gmail App Password (see [Credential setup](#credential-setup-junior-proof-step-by-step)) and use that as the password |
| **Login fails on install** | Outlook / Microsoft 365 — IMAP disabled for the mailbox | Enable IMAP in Exchange admin centre under Recipients → Mailboxes → Email apps |
| **Nothing ingested after first sync** | `date_window_days` is cutting off old messages | Increase `date_window_days` in `config_json` or set to `0` for all history |
| **Nothing ingested after first sync** | Folder name mismatch | List actual folder names via a live test or an IMAP client (e.g. Thunderbird); update `folders.include` to match |
| **Nothing ingested after first sync** | `skip_auto_generated: true` (default) is filtering all messages | Your messages carry `Precedence: bulk`, `Auto-Submitted`, or `List-Unsubscribe` headers. Set `skip_auto_generated: false` to override |
| **Attachments missing from KB** | Extension not in allowlist | Add the extension to `attachments.allowed_extensions` in `config_json` |
| **Attachments missing from KB** | File over 25 MB limit | Raise `attachments.max_size_mb` in `config_json`, or split large files before sending |
| **Gmail folders not found** | Gmail uses `[Gmail]/All Mail`, `[Gmail]/Sent Mail`, etc. | Use the exact folder names shown by your IMAP client. The default `folders_exclude` already excludes `[Gmail]/Spam` and `[Gmail]/Trash`; add `[Gmail]/All Mail` to `folders.exclude` if you want to avoid ingesting all sent/received mail |
| **Deleted emails reappear in RAG** | `reconcile_deletions` is `false` (default) | Set `reconcile_deletions: true` in `config_json`. Note: this is expensive on large mailboxes |
| **PHPStan OOMs locally** | Default memory limit too low | Run `vendor/bin/phpstan analyse --memory-limit=512M` or use `composer analyse` (already set correctly in the script) |
| **XOAUTH2 throws a `ConnectorAuthException`** | Token exchange not yet implemented | Use basic-auth mode (`auth_mode: "basic"`) for now. XOAUTH2 full implementation is on the roadmap |

## License

Apache-2.0 — see [LICENSE](LICENSE).

Built and maintained by [Padosoft](https://padosoft.com/). Part of the AskMyDocs connector ecosystem.
