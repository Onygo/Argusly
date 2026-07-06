<?php

it('accepts argusly-issued connector env names in the packaged config', function () {
    $config = file_get_contents(base_path('packages/laravel-connector/config/argusly-connector.php'));

    expect($config)->toContain('ARGUSLY_CONNECTOR_API_URL')
        ->and($config)->toContain('ARGUSLY_CONNECTOR_API_KEY')
        ->and($config)->toContain('ARGUSLY_CONNECTOR_WORKSPACE_ID')
        ->and($config)->toContain('ARGUSLY_CONNECTOR_DESTINATION_KEY')
        ->and($config)->toContain('ARGUSLY_CONNECTOR_SITE_NAME')
        ->and($config)->toContain('ARGUSLY_CONNECTOR_SITE_URL')
        ->and($config)->toContain('ARGUSLY_CONNECTOR_TIMEOUT')
        ->and($config)->toContain('ARGUSLY_CONNECTOR_WEBHOOKS_ENABLED')
        ->and($config)->toContain('ARGUSLY_CONNECTOR_WEBHOOK_SECRET')
        ->and($config)->toContain('ARGUSLY_CONNECTOR_SYNC_PATH')
        ->and($config)->toContain('ARGUSLY_CONNECTOR_ALLOWED_OPERATIONS')
        ->and($config)->toContain('ARGUSLY_CONNECTOR_AUTONOMOUS_ALLOWED');
});

it('registers heartbeat through the normal laravel scheduler', function () {
    $provider = file_get_contents(base_path('packages/laravel-connector/src/ArguslyConnectorServiceProvider.php'));

    expect($provider)->toContain(\Illuminate\Console\Scheduling\Schedule::class)
        ->and($provider)->toContain("command('argusly:connector:health')")
        ->and($provider)->toContain('everyFiveMinutes')
        ->and($provider)->toContain('loadMigrationsFrom')
        ->and($provider)->toContain('loadRoutesFrom');
});

it('exposes the inbound connector sync endpoint in the package', function () {
    $routes = file_get_contents(base_path('packages/laravel-connector/routes/api.php'));
    $composer = file_get_contents(base_path('packages/laravel-connector/composer.json'));

    expect($routes)->toContain('ConnectorSyncController')
        ->and($routes)->toContain('argusly.connector.sync')
        ->and($composer)->toContain('illuminate/database')
        ->and($composer)->toContain('Argusly\\\\LaravelConnector\\\\');
});

it('reports the laravel connector release version', function () {
    require_once base_path('packages/laravel-connector/src/InstalledVersions.php');

    expect(\Onygo\ArguslyConnector\InstalledVersions::version())->toBe('0.1.5');
});

it('does not document a connector-specific cron job', function () {
    $readme = file_get_contents(base_path('packages/laravel-connector/README.md'));

    expect($readme)->toContain('normal `php artisan schedule:run`')
        ->and($readme)->not->toContain('* * * * *')
        ->and($readme)->not->toContain('Plesk cron')
        ->and($readme)->not->toContain('argusly:connector:health >>');
});
