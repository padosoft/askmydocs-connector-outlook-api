<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorMicrosoftMail;

use Illuminate\Support\ServiceProvider;

final class MicrosoftMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/connector-microsoft-mail.php', 'connector-microsoft-mail');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/connector-microsoft-mail.php' => config_path('connector-microsoft-mail.php')], 'connector-microsoft-mail-config');
            $this->publishes([__DIR__.'/../resources/icons/microsoft-mail.svg' => public_path('connectors/microsoft-mail.svg')], 'connector-microsoft-mail-assets');
        }
    }
}
