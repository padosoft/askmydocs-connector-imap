# Changelog

All notable changes to `padosoft/askmydocs-connector-imap` are documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). This project uses [Semantic Versioning](https://semver.org/).

---

## [1.4.1] — 2026-06-25

### Fixed

- **Rejected IMAP logins now raise `ConnectorAuthException`, not `ConnectorApiException`.**
  `WebklexImapClient::ensure()` previously wrapped every connect/login error as
  `ConnectorApiException`, so a bad-credential folder-discovery attempt looked
  like a transient 503 and the sync job kept retrying. It now catches webklex's
  `AuthFailedException` and raises `ConnectorAuthException` (host prompts
  re-authentication; sync job stops retrying), matching the `SupportsFolderDiscovery`
  contract. Other connect failures stay `ConnectorApiException` (retryable).
- **`connectionSettingsSchema()` defaults now derive from
  `connectors.providers.imap.defaults`** (the same config block `resolveConfig()`
  reads) instead of hardcoded literals. A host that overrides e.g.
  `date_window_days` or `folders_exclude` now sees its configured value in the
  settings editor, so opening/saving settings no longer silently re-imposes the
  package default.

## [1.4.0] — 2026-06-25

### Added

- **Folder discovery** — `ImapConnector` implements `SupportsFolderDiscovery`
  (connector-base ^1.4). `listAvailableFolders()` returns the live mailbox/label
  paths verbatim, reusing the connector's own `makeClient()` so basic AND
  xoauth2 (with token refresh) both work — the host no longer reconstructs the
  IMAP client itself, which also fixes folder discovery for OAuth2 accounts whose
  access token has expired. `close()` runs in a `finally` (safe even when connect
  failed). Failures surface as `ConnectorApiException` (unreachable / transient)
  or `ConnectorAuthException` (credentials rejected), never an empty-but-OK list.
- **Full settings editor** — `ImapConnector` implements `SupportsConnectionSettings`
  (connector-base ^1.4). `connectionSettingsSchema()` declares the **entire
  editable sync surface** as a schema the host renders generically: folder
  include/exclude (live `multiselect` backed by discovery), sync window, scope
  flags (`only_unseen`, `only_flagged`, `reconcile_deletions`), content options
  (`body_format`, `skip_auto_generated`, `strip_quoted_history`, `redact_pii`),
  sender/recipient/subject filters (`tags`), attachment policy, and sync limits.
  Each field's dotted `name` is the `config_json` path the sync engine reads back,
  so a value set in the UI round-trips 1:1. None of these were UI-editable before
  (they were config-file / API-payload only).

### Changed

- **Requires `padosoft/askmydocs-connector-base` `^1.4`** (for the two new
  capability interfaces and the `multiselect` / `tags` `CredentialField` types).

### Compatibility

- Fully backward compatible. The new interfaces are additive and opt-in; the sync
  engine, credential form, and existing `config_json` shape are unchanged. Hosts
  on connector-base < 1.4 simply do not see the new capabilities.

---

## [1.3.0] — 2026-06-22

### Changed

- Adopt connector-base v1.3 **multi-account + project binding**: the ingest project is now resolved via `BaseConnector::resolveProjectKey($installation)` (installation `project_key` → `kb.ingest.default_project` → `'default'`), replacing the per-connector `connector-imap` synthetic-project fallback. Empty `project_key` inherits the tenant default. Requires `padosoft/askmydocs-connector-base` `^1.3`.

## [1.2.0] — 2026-06-19

### Added

- **`SupportsCredentialForm` implementation** — `ImapConnector` now implements `Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm`, advertising its full credential-form schema so AskMyDocs hosts on connector-base ^1.2 can render a native admin form (Mode A) without the optional Blade HTTP layer. The schema covers 8 fields across three groups (Authentication, Server, Credentials) including `auth_mode`/`xoauth2_provider` selects, connection fields (`host`, `port`, `encryption`, `validate_cert`, `username`), and the `password` secret field. Requires `padosoft/askmydocs-connector-base: ^1.2`.

---

## [1.1.0] — 2026-06-19

### Added

- **Real HTML→Markdown via `league/html-to-markdown`** — `EmailToMarkdown::htmlToMarkdown()` now uses `HtmlConverter` instead of `strip_tags`, preserving bold (`**...**`), links (`[text](url)`), and bullet lists from HTML email bodies. A defensive `catch (\Throwable)` falls back to `html_entity_decode(strip_tags(...))` so malformed HTML never breaks ingestion.

- **Full XOAUTH2 token exchange for Gmail and Microsoft 365** — `handleXoauthCallback()` POSTs `authorization_code` to the provider's `token_url` and persists `accessToken`, `refreshToken`, and `expiresAt` to the vault. Providers supported: `google` (`imap.gmail.com`) and `microsoft` (`outlook.office365.com`). Provider selection is per-installation via `config_json.xoauth2_provider` (default `"google"`).

- **Silent XOAUTH2 token refresh** — `refreshTokenIfExpired()` checks vault expiry; when the access token has expired, it POSTs to `token_url` with `grant_type=refresh_token`, rotates the refresh token if the provider returns a new one, persists the result, and emits a `token_refreshed` audit event (no token value in audit metadata).

- **XOAUTH2 revoke on disconnect** — `disconnect()` best-effort POSTs to `revoke_url` (Google: `https://oauth2.googleapis.com/revoke`; Microsoft: no standard endpoint, `revoke_url` is `null`). Revoke failure never blocks the local credential clear.

- **`makeClient()` uses fresh access token for XOAUTH2** — calls `refreshTokenIfExpired()` as the IMAP SASL secret instead of reading the raw stored token, ensuring the IMAP connection always uses a valid token.

- **`scopes` key in provider config** — `config/imap.php` now declares `scopes` per provider: `https://mail.google.com/` for Google; `https://outlook.office.com/IMAP.AccessAsUser.All offline_access openid email` for Microsoft. The authorize URL includes these scopes automatically.

- **`revoke_url` key in provider config** — Google: `https://oauth2.googleapis.com/revoke`; Microsoft: `null`.

- **Optional publishable HTTP layer** — `ImapConnectorController`, `routes/imap.php`, and `resources/views/credentials.blade.php` ship as an opt-in, publishable HTTP layer (publish tag `connector-imap-http`). Disabled by default (`CONNECTOR_IMAP_ROUTES_ENABLED=false`). When enabled, registers three routes:
  - `GET  {prefix}/{installation}/credentials` — credential form (basic-auth) or OAuth redirect (xoauth2)
  - `POST {prefix}/{installation}/credentials` — store basic-auth credentials
  - `GET  {prefix}/{installation}/oauth/callback` — handle OAuth provider redirect

- **`routes` block in `config/imap.php`** — `enabled` (default `false`), `prefix` (`admin/connectors/imap`), `middleware` (default `['web', 'auth']`; operators must append an admin authorization gate).

- **Tenant-scoped installation lookup in HTTP controller** — `findInstallation()` scopes by `tenant_id` (from `TenantContext`) in addition to `id` + `connector_name`, preventing IDOR.

- **Session-bound OAuth state** — the HTTP controller stores the state in the authenticated user's session and consumes it atomically (`session()->pull()`) before calling the connector, preventing CSRF and replay on both the basic credential form and the XOAUTH2 callback.

- **Feature tests for XOAUTH2** — `ImapXoauthTest` covers: authorize URL with scopes + state; code→token exchange + vault storage; token refresh rotation; Google revoke on disconnect. All tests use `Http::fake()`.

- **Feature tests for HTTP layer** — `ImapHttpTest` covers: credential form renders (basic); credential POST stores password; routes absent when disabled.

### Changed

- `xoauthAuthorizeUrl()` now reads `scope` from `config('connectors.providers.imap.xoauth2.<provider>.scopes')` instead of an inline hardcoded string. Removed the `// TODO` comment that previously marked this as a stub.
- `handleOAuthCallback()` XOAUTH2 branch now calls `handleXoauthCallback()` successfully instead of throwing a "not implemented" exception.
- Basic-auth behaviour is **unchanged** — `handleOAuthCallback()` basic branch, `makeClient()` basic path, credential form URL generation, and all v1.0.0 tests are unaffected.

### Dependencies

- Added `league/html-to-markdown: ^5.1`.

---

## [1.0.0] — 2026-05-01

Initial release.

### Added

- `ImapConnector` implementing `ConnectorInterface` from `padosoft/askmydocs-connector-base`.
- Basic-auth (password / app-password) with live `ping()` verification before credential storage.
- XOAUTH2 authorize URL generation and CSRF state-token round-trip (token exchange deferred to v1.1.0).
- `EmailToMarkdown` — structured header block (From / To / Cc / Date / Folder / Message-ID / Attachments) + body rendered as markdown. HTML bodies fell back to `strip_tags` in this version.
- `AttachmentPolicy` — extension allowlist + size cap (25 MB) + inline skip + per-email limit (20).
- `MessageFilter` — 12 independent filter axes: sender/recipient include/exclude, subject keywords, date window, only_unseen, only_flagged, skip_auto_generated, strip_quoted_history.
- `MailboxWalker` — folder selection (include/exclude), UIDVALIDITY + UID watermark, date window bounds.
- `MailMetadata` — metadata builder including `imap_doc_key` composite key (`mailbox:uidvalidity:uid`).
- Full sync + incremental sync (UIDVALIDITY + UID watermark per mailbox in `extra_json.mailboxes_state`).
- Optional deletion reconciliation (`reconcile_deletions`) using the composite `imap_doc_key`.
- `ImapClientInterface` + `ImapClientFactory` wrapping `webklex/php-imap` v6 (PHP 8.4+ compatible — no `ext-imap`).
- `FakeImapClient` + `.eml` fixtures for deterministic unit and feature tests.
- Unit suite: `MessageFilter`, `AttachmentPolicy`, `EmailToMarkdown`, `MailboxWalker`, `MailMetadata`.
- Feature suite: full and incremental sync, attachment ingestion, filters, health, disconnect, reconcile_deletions.
- Live suite: opt-in with `CONNECTOR_IMAP_LIVE=1`.
- PHPStan level 8 + Pint + CI matrix PHP 8.3/8.4/8.5 × Laravel 12/13.
- Composer-extra auto-registration (`extra.askmydocs.connectors`).
- Publish tags: `connector-imap-config`, `connector-imap-assets`.

[1.1.0]: https://github.com/padosoft/askmydocs-connector-imap/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/padosoft/askmydocs-connector-imap/releases/tag/v1.0.0
