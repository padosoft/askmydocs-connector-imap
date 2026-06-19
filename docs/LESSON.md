# LESSON — askmydocs-connector-imap

- The base contract is OAuth-centric; IMAP reuses initiateOAuth/handleOAuthCallback
  as a *credential acquisition* seam (basic-auth posts host/port/user to the
  callback; password → vault, connection params → config_json).

- webklex/php-imap chosen over ext-imap (unbundled in PHP 8.4+). All webklex calls
  are isolated behind ImapClientInterface so the rest of the package is testable
  with a FakeImapClient and .eml fixtures.

- Incremental sync keys off UIDVALIDITY + UID watermark per mailbox in vault
  extra_json; UIDVALIDITY change forces a window-bounded rescan.

- Provider defaults (`connectors.providers.imap.defaults`) are merged into every
  installation's config at sync time, so new installs need zero explicit config
  for most deployments (attachments enabled, folders_exclude set, body_format
  prefer_text, date_window 365 days, max_messages 5000).

- PHPStan requires `--memory-limit=512M`; without it the analysis OOMs on the
  webklex dependency tree. Add it to every CI step and to the composer `analyse`
  script so no developer hits this wall by accident.

## v1.1.0 lessons

- **XOAUTH2 SASL via webklex `authentication: 'oauth'`** — the webklex factory
  receives `auth_mode` from the connector and sets `authentication: 'oauth'` on the
  client config. The IMAP SASL secret is the access token (passed as the "password"
  to `makeClientWithPassword`). `makeClient()` must call `refreshTokenIfExpired()`
  to always pass a valid token; reading the raw stored value risks using an expired
  one. For xoauth2, `config_json.connection.username` MUST be the mailbox address —
  it is not derivable from the OAuth token response and the IMAP server requires it.

- **Opt-in publishable routes must ship auth + tenant-scope by default** — a
  package that ships HTTP routes for credential management must enforce (a) at
  minimum `auth` middleware so unauthenticated requests are rejected, and (b)
  tenant-scoped installation lookups to prevent IDOR. The host MUST add its own
  admin authorization gate — the package cannot safely hardcode a gate name because
  the host defines its own role model. Document this requirement prominently.
  Session-bound OAuth state (stored + consumed atomically via `session()->pull()`)
  is a second layer against CSRF and replay on both credential-form POST and OAuth
  callback routes.

- **`league/html-to-markdown` for email fidelity** — `strip_tags` destroys semantic
  structure (bold, links, lists) that is often meaningful in support email bodies.
  `HtmlConverter` with `strip_tags: true` + `remove_nodes: 'script style head'` +
  `hard_break: true` preserves structure while sanitising unsafe content. Wrap in a
  `catch (\Throwable)` so malformed HTML (common in older Outlook messages) never
  breaks ingestion — fall back to `html_entity_decode(strip_tags(...))` silently.

- **No secret in audit metadata** — `emitAudit('installed', ...)` and
  `emitAudit('token_refreshed', ...)` must never include `access_token`,
  `refresh_token`, or `client_secret` in the metadata array. Include only
  `auth_mode` and `provider`. The same rule applies to exception messages and
  `SyncResult.errors` strings — log the HTTP status code, never the token value.
