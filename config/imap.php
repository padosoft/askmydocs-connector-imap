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
    'routes' => [
        'enabled' => env('CONNECTOR_IMAP_ROUTES_ENABLED', false),
        'prefix' => 'admin/connectors/imap',
        'middleware' => ['web'],
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
