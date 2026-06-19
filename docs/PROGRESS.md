# PROGRESS — askmydocs-connector-imap

## v1.0.0 — Initial release

- [x] Scaffold + base wiring
- [x] EmailToMarkdown, AttachmentPolicy, MessageFilter, MailboxWalker, MailMetadata
- [x] Config + ServiceProvider
- [x] Connector: identity, basic-auth, health, disconnect
- [x] webklex client factory
- [x] Sync full/incremental + attachments + watermark
- [x] Optional deletion reconciliation
- [x] CI + Live suite + README v1.0

## v1.1.0 — Complete follow-ups

- [x] Real HTML→Markdown via `league/html-to-markdown` (bold, links, lists preserved; defensive fallback)
- [x] XOAUTH2 full token exchange — Google Gmail (code→token, refresh, revoke on disconnect)
- [x] XOAUTH2 full token exchange — Microsoft 365 (code→token, refresh; no standard revoke)
- [x] `makeClient()` uses `refreshTokenIfExpired()` as SASL secret for XOAUTH2
- [x] `scopes` + `revoke_url` added to provider config blocks
- [x] Optional publishable HTTP layer (ImapConnectorController + routes/imap.php + credentials.blade.php)
- [x] `routes` config block (`enabled`, `prefix`, `middleware`); default OFF
- [x] Tenant-scoped installation lookup in HTTP controller (IDOR-safe)
- [x] Session-bound OAuth state in HTTP controller (CSRF + replay protection)
- [x] Feature tests: ImapXoauthTest (Http::fake()) + ImapHttpTest
- [x] Flagship README v1.1.0 (XOAUTH2, HTTP layer, security notes, config recipes)
- [x] CHANGELOG.md (Keep a Changelog style)
