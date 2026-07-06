<?php

declare(strict_types=1);

namespace Onygo\ArguslyConnector;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
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

            $this->app->booted(function (): void {
                $schedule = $this->app->make(Schedule::class);

                $schedule->command('argusly:connector:health')
                    ->everyFiveMinutes()
                    ->withoutOverlapping();
            });
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        $this->registerSyncRouteAlias();
    }

    private function registerSyncRouteAlias(): void
    {
        $path = trim((string) config('argusly-connector.webhooks.sync_path', 'argusly/sync'), '/');

        if ($path === '' || str_starts_with($path, 'api/')) {
            return;
        }

        Route::post('api/' . $path, \Onygo\ArguslyConnector\Http\Controllers\ConnectorSyncController::class)
            ->name('argusly.connector.sync.api');
    }
}
