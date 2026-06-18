# askmydocs-connector-imap — Design Spec

- **Date**: 2026-06-18
- **Package**: `padosoft/askmydocs-connector-imap`
- **Namespace**: `Padosoft\AskMyDocsConnectorImap\`
- **Connector FQCN**: `Padosoft\AskMyDocsConnectorImap\ImapConnector`
- **Base**: `padosoft/askmydocs-connector-base` (^1.1)
- **Status**: approved (design phase)

## 1. Purpose & scope

An AskMyDocs connector that ingests **email over IMAP** (e.g. a customer-service
mailbox) as RAG knowledge. It captures the **maximum useful data**: body
(text/html rendered to markdown), sender (name + address), recipients (To/Cc),
relevant headers, subject, dates, flags/labels, and **document attachments** as
separate KB documents. It is the **first credential-based** connector in the
family (all existing connectors are OAuth-redirect).

### In scope (v1)
- Basic-auth IMAP (host / port / encryption / username / password or app-password) — primary.
- XOAUTH2 (Gmail / Microsoft 365) **design-ready**, enabled via config (live path is basic-auth).
- Email → markdown document (one document per email).
- Document attachments → separate KB documents, with allowlist + size cap.
- Rich per-installation filters (folders, senders, recipients, date window, subject, auto-generated, etc.).
- Incremental sync via UIDVALIDITY + UID watermark; optional deletion reconciliation (off by default).

### Out of scope (v1)
- Sending email / SMTP (read-only connector).
- Writing to the IMAP server (no flag/move/delete on the server).
- POP3.
- Thread-as-single-document (each email = one document; thread linked via `References`).
- OCR of image attachments.

## 2. Constraints (from `askmydocs-connector-base`)

- Implement the **10-method `ConnectorInterface`** by extending `BaseConnector`.
- Auto-discovery via `composer.json` → `extra.askmydocs.connectors` + Laravel provider.
- Credentials live in `OAuthCredentialVault` (AES-256, `extra_json`, atomic `setExtraKey`).
- Ingestion via `dispatchIngestion(projectKey, relativePath, disk, title, metadata, mimeType, tenantId)`.
- Metadata via `SourceAwareMetadataBuilder->build(base, sourceKey, sourceFields, tags, statusActive, lastModified, owner)`.
- Soft delete via `softDeleteByMetadataKey(installation, metadataKey, remoteId)`.
- Incremental watermark stored in vault `extra_json`.
- Per-installation config in `connector_installations.config_json` (host-managed).
- Exceptions: `ConnectorAuthException` (no retry), `ConnectorApiException` (retry backoff), `ConnectorPaginationLimitException` (partial sync).
- Tenant isolation (R30/R31) handled by the base models/job.

## 3. Dependencies

- `padosoft/askmydocs-connector-base: ^1.1`
- `webklex/php-imap: ^6.0` — pure-PHP IMAP client (no `ext-imap`; the PHP imap
  extension is unbundled from core in PHP 8.4+). Handles folders, UID/UIDVALIDITY,
  MIME decoding, attachments, headers, SEARCH, and SASL XOAUTH2.
- Dev: phpunit ^11|^12, orchestra/testbench ^10|^11, mockery, phpstan ^2, laravel/pint.
- Matrix: PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13.

## 4. Package layout

```
askmydocs-connector-imap/
├── composer.json
├── config/imap.php
├── src/
│   ├── ImapConnector.php
│   ├── ImapServiceProvider.php
│   ├── Imap/
│   │   ├── ImapClientFactory.php   # interface + webklex impl (basic | xoauth2)
│   │   ├── MailboxWalker.php        # folder selection, SEARCH, lazy UID batches, watermark
│   │   ├── MessageFilter.php        # applies config_json filters
│   │   ├── EmailToMarkdown.php      # header block + body (text | html→md), quoted trim
│   │   └── AttachmentPolicy.php     # allowlist mime/ext, max size MB, max per email, skip inline
│   └── Support/MailMetadata.php     # SourceAwareMetadataBuilder wiring
├── public/icons/imap.svg
├── tests/{Unit,Feature,Live,fixtures}/
├── docs/{LESSON.md,PROGRESS.md}
├── .github/workflows/tests.yml
├── pint.json
├── phpstan.neon.dist
└── README.md
```

## 5. Authentication model (dual, inside the OAuth contract)

Mode selected per installation: `config_json.auth_mode` = `basic` (default) | `xoauth2`.

### basic (default — any IMAP server)
- `initiateOAuth(int): string` → returns the URL of the AskMyDocs admin **IMAP
  credential form** for this installation, carrying a CSRF `state`
  (`$this->issueOAuthState()`).
- `handleOAuthCallback(int, Request): void` → validates `state`, performs a real
  IMAP login test, then persists:
  - **password → vault** (`accessToken`, `expiresAt = null`, no refresh token).
  - **host / port / encryption / username / validate_cert → `config_json`**
    (non-secret; managed by the form). Encryption ∈ {ssl, tls, starttls, none}.

### xoauth2 (Gmail / Microsoft 365 — design-ready)
- `initiateOAuth()` → provider authorize URL (client creds from `config/imap.php`).
- `handleOAuthCallback()` → exchange code → tokens; store access + refresh + expiry in vault.
- `refreshTokenIfExpired(int): ?string` → rotate via provider token endpoint.
- IMAP login uses SASL **XOAUTH2** with the access token + configured username/host
  (imap.gmail.com / outlook.office365.com).

### lifecycle
- `health(int): HealthStatus` → connect + login + NOOP, < 2s, returns `healthy()`/`errored()`.
- `disconnect(int): void` → `vault->clearCredentials()` (+ token revoke if xoauth2).

## 6. Ingested email document

One markdown document per email:
`<project>/connectors/imap/installation-<id>/<mailbox-slug>/<uid>.md`,
`mimeType: text/markdown`.

```markdown
# {Subject}

