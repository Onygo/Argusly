<?php

namespace App\Providers;

use App\Models\ContentPublication;
use App\Support\Connectors\ConnectorRegistry;
use App\Support\Connectors\LaravelConnector;
use App\Support\Connectors\WordPressPublicationConnector;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for content publication connectors.
 *
 * Registers the connector registry as a singleton and registers
 * all available connector implementations.
 */
class ConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register ConnectorRegistry as a singleton
        $this->app->singleton(ConnectorRegistry::class, function ($app) {
            $registry = new ConnectorRegistry();

            // Register Laravel connector
            $registry->register($app->make(LaravelConnector::class));

            // Register WordPress connector
            $registry->register($app->make(WordPressPublicationConnector::class));

            return $registry;
        });
    }

    public function boot(): void
    {
        //
    }
}
