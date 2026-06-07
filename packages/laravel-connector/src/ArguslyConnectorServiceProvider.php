<?php

declare(strict_types=1);

namespace Onygo\ArguslyConnector;

use Illuminate\Support\ServiceProvider;
use Onygo\ArguslyConnector\Console\Commands\ContentPullCommand;
use Onygo\ArguslyConnector\Console\Commands\ContentSyncCommand;
use Onygo\ArguslyConnector\Console\Commands\HealthCheckCommand;

final class ArguslyConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/argusly-connector.php', 'argusly-connector');

        $this->app->singleton(ArguslyClient::class, function ($app): ArguslyClient {
            return new ArguslyClient($app['config']->get('argusly-connector'));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/argusly-connector.php' => config_path('argusly-connector.php'),
        ], 'argusly-connector-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                HealthCheckCommand::class,
                ContentPullCommand::class,
                ContentSyncCommand::class,
            ]);
        }
    }

}
