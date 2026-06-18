<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorImap;

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
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/imap.php' => config_path('connectors-imap.php'),
            ], 'connector-imap-config');

            $this->publishes([
                __DIR__.'/../public/icons' => public_path('connectors'),
            ], 'connector-imap-assets');
        }
    }
}
