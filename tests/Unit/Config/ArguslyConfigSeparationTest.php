<?php

afterEach(function (): void {
    foreach ([
        'ARGUSLY_CONNECTOR_BASE_URL',
        'ARGUSLY_CONNECTOR_WORKSPACE_ID',
        'ARGUSLY_CONNECTOR_API_KEY',
        'ARGUSLY_WEBHOOK_SECRET',
        'ARGUSLY_WEBHOOK_QUEUE',
        'ARGUSLY_CONNECTOR_PUBLIC_URL',
        'ARGUSLY_IMAGES_ENABLED',
        'ARGUSLY_IMAGES_DISK',
    ] as $key) {
        setEnvVar($key, null);
    }
});

it('reads canonical connector env keys', function () {
    setEnvVar('ARGUSLY_CONNECTOR_BASE_URL', 'https://api.argusly.test');
    setEnvVar('ARGUSLY_CONNECTOR_WORKSPACE_ID', 'workspace-id');
    setEnvVar('ARGUSLY_CONNECTOR_API_KEY', 'api-key');

    $config = require base_path('config/argusly_connector.php');

    expect(data_get($config, 'api.base_url'))->toBe('https://api.argusly.test')
        ->and(data_get($config, 'api.workspace_id'))->toBe('workspace-id')
        ->and(data_get($config, 'api.api_key'))->toBe('api-key');
});

it('reads canonical server env keys', function () {
    setEnvVar('ARGUSLY_WEBHOOK_SECRET', 'webhook-secret');
    setEnvVar('ARGUSLY_WEBHOOK_QUEUE', 'webhook-queue');
    setEnvVar('ARGUSLY_CONNECTOR_PUBLIC_URL', 'https://public.argusly.test');
    setEnvVar('ARGUSLY_IMAGES_ENABLED', '0');
    setEnvVar('ARGUSLY_IMAGES_DISK', 'images-disk');

    $config = require base_path('config/argusly.php');

    expect(data_get($config, 'webhooks.secret'))->toBe('webhook-secret')
        ->and(data_get($config, 'webhooks.queue'))->toBe('webhook-queue')
        ->and(data_get($config, 'webhooks.connector_public_url'))->toBe('https://public.argusly.test')
        ->and(data_get($config, 'images.enabled'))->toBeFalse()
        ->and(data_get($config, 'images.disk'))->toBe('images-disk');
});

function setEnvVar(string $key, ?string $value): void
{
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);

        return;
    }

    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