| Field | Value |
|------|--------|
| From | Mario Rossi <mario@acme.com> |
| To   | support@us.com |
| Cc   | … |
| Date | 2026-06-15 14:30 |
| Folder | INBOX |
| Message-ID | <…> |
| Attachments | fattura.pdf, contratto.docx |

---
{body: text/plain preferred, else html→markdown; quoted history trimmable}
```

**Metadata** (`SourceAwareMetadataBuilder`, `sourceKey: 'imap'`), `sourceFields`:
`mailbox, uid, uidvalidity, message_id, in_reply_to, references, from_name,
from_email, to[], cc[], date, subject, flags, labels, has_attachments,
attachment_count, size_bytes`. Derived: `owner = from_email`,
`tags = labels + folder`, `statusActive = true`, `lastModified = date`.

## 7. Attachments

Document attachments ingested as **separate KB documents** linked to the email
(`message_id` / `uid` / `mailbox` in metadata), title = filename, native mime →
host extractor/chunker processes them. Path:
`<project>/connectors/imap/installation-<id>/<mailbox-slug>/<uid>/<filename>`.

Config (defaults):
- `attachments.enabled` (true)
- `attachments.allowed_extensions`: pdf, doc, docx, ppt, pptx, xls, xlsx, txt, csv, md, rtf, odt
- `attachments.max_size_mb` (25) — **excludes attachments larger than X MB**
- `attachments.max_per_email` (20)
- `attachments.skip_inline` (true)
- Excluded by default: images, archives (zip/rar/7z), executables.

## 8. Filters (all in `config_json`)

- `folders.include` ([] = all), `folders.exclude` (default: Trash, Spam, Junk, [Gmail]/Spam, [Gmail]/Trash)
- `senders.include` / `senders.exclude` (address or domain)
- `recipients.include` / `recipients.exclude`
- `date_window_days` (365; 0 = unlimited → IMAP `SINCE`)
- `only_unseen` (false), `only_flagged` (false)
- `subject.include_keywords` / `subject.exclude_keywords`
- `skip_auto_generated` (true → filter `Auto-Submitted`, `Precedence: bulk/list`, `List-Unsubscribe`)
- `strip_quoted_history` (false)
- `body_format` (prefer_text | prefer_html)
- `limits.max_messages_per_sync` (cap → `ConnectorPaginationLimitException`)
- `limits.max_message_size_mb`
- `redact_pii` (inherits `maybeRedactContent`)

## 9. Incremental sync & deletions

- **Watermark** in `vault.extra_json.mailboxes_state`:
  `{ "INBOX": {uidvalidity, last_uid}, … }`.
- Incremental per selected folder: if `UIDVALIDITY` unchanged →
  `SEARCH UID (last_uid+1):*` (plus `SINCE $since` guard); if changed/unknown →
  folder rescan (bounded by date window). Lazy UID batches (memory-safe, like
  `NotionPaginator`). Update `last_uid` to max seen.
- `syncIncremental(id, null)` falls back to `syncFull`.
- **Deletions**: append-mostly. Toggle `reconcile_deletions` (default **off**):
  compares current UID set vs ingested docs and soft-deletes the missing ones via
  `softDeleteByMetadataKey('imap_uid', …)`. ⚠️ Bulk reconciliation may require a
  small helper in the `base`/host package; if so, implement it in core **first**,
  then complete here (admin→core backfill policy).

## 10. Errors & robustness

- `ConnectorAuthException` (login/credential failure) → no retry, installation errored.
- `ConnectorApiException` (timeout / socket / transient) → retry backoff [60,300,900].
- `ConnectorPaginationLimitException` (message cap) → partial `SyncResult` + `errors[]`.
- Per-email try/catch: a broken message never aborts the whole sync.
- IMAP connection closed in `finally`.

## 11. Testing

- `ImapClientFactory` behind an interface → tests inject a **fake** with parsed
  messages from **.eml fixtures** (zero network, CI-safe).
- `Unit`: EmailToMarkdown, AttachmentPolicy, MessageFilter, watermark logic.
- `Feature`: the 10 methods against the fake + `SpyIngestionContract`.
- `Live` (opt-in, `CONNECTOR_IMAP_LIVE=1`, real creds via env, skipped in CI).
- Pint + PHPStan level 8; GitHub Actions matrix 8.3/8.4/8.5 × L12/13.

## 12. Config reference — `config/imap.php`

```php
return [
    // XOAUTH2 provider credentials (only used when auth_mode = xoauth2)
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

    // URL of the admin IMAP credential form (basic auth mode)
    'credential_form_url' => env(
        'CONNECTOR_IMAP_CREDENTIAL_FORM_URL',
        env('APP_URL', 'http://localhost').'/admin/connectors/imap/credentials'
    ),

    // Defaults applied when an installation omits a knob
    'defaults' => [
        'date_window_days' => 365,
        'folders_exclude' => ['Trash', 'Spam', 'Junk', '[Gmail]/Spam', '[Gmail]/Trash'],
        'skip_auto_generated' => true,
        'body_format' => 'prefer_text',
        'attachments' => [
            'enabled' => true,
            'allowed_extensions' => ['pdf','doc','docx','ppt','pptx','xls','xlsx','txt','csv','md','rtf','odt'],
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

## 13. README sections (house style)

Why · Features · Architecture · Installation · Credential setup (junior-proof:
basic + app-password Gmail/O365 + XOAUTH2) · Activation inside AskMyDocs · What
gets ingested (email + attachments table) · Sync semantics · Config reference ·
Testing · Troubleshooting · License (Apache-2.0).
