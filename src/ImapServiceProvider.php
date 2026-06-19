<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactory;
use Padosoft\AskMyDocsConnectorImap\Imap\ImapClientFactoryInterface;

final class ImapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/imap.php', 'connectors.providers.imap');

        $this->app->bind(
            ImapClientFactoryInterface::class,
            ImapClientFactory::class,
        );
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'connector-imap');

        if ((bool) config('connectors.providers.imap.routes.enabled', false)) {
            $prefix = (string) config('connectors.providers.imap.routes.prefix', 'admin/connectors/imap');
            /** @var array<int,string> $middleware */
            $middleware = (array) config('connectors.providers.imap.routes.middleware', ['web']);

            Route::group(['prefix' => $prefix, 'middleware' => $middleware], function (): void {
                $this->loadRoutesFrom(__DIR__.'/../routes/imap.php');
            });
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/imap.php' => config_path('connectors-imap.php'),
            ], 'connector-imap-config');

            $this->publishes([
                __DIR__.'/../public/icons' => public_path('connectors'),
            ], 'connector-imap-assets');

            $this->publishes([
                __DIR__.'/../routes/imap.php' => base_path('routes/connector-imap.php'),
                __DIR__.'/../resources/views' => resource_path('views/vendor/connector-imap'),
                __DIR__.'/Http/ImapConnectorController.php' => app_path('Http/Controllers/Connectors/ImapConnectorController.php'),
            ], 'connector-imap-http');
        }
    }
}
