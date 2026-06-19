<h1 align="center">askmydocs-connector-imap</h1>

<p align="center">
  <strong>IMAP email connector for AskMyDocs — real HTML→Markdown, full XOAUTH2 (Gmail + M365), optional publishable HTTP layer, dual auth, document attachments, rich filters, incremental UID watermark.</strong><br/>
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
5. [Quick start](#quick-start)
6. [Credential setup (junior-proof, step by step)](#credential-setup-junior-proof-step-by-step)
7. [Integration modes](#integration-modes)
8. [What gets ingested](#what-gets-ingested)
9. [Sync semantics](#sync-semantics)
10. [Configuration reference](#configuration-reference)
11. [Config recipes](#config-recipes)
12. [Security notes](#security-notes)
13. [Testing](#testing)
14. [Live testsuite](#live-testsuite)
15. [Troubleshooting](#troubleshooting)
16. [Roadmap](#roadmap)
17. [License](#license)

---

## Why this package

[AskMyDocs](https://github.com/lopadova/AskMyDocs) is an enterprise-grade RAG + canonical knowledge compilation system. Out of the box it ingests markdown from disk, the chat UI, an HTTP API, and a Git-driven workflow — but the richest operational knowledge in most organisations flows through email: customer-service inboxes, support queues, shared mailboxes, internal mailing lists.

This package is the smallest possible surface for shipping that integration:

- An `ImapConnector` that implements `Padosoft\AskMyDocsConnectorBase\ConnectorInterface`.
- `EmailToMarkdown` that renders the full header block (From / To / Cc / Date / Folder / Message-ID / Attachments) plus text or HTML body into clean markdown — using `league/html-to-markdown` so bold, links, and lists survive the conversion.
- `AttachmentPolicy` + per-email ingestion that turns every qualifying document attachment into its own KB doc (separate chunking + retrieval).
- `MessageFilter` with twelve independent filter axes (sender, recipient, subject keywords, date window, flagged, unseen, auto-generated, folder include/exclude).
- Incremental sync via UIDVALIDITY + UID watermark per mailbox, persisted in the vault's encrypted `extra_json`.
- Optional deletion reconciliation (`reconcile_deletions`) that soft-deletes KB docs whose source UIDs have vanished from the mailbox.
- Full XOAUTH2 for Gmail and Microsoft 365 — code→token exchange, silent token refresh, best-effort revoke on disconnect.
- An optional, publishable HTTP layer (controller + routes + credential form) so hosts can flip one config flag instead of writing their own admin wiring.
- A `composer.json` that auto-registers via `extra.askmydocs.connectors`. Zero edits to host app config required.

> **`composer require padosoft/askmydocs-connector-imap`. Done.**

## Features

- **Zero-config installation** — composer-extra discovery auto-registers the connector at boot.
- **Dual authentication** — basic-auth (password / app-password) and full XOAUTH2 (Gmail + Microsoft 365) with code→token exchange, silent access-token refresh, and best-effort revoke.
- **Real HTML→Markdown** — `league/html-to-markdown` converts HTML bodies preserving bold (`**...**`), links (`[text](url)`), and bullet lists. Unknown or unsafe tags are stripped. A defensive fallback to `strip_tags` protects against malformed HTML.
- **Document attachments as separate KB docs** — each qualifying attachment is stored independently so the chunker and retriever treat it as a first-class document.
- **Allowlist + size cap** — attachments accepted only when extension is in the allowlist (pdf, doc, docx, ppt, pptx, xls, xlsx, txt, csv, md, rtf, odt by default) and size is under 25 MB. Inline/embedded images are skipped.
- **Rich filters** — 12 orthogonal knobs: sender include/exclude, recipient include/exclude, subject keyword include/exclude, date window (default 365 days), only_unseen, only_flagged, skip_auto_generated (Precedence: bulk/list + Auto-Submitted + List-Unsubscribe headers), strip_quoted_history.
- **Incremental UID watermark** — tracks `uidvalidity + last_uid` per mailbox in the vault. UIDVALIDITY change forces a window-bounded rescan; normal incremental runs only fetch new UIDs.
- **Optional deletion reconciliation** — `reconcile_deletions: true` computes vanished UIDs and calls `softDeleteByRemoteId` on the host's ingestion contract.
- **Optional publishable HTTP layer** — set `CONNECTOR_IMAP_ROUTES_ENABLED=true` and the package registers credential form + OAuth callback routes under `admin/connectors/imap`. Default OFF; fully publishable for host customisation.
- **Provider defaults** — `connectors.providers.imap.defaults` supplies safe defaults; per-installation `config_json` overrides only what differs.
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
                │ base v1.2.0+                 │
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
                │    (league/html-to-markdown)│
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
# Optional — publishes config/connector-imap.php for env-var overrides
php artisan vendor:publish --tag=connector-imap-config

# Optional — copies imap.svg to public/connectors/
php artisan vendor:publish --tag=connector-imap-assets

# Optional — publishes the HTTP layer (controller + routes + Blade view) for customisation
php artisan vendor:publish --tag=connector-imap-http
```

The `connector-base` migrations ship in the parent package (`padosoft/askmydocs-connector-base`) and auto-load via its service provider; no extra `migrate` step is needed.

## Quick start

### Basic-auth in ~10 lines

Set these values in the installation's `config_json` via the host admin UI or via code:

```json
{
  "auth_mode": "basic",
  "project_key": "support-inbox",
  "connection": {
    "host": "imap.gmail.com",
    "port": 993,
    "encryption": "ssl",
    "username": "support@yourcompany.com"
  },
  "date_window_days": 90,
  "folders": { "include": ["INBOX"] }
}
```

Then submit the password via the credential form (or the host's admin UI). The connector verifies the credentials with a live IMAP `ping()` before storing anything. On success the first sync fires within the cadence window (default 15 min).

### XOAUTH2 (Gmail) in 5 steps

1. Create a GCP OAuth client and configure your `.env` (see [Gmail XOAUTH2 setup](#gmail-xoauth2)).
2. Set `CONNECTOR_IMAP_ROUTES_ENABLED=true` in `.env` (or wire the routes yourself — see [Integration modes](#integration-modes)).
3. Set `config_json.auth_mode = "xoauth2"` and `config_json.xoauth2_provider = "google"` for the installation.
4. Make sure `config_json.connection.username` is the mailbox email address (the connector cannot infer it from the token response).
5. Navigate to the credential form → you are redirected to Google → grant IMAP access → return to the admin UI.

Token refresh and best-effort revoke on disconnect are handled automatically.

## Credential setup (junior-proof, step by step)

### Generic IMAP (Postfix, Dovecot, custom servers)

Use your server's IMAP hostname, port 993 (SSL) or 143 (STARTTLS), and the mailbox account credentials. The connector sends `CAPABILITY` + `LOGIN` on connect; any standard IMAP4rev1 server works.

Connection values to set in `config_json.connection`:

| Field | Example value |
|---|---|
| `host` | `mail.yourcompany.com` |
| `port` | `993` |
| `encryption` | `ssl` |
| `username` | `support@yourcompany.com` |

---

### Gmail — App Password (basic-auth)

Google disables direct password login by default on accounts with 2-Step Verification enabled (which is the right choice for production). You must create an **App Password**:

1. Open <https://myaccount.google.com/security> and sign in with the mailbox account.
2. Under **"How you sign in to Google"**, click **"2-Step Verification"** and make sure it is enabled. (App Passwords are only available when 2SV is on.)
3. Go back to <https://myaccount.google.com/security> and click **"App passwords"** (appears only when 2SV is active). If you don't see it, search "App passwords" in the Google Account search box.
4. In the **"Select app"** dropdown, choose **"Mail"**. In **"Select device"**, choose **"Other (Custom name)"** and type `AskMyDocs`.
5. Click **"Generate"**. Google shows a 16-character password (e.g. `abcd efgh ijkl mnop`). Copy it immediately — it is shown only once.
6. Use these connection values:
   - **Host**: `imap.gmail.com`
   - **Port**: `993`
   - **Encryption**: `ssl`
   - **Username**: your Gmail address (e.g. `support@yourcompany.com`)
   - **Password**: the 16-character App Password (no spaces)

---

### Gmail XOAUTH2

XOAUTH2 is preferred for production Google accounts — it does not require enabling "less secure app access" or managing app passwords, and access can be revoked per-client from the Google Account page.

#### 1. Create the GCP project

1. Open <https://console.cloud.google.com/> and sign in.
2. Click the **project selector dropdown** → **"NEW PROJECT"**. Name it `askmydocs-prod`. Click **"CREATE"**.

#### 2. Enable the Gmail API

1. Left sidebar → **"APIs & Services"** → **"Library"**.
2. Search `Gmail API`, click it, click **"ENABLE"**.

#### 3. Configure the OAuth consent screen

1. Left sidebar → **"APIs & Services"** → **"OAuth consent screen"**.
2. **User Type**: **External**. Click **"CREATE"**.
3. Fill in App name (`AskMyDocs`), support email, developer contact. Click **"SAVE AND CONTINUE"**.
4. **Scopes**: click **"ADD OR REMOVE SCOPES"**, search and tick `https://mail.google.com/` (full Gmail IMAP access). Click **"UPDATE"** then **"SAVE AND CONTINUE"**.
5. **Test users**: add the email of every account that will be connected. Click **"SAVE AND CONTINUE"**.

#### 4. Create the OAuth client credentials

1. Left sidebar → **"APIs & Services"** → **"Credentials"**.
2. **"+ CREATE CREDENTIALS"** → **"OAuth client ID"**.
3. **Application type**: **Web application**. Name: `AskMyDocs IMAP`.
4. **Authorized redirect URIs**: add your callback URL. If using the package's built-in HTTP layer:
   ```
   https://your-app.example.com/admin/connectors/imap/{installation}/oauth/callback
   ```
   The `{installation}` segment is the integer installation ID — use a wildcard pattern or add all expected IDs.
5. Click **"CREATE"**. Copy the **Client ID** and **Client secret**.

#### 5. Write credentials to `.env`

```dotenv
CONNECTOR_IMAP_GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
CONNECTOR_IMAP_GOOGLE_CLIENT_SECRET=your-client-secret
CONNECTOR_IMAP_GOOGLE_REDIRECT_URI=https://your-app.example.com/admin/connectors/imap/{installation}/oauth/callback
```

> **Important:** `config_json.connection.username` MUST be the mailbox email address (e.g. `support@yourcompany.com`). The connector cannot infer it from the token response — it is not included in the standard IMAP XOAUTH2 exchange.

---

### Microsoft 365 XOAUTH2

#### 1. Register an Entra app

1. Open <https://portal.azure.com/> → **"Microsoft Entra ID"** → **"App registrations"**.
2. Click **"+ New registration"**.
   - **Name**: `AskMyDocs IMAP Connector`
   - **Supported account types**: "Accounts in this organizational directory only" (single-tenant) or "Accounts in any organizational directory" (multi-tenant, pick what matches your deployment).
   - **Redirect URI**: select **Web** and enter:
     ```
     https://your-app.example.com/admin/connectors/imap/{installation}/oauth/callback
     ```
3. Click **"Register"**. Copy the **Application (client) ID**.

#### 2. Add a client secret

1. Left sidebar → **"Certificates & secrets"** → **"+ New client secret"**.
2. Set a description and expiry. Click **"Add"**. Copy the **Value** immediately — it is shown only once.

#### 3. Add API permissions

1. Left sidebar → **"API permissions"** → **"+ Add a permission"** → **"Microsoft Graph"** is NOT what you need here — scroll to **"APIs my organization uses"** or use **"Supported legacy APIs"** → **"Office 365 Exchange Online"**.
2. Select **"Delegated permissions"** and tick:
   - `IMAP.AccessAsUser.All`
   - `offline_access`
   - `openid`
   - `email`
3. Click **"Add permissions"**.
4. Click **"Grant admin consent for [your organisation]"** and confirm.

#### 4. Write credentials to `.env`

```dotenv
CONNECTOR_IMAP_MS_CLIENT_ID=your-azure-app-id
CONNECTOR_IMAP_MS_CLIENT_SECRET=your-client-secret
CONNECTOR_IMAP_MS_REDIRECT_URI=https://your-app.example.com/admin/connectors/imap/{installation}/oauth/callback
```

> **Important:** `config_json.connection.username` MUST be the mailbox UPN (e.g. `support@yourcompany.onmicrosoft.com`). Also ensure IMAP is enabled for the mailbox in the Exchange admin centre (Recipients → Mailboxes → Email apps → IMAP: Enabled).

---

### Outlook / Microsoft 365 — App Password (basic-auth)

If IMAP basic-auth is allowed by your M365 tenant policy:

1. In the Microsoft 365 admin centre → **Users → Active users** → open the mailbox.
2. Under the **"Mail"** tab confirm IMAP is shown as enabled.
3. In the Exchange admin centre → **Recipients → Mailboxes** → open the mailbox → **"Email apps"** → confirm **"IMAP"** is on.
4. Connection values:
   - **Host**: `outlook.office365.com`
   - **Port**: `993`
   - **Encryption**: `ssl`
   - **Username**: the mailbox address or service account UPN
   - **Password**: the account password

---

### Provider-level env vars summary

```dotenv
# Credential form URL (where basic-auth credentials are entered in the UI)
# Default: APP_URL + '/admin/connectors/imap/credentials'
CONNECTOR_IMAP_CREDENTIAL_FORM_URL=https://your-app.example.com/admin/connectors/imap/credentials

# Optional HTTP layer on/off (default: false)
CONNECTOR_IMAP_ROUTES_ENABLED=true

# XOAUTH2 — Google Gmail
CONNECTOR_IMAP_GOOGLE_CLIENT_ID=
CONNECTOR_IMAP_GOOGLE_CLIENT_SECRET=
CONNECTOR_IMAP_GOOGLE_REDIRECT_URI=

# XOAUTH2 — Microsoft 365
CONNECTOR_IMAP_MS_CLIENT_ID=
CONNECTOR_IMAP_MS_CLIENT_SECRET=
CONNECTOR_IMAP_MS_REDIRECT_URI=
```

## Integration modes

The package supports two integration modes for credential collection. Choose the one that fits your host app's admin architecture.

> **Native form rendering (connector-base ^1.2+):** `ImapConnector` implements `Padosoft\AskMyDocsConnectorBase\Contracts\SupportsCredentialForm`. On hosts running connector-base ^1.2, the AskMyDocs admin UI detects this interface at install time, calls `credentialFormSchema()`, and renders a native credential form — covering `auth_mode`, `xoauth2_provider`, `host`, `port`, `encryption`, `validate_cert`, `username`, and `password` across three logical groups. This is the recommended path for Mode A hosts on base ^1.2: no Blade layer publication required.

---

### Mode A: Use the package's optional HTTP layer (recommended for most hosts)

The package ships a ready-made controller + routes + Blade form that handle both basic-auth credential collection and XOAUTH2 redirect/callback. This is the fastest path.

**Step 1: enable the routes**

In `.env`:

```dotenv
CONNECTOR_IMAP_ROUTES_ENABLED=true
```

Routes are registered under `admin/connectors/imap` by default with middleware `['web', 'auth']`.

**Step 2: add your admin authorization gate (REQUIRED)**

The default middleware `['web', 'auth']` only verifies that the user is authenticated. It does **not** check whether they have admin privileges. You MUST append your own authorization middleware so that ordinary authenticated users cannot access or modify connector credentials for any tenant.

Publish the config and add your gate:

```bash
php artisan vendor:publish --tag=connector-imap-config
```

In `config/connector-imap.php`:

```php
'routes' => [
    'enabled' => env('CONNECTOR_IMAP_ROUTES_ENABLED', false),
    'prefix'  => 'admin/connectors/imap',
    // Add your own admin authorization gate here:
    'middleware' => ['web', 'auth', 'can:manage-connectors'],
],
```

Why this is required: the routes accept an `{installation}` integer ID. Without an admin gate, any authenticated user who guesses an installation ID can view or overwrite credentials belonging to another tenant. The controller already scopes installations by `tenant_id` (IDOR safety), but authorization — "is this user allowed to manage connectors at all?" — is the host's responsibility, not this package's.

**Step 3 (optional): publish and customise the view/controller**

```bash
php artisan vendor:publish --tag=connector-imap-http
```

This copies `ImapConnectorController.php` and `credentials.blade.php` to your app. Edit freely — the connector logic stays in the package regardless.

**Routes registered when enabled:**

| Method | URI | Handler | Purpose |
|---|---|---|---|
| `GET` | `{prefix}/{installation}/credentials` | `form` | Render basic-auth form OR redirect to OAuth provider |
| `POST` | `{prefix}/{installation}/credentials` | `store` | Store basic-auth credentials |
| `GET` | `{prefix}/{installation}/oauth/callback` | `callback` | Handle OAuth provider redirect (XOAUTH2) |

---

### Mode B: Wire it yourself in the host

If your host app already has an admin panel and you want full control over the routes and views, skip the package's HTTP layer entirely (`routes.enabled` stays `false`) and call the connector directly.

**What the host must provide:**

1. A service provider that binds `ConnectorIngestionContract`:

```php
// In your AppServiceProvider or a dedicated ConnectorServiceProvider:
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;

$this->app->bind(ConnectorIngestionContract::class, YourIngestionBridge::class);
```

2. Your own admin routes:

```php
// routes/admin.php (already auth + admin-gate protected)
Route::get('/connectors/imap/{installation}/credentials', [MyImapAdminController::class, 'form']);
Route::post('/connectors/imap/{installation}/credentials', [MyImapAdminController::class, 'store']);
Route::get('/connectors/imap/{installation}/oauth/callback', [MyImapAdminController::class, 'callback']);
```

3. A controller that delegates to the connector:

```php
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorImap\ImapConnector;
use Illuminate\Http\Request;

class MyImapAdminController extends Controller
{
    public function __construct(private ImapConnector $connector) {}

    public function form(Request $request, int $installation): mixed
    {
        // Returns either a credential form URL (basic) or an OAuth authorize URL (xoauth2).
        $url = $this->connector->initiateOAuth($installation);

        $config = ConnectorInstallation::findOrFail($installation)->config_json ?? [];
        if (($config['auth_mode'] ?? 'basic') === 'xoauth2') {
            return redirect()->away($url);
        }

        // Basic-auth: render your own form posting to the store route.
        // Include the `state` query parameter from the URL and a CSRF token.
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        return view('admin.connectors.imap.credentials', ['state' => $query['state'] ?? '']);
    }

    public function store(Request $request, int $installation): mixed
    {
        // The connector validates the state, pings the server, and persists credentials.
        $this->connector->handleOAuthCallback($installation, $request);
        return redirect()->back()->with('success', 'Credentials saved.');
    }

    public function callback(Request $request, int $installation): mixed
    {
        // OAuth provider redirects here with `code` + `state`.
        $this->connector->handleOAuthCallback($installation, $request);
        return redirect()->route('admin.connectors.index')->with('success', 'Connected.');
    }
}
```

**What the host still owns in Mode B:**
- Route registration + middleware (auth + admin gate).
- The Blade form (host renders it; the store route receives `password`, `state`, plus any connection fields set via `config_json` elsewhere).
- Session-bound state storage (in Mode A this is handled by the package's controller; in Mode B you store the state in the session yourself and verify it before calling `handleOAuthCallback`).

## What gets ingested

### Email document

Each email that passes the filters becomes one markdown document ingested into the KB. The rendered markdown looks like:

```markdown
# Support ticket: login error

| Field      | Value                          |
|------------|-------------------------------|
| From       | Alice Smith <alice@example.com> |
| To         | support@yourcompany.com        |
| Date       | 2024-03-15 09:23:00            |
| Folder     | INBOX                          |
| Message-ID | <abc123@mail.gmail.com>        |
| Attachments | error-log.pdf                 |

---

Hi, I'm getting a 500 error when I try to log in with my SSO account...

**Steps to reproduce:**
1. Go to the login page
2. Click "Sign in with Google"
3. Error appears immediately
```

HTML-only emails are converted with `league/html-to-markdown` (bold, links, bullet lists preserved). If `text/plain` is present it is used directly (controlled by `body_format`).

**Header block fields** (always present in the rendered markdown):

| Header field | IMAP source |
|---|---|
| `From` | `From` header — display name + email address |
| `To` | `To` header — semicolon-separated addresses |
| `Cc` | `Cc` header — semicolon-separated addresses (omitted if empty) |
| `Date` | `Date` header — ISO-8601 |
| `Folder` | IMAP mailbox name |
| `Message-ID` | `Message-ID` header |
| `Attachments` | Comma-separated filenames of all MIME attachments (omitted if none) |

**Metadata fields** (stored under `metadata.converter_hints.imap`):

| Key | Value |
|---|---|
| `connector` | `imap` |
| `installation_id` | Installation integer ID |
| `imap_uid` | IMAP UID (numeric string) |
| `imap_doc_key` | Composite `mailbox:uidvalidity:uid` — stable per-document identity used for deletion reconciliation |
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

Attachments that exceed 25 MB, have a non-allowlisted extension, or are flagged as inline (`Content-Disposition: inline`) are silently skipped. The parent email document is still ingested.

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

When `reconcile_deletions: true` is set in `config_json`, after processing new UIDs the connector calls `SEARCH` with no UID filter to get all current UIDs, diffs them against the stored `ingested_keys` list (capped at 1,000 most recent composite keys), and calls `softDeleteByRemoteId('imap_doc_key', $compositeKey)` for each vanished key.

The composite key format is `mailbox:uidvalidity:uid`, which ensures identical numeric UIDs in different folders or after a UIDVALIDITY roll never cross-delete documents from another folder.

Disabled by default because the diff query is expensive on large mailboxes. Enable it only when you need the KB to reflect actual deletions.

### Sync cap

`max_messages_per_sync` (default 5,000) prevents runaway syncs on newly-connected large mailboxes. When the cap is hit, the watermark is saved for all processed mailboxes and a truncation note is added to `SyncResult.errors`; the next incremental run continues from where the cap was reached.

## Configuration reference

All knobs live in the installation's `config_json` column. Provider-level defaults in `config/connector-imap.php` (under `defaults`) are merged at sync time; you only need to set what differs from the default.

### Authentication

| Key | Default | Description |
|---|---|---|
| `auth_mode` | `"basic"` | `"basic"` (password / app-password) or `"xoauth2"` (Gmail or M365 full OAuth round-trip) |
| `xoauth2_provider` | `"google"` | Which XOAUTH2 provider to use: `"google"` or `"microsoft"`. Only relevant when `auth_mode = "xoauth2"` |

### Core

| Key | Default | Description |
|---|---|---|
| `project_key` | `"connector-imap"` | KB project key under which documents are stored |
| `date_window_days` | `365` | How far back to look for messages. `0` disables the window (all history) |
| `only_unseen` | `false` | Only process messages not yet marked `\Seen` |
| `only_flagged` | `false` | Only process messages marked `\Flagged` |
| `skip_auto_generated` | `true` | Skip messages with `Precedence: bulk/list`, `Auto-Submitted:` (not `no`), or `List-Unsubscribe:` headers |
| `strip_quoted_history` | `false` | Remove quoted reply blocks (lines starting with `>`) from the body before rendering markdown |
| `body_format` | `"prefer_text"` | `"prefer_text"` uses `text/plain` when available, falls back to HTML→markdown. `"prefer_html"` inverts priority |
| `redact_pii` | `false` | Pass the rendered markdown through the host's PII redactor before storing |
| `reconcile_deletions` | `false` | Enable UID-diff deletion reconciliation (expensive on large mailboxes) |

### Connection

Under `config_json.connection`:

| Key | Default | Description |
|---|---|---|
| `connection.host` | — | IMAP hostname (e.g. `imap.gmail.com`, `outlook.office365.com`) |
| `connection.port` | `993` | IMAP port |
| `connection.encryption` | `"ssl"` | `"ssl"` (port 993) or `"tls"` (STARTTLS, port 143) |
| `connection.username` | — | Mailbox username / email address. **Required for XOAUTH2** — the connector cannot infer it from the token |
| `connection.validate_cert` | `true` | Validate the server's TLS certificate. Set `false` only for self-signed certs in dev |

### Folders

Under `config_json.folders`:

| Key | Default | Description |
|---|---|---|
| `folders.include` | `[]` | Explicit allowlist of folder names to sync. When non-empty, only these folders are synced and `folders.exclude` is ignored |
| `folders.exclude` | `["Trash", "Spam", "Junk", "[Gmail]/Spam", "[Gmail]/Trash"]` | Folders to skip when `folders.include` is empty |

### Senders / Recipients

Under `config_json.senders` and `config_json.recipients`:

| Key | Default | Description |
|---|---|---|
| `senders.include` | `[]` | Only ingest messages from these addresses / domains. Empty = no restriction |
| `senders.exclude` | `[]` | Never ingest messages from these addresses / domains |
| `recipients.include` | `[]` | Only ingest messages addressed to these recipients (To + Cc). Empty = no restriction |
| `recipients.exclude` | `[]` | Never ingest messages addressed to these recipients |

Addresses may be full (`alice@example.com`) or bare domains (`example.com`). Domain matching uses `str_ends_with($address, '@'.$needle)`.

### Subject keywords

Under `config_json.subject`:

| Key | Default | Description |
|---|---|---|
| `subject.include_keywords` | `[]` | Only ingest messages whose subject contains at least one of these substrings (case-insensitive). Empty = no restriction |
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

### Provider env vars (set in host `.env`)

| Env var | Config key | Notes |
|---|---|---|
| `CONNECTOR_IMAP_ROUTES_ENABLED` | `routes.enabled` | Enable the optional HTTP layer (default `false`) |
| `CONNECTOR_IMAP_CREDENTIAL_FORM_URL` | `credential_form_url` | Override the credential form URL for basic-auth mode |
| `CONNECTOR_IMAP_GOOGLE_CLIENT_ID` | `xoauth2.google.client_id` | GCP OAuth2 client ID |
| `CONNECTOR_IMAP_GOOGLE_CLIENT_SECRET` | `xoauth2.google.client_secret` | GCP OAuth2 client secret |
| `CONNECTOR_IMAP_GOOGLE_REDIRECT_URI` | `xoauth2.google.redirect_uri` | Redirect URI registered in GCP |
| `CONNECTOR_IMAP_MS_CLIENT_ID` | `xoauth2.microsoft.client_id` | Entra app (client) ID |
| `CONNECTOR_IMAP_MS_CLIENT_SECRET` | `xoauth2.microsoft.client_secret` | Entra client secret |
| `CONNECTOR_IMAP_MS_REDIRECT_URI` | `xoauth2.microsoft.redirect_uri` | Redirect URI registered in Entra |

## Config recipes

### Customer-service folder only, last 90 days, PDFs only

```json
{
  "auth_mode": "basic",
  "project_key": "customer-service",
  "date_window_days": 90,
  "connection": {
    "host": "imap.yourcompany.com",
    "port": 993,
    "encryption": "ssl",
    "username": "cs@yourcompany.com"
  },
  "folders": { "include": ["Customer Service", "Support"] },
  "attachments": {
    "enabled": true,
    "allowed_extensions": ["pdf"],
    "max_size_mb": 25
  }
}
```

### Gmail XOAUTH2 with deletion reconciliation

```json
{
  "auth_mode": "xoauth2",
  "xoauth2_provider": "google",
  "project_key": "sales-inbox",
  "date_window_days": 365,
  "connection": {
    "host": "imap.gmail.com",
    "port": 993,
    "encryption": "ssl",
    "username": "sales@yourcompany.com"
  },
  "folders": { "include": ["INBOX", "[Gmail]/Sent Mail"] },
  "reconcile_deletions": true,
  "skip_auto_generated": true,
  "strip_quoted_history": true
}
```

### Internal mailing list — filter to specific senders, no attachments

```json
{
  "auth_mode": "basic",
  "project_key": "engineering-updates",
  "date_window_days": 180,
  "connection": {
    "host": "outlook.office365.com",
    "port": 993,
    "encryption": "ssl",
    "username": "eng-updates@yourcompany.com"
  },
  "senders": {
    "include": ["@yourcompany.com"]
  },
  "subject": {
    "include_keywords": ["[eng]", "update:", "RFC:"]
  },
  "skip_auto_generated": false,
  "attachments": { "enabled": false }
}
```

## Security notes

- **Credentials encrypted at rest** — passwords and OAuth tokens are stored via `OAuthCredentialVault`, which encrypts values using Laravel's application key before writing to the database. No secret is ever stored in plaintext.
- **No secret logging** — no password, `client_secret`, `access_token`, or `refresh_token` value is written to logs, included in exception messages, stored in audit metadata, or ingested into KB documents.
- **Routes require authentication and your admin gate** — the package's HTTP layer ships with `['web', 'auth']` as the minimum middleware. You MUST add your own admin authorization gate (e.g. `can:manage-connectors`) to prevent ordinary authenticated users from accessing connector credential routes. See [Integration modes — Mode A](#mode-a-use-the-packages-optional-http-layer-recommended-for-most-hosts).
- **Tenant isolation** — every installation lookup in the HTTP layer is scoped by `tenant_id` via `TenantContext`. A valid installation ID belonging to a different tenant returns 404 (not 403) to avoid leaking whether an installation ID exists. This is IDOR-safe by design.
- **Session-bound OAuth state** — the OAuth state token is stored in the authenticated user's Laravel session and consumed atomically (`session()->pull()`). This prevents CSRF and replay attacks on the callback route. The state is also validated by `ImapConnector::consumeOAuthState()` as a second line of defence.
- **Best-effort revoke** — `disconnect()` calls Google's revoke endpoint (Microsoft has no standard revoke endpoint). If the network call fails, local credentials are still cleared — operators can always disconnect locally.
- **No secrets in KB documents** — the `emitAudit('installed', ...)` call deliberately excludes all token values from the audit metadata. The only audit fields are `auth_mode` and `provider`.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite has three test suites:

| Suite | What it covers | Network |
|---|---|---|
| Unit | Pure-PHP logic: `MessageFilter`, `AttachmentPolicy`, `EmailToMarkdown` (incl. HTML→markdown fidelity), `MailboxWalker`, `MailMetadata` | None |
| Feature | `ImapConnector` end-to-end against `FakeImapClient` + `.eml` fixtures: full sync, incremental, attachment ingestion, filter behaviour, health, disconnect, reconcile_deletions; XOAUTH2 initiate/callback/refresh/revoke against `Http::fake()`; HTTP layer routes | None |
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
| **Login fails on install** | Gmail with 2-Step Verification — direct password not accepted | Create a Gmail App Password (see [Credential setup](#credential-setup-junior-proof-step-by-step)) |
| **Login fails on install** | Outlook / Microsoft 365 — IMAP disabled for the mailbox | Enable IMAP in Exchange admin centre under Recipients → Mailboxes → Email apps |
| **XOAUTH2 — `invalid_grant`** | Token expired or revoked | User must re-authorise via the credential form. For Google, check if the app's refresh token was revoked at <https://myaccount.google.com/permissions> |
| **XOAUTH2 — `invalid_scope`** | Gmail scope missing or wrong | Confirm `https://mail.google.com/` is in the GCP OAuth consent screen scopes. For M365 confirm `IMAP.AccessAsUser.All` and `offline_access` are in the Entra app permissions with admin consent granted |
| **XOAUTH2 — `ConnectorAuthException` on token refresh** | Refresh token was not returned at first auth | For Google: ensure `access_type=offline` is in the authorize URL (it is — the connector sends it). For M365: ensure `offline_access` is in the scope |
| **Routes return 404** | `routes.enabled` is `false` (default) | Set `CONNECTOR_IMAP_ROUTES_ENABLED=true` in `.env` and restart your queue/octane worker |
| **Routes return 403** | No admin gate configured | Add your admin authorization middleware to `routes.middleware` in the config (e.g. `['web', 'auth', 'can:manage-connectors']`) |
| **Nothing ingested after first sync** | `date_window_days` cutting off old messages | Increase `date_window_days` or set to `0` for all history |
| **Nothing ingested after first sync** | Folder name mismatch | List actual folder names via a live test or IMAP client (e.g. Thunderbird); update `folders.include` to match |
| **Nothing ingested after first sync** | `skip_auto_generated: true` filtering all messages | Messages carry `Precedence: bulk`, `Auto-Submitted`, or `List-Unsubscribe` headers. Set `skip_auto_generated: false` to override |
| **Attachments missing from KB** | Extension not in allowlist | Add the extension to `attachments.allowed_extensions` in `config_json` |
| **Attachments missing from KB** | File over 25 MB limit | Raise `attachments.max_size_mb` in `config_json` |
| **Gmail folders not found** | Gmail uses `[Gmail]/All Mail`, `[Gmail]/Sent Mail`, etc. | Use the exact folder names shown by your IMAP client. The default `folders_exclude` already excludes `[Gmail]/Spam` and `[Gmail]/Trash` |
| **Deleted emails reappear in RAG** | `reconcile_deletions` is `false` (default) | Set `reconcile_deletions: true` in `config_json` (note: expensive on large mailboxes) |
| **Microsoft 365 disconnect does not revoke upstream token** | M365 has no OAuth revoke endpoint (known Microsoft limitation) | `disconnect` clears locally stored credentials, but the upstream access token remains valid until it expires (typically 60 min). Sessions already authenticated with that token will continue to work until expiry; request new authorization from the user if needed |
| **PHPStan OOMs locally** | Default memory limit too low | Run `vendor/bin/phpstan analyse --memory-limit=512M` or use `composer analyse` |

## Roadmap

- [ ] Move to "Production" mode in GCP OAuth consent screen (removes the test-user restriction for public Gmail accounts).
- [ ] M365 tenant-specific Entra endpoints for single-tenant deployments.
- [ ] Webhook / IMAP IDLE push mode for near-real-time sync (currently polling via cron cadence).
- [ ] Sheets and Slides attachment export (analogous to Google Drive connector).
- [ ] PGP/S-MIME header metadata capture.

## License

Apache-2.0 — see [LICENSE](LICENSE).

Built and maintained by [Padosoft](https://padosoft.com/). Part of the AskMyDocs connector ecosystem.
