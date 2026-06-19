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
