<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Padosoft\AskMyDocsConnectorImap\Http\ImapConnectorController;

/*
|--------------------------------------------------------------------------
| IMAP Connector — Optional HTTP routes
|--------------------------------------------------------------------------
|
| These routes are only loaded when `connectors.providers.imap.routes.enabled`
| is true. The prefix and middleware are applied by ImapServiceProvider::boot()
| before this file is loaded, so you should NOT re-apply them here.
|
| Publish to customise:
|   php artisan vendor:publish --tag=connector-imap-http
|
*/

Route::get('{installation}/credentials', [ImapConnectorController::class, 'form'])
    ->name('connector-imap.credentials.form');

Route::post('{installation}/credentials', [ImapConnectorController::class, 'store'])
    ->name('connector-imap.credentials.store');

Route::get('{installation}/oauth/callback', [ImapConnectorController::class, 'callback'])
    ->name('connector-imap.oauth.callback');
