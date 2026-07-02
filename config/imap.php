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
            'scopes' => 'https://mail.google.com/',
            'revoke_url' => 'https://oauth2.googleapis.com/revoke',
        ],
        'microsoft' => [
            'client_id' => env('CONNECTOR_IMAP_MS_CLIENT_ID'),
            'client_secret' => env('CONNECTOR_IMAP_MS_CLIENT_SECRET'),
            'redirect_uri' => env('CONNECTOR_IMAP_MS_REDIRECT_URI'),
            'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'imap_host' => 'outlook.office365.com',
            'scopes' => 'https://outlook.office.com/IMAP.AccessAsUser.All offline_access openid email',
            'revoke_url' => null,
        ],
    ],

    // App-only (OAuth2 client-credentials) providers. Unlike the delegated
    // xoauth2 flow above — where a user interactively signs in and consents —
    // these credentials are PER-INSTALLATION: each customer supplies their own
    // Entra tenant + app registration (Directory/tenant ID, client ID, client
    // secret) and the mailbox to read. Those per-install values live in the
    // installation's config_json + the encrypted vault, NOT in host env. This
    // block only holds the fixed provider-level endpoint template + scope.
    //
    // Sysadmin setup for the mailbox owner (Exchange Online):
    //   1. Enable IMAP on the mailbox.
    //   2. Register an app in Microsoft Entra ID.
    //   3. Add the Office 365 Exchange Online APPLICATION permission
    //      IMAP.AccessAsApp and grant tenant admin consent.
    //   4. Register the app's service principal in Exchange:
    //      New-ServicePrincipal -AppId <clientId> -ObjectId <enterpriseAppObjectId>
    //   5. Scope it to the mailbox:
    //      Add-MailboxPermission -Identity <mailbox> -User <servicePrincipalId> -AccessRights FullAccess
    //      (or restrict via New-ApplicationAccessPolicy).
    //   6. Hand over: Tenant ID, Client ID, Client Secret, mailbox email.
    'client_credentials' => [
        'microsoft' => [
            // {tenant} is replaced with config_json.ms_tenant_id at runtime.
            'token_url_template' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
            'scope' => 'https://outlook.office365.com/.default',
            'imap_host' => 'outlook.office365.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
        ],
    ],
    'routes' => [
        'enabled' => env('CONNECTOR_IMAP_ROUTES_ENABLED', false),
        'prefix' => 'admin/connectors/imap',
        // SECURITY: 'auth' is the minimum required middleware — it ensures only
        // authenticated users can access the credential routes.
        // Operators MUST append their own admin-authorization middleware to this
        // array (e.g. a gate/policy such as 'can:manage-connectors') because only
        // the host application knows its admin roles — this package cannot safely
        // hardcode a gate name (an undefined gate would 403 every host).
        // Example: ['web', 'auth', 'can:manage-connectors']
        'middleware' => ['web', 'auth'],
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
