<?php

afterEach(function (): void {
    foreach ([
        'PL_CONNECTOR_BASE_URL',
        'PL_CONNECTOR_WORKSPACE_ID',
        'PL_CONNECTOR_API_KEY',
        'PUBLISHLAYER_BASE_URL',
        'PUBLISHLAYER_WORKSPACE_ID',
        'PUBLISHLAYER_API_KEY',
        'PL_WEBHOOK_SECRET',
        'PL_WEBHOOK_QUEUE',
        'PL_CONNECTOR_PUBLIC_URL',
        'PL_IMAGES_ENABLED',
        'PL_IMAGES_DISK',
        'PUBLISHLAYER_WEBHOOK_SECRET',
        'PUBLISHLAYER_WEBHOOK_QUEUE',
        'PUBLISHLAYER_CONNECTOR_PUBLIC_URL',
        'PUBLISHLAYER_IMAGES_ENABLED',
        'PUBLISHLAYER_IMAGES_DISK',
    ] as $key) {
        setPublishLayerEnvVar($key, null);
    }
});

it('falls back to legacy connector env keys when new connector keys are not set', function () {
    setPublishLayerEnvVar('PL_CONNECTOR_BASE_URL', null);
    setPublishLayerEnvVar('PL_CONNECTOR_WORKSPACE_ID', null);
    setPublishLayerEnvVar('PL_CONNECTOR_API_KEY', null);
    setPublishLayerEnvVar('PUBLISHLAYER_BASE_URL', 'https://legacy-api.publishlayer.test');
    setPublishLayerEnvVar('PUBLISHLAYER_WORKSPACE_ID', 'legacy-workspace');
    setPublishLayerEnvVar('PUBLISHLAYER_API_KEY', 'legacy-api-key');

    $config = require base_path('config/publishlayer_connector.php');

    expect(data_get($config, 'api.base_url'))->toBe('https://legacy-api.publishlayer.test')
        ->and(data_get($config, 'api.workspace_id'))->toBe('legacy-workspace')
        ->and(data_get($config, 'api.api_key'))->toBe('legacy-api-key');
});

it('falls back to legacy server env keys when new server keys are not set', function () {
    setPublishLayerEnvVar('PL_WEBHOOK_SECRET', null);
    setPublishLayerEnvVar('PL_WEBHOOK_QUEUE', null);
    setPublishLayerEnvVar('PL_CONNECTOR_PUBLIC_URL', null);
    setPublishLayerEnvVar('PL_IMAGES_ENABLED', null);
    setPublishLayerEnvVar('PL_IMAGES_DISK', null);

    setPublishLayerEnvVar('PUBLISHLAYER_WEBHOOK_SECRET', 'legacy-webhook-secret');
    setPublishLayerEnvVar('PUBLISHLAYER_WEBHOOK_QUEUE', 'legacy-webhook-queue');
    setPublishLayerEnvVar('PUBLISHLAYER_CONNECTOR_PUBLIC_URL', 'https://legacy-public.publishlayer.test');
    setPublishLayerEnvVar('PUBLISHLAYER_IMAGES_ENABLED', '0');
    setPublishLayerEnvVar('PUBLISHLAYER_IMAGES_DISK', 'legacy-images-disk');

    $config = require base_path('config/publishlayer.php');

    expect(data_get($config, 'webhooks.secret'))->toBe('legacy-webhook-secret')
        ->and(data_get($config, 'webhooks.queue'))->toBe('legacy-webhook-queue')
        ->and(data_get($config, 'webhooks.connector_public_url'))->toBe('https://legacy-public.publishlayer.test')
        ->and(data_get($config, 'images.enabled'))->toBeFalse()
        ->and(data_get($config, 'images.disk'))->toBe('legacy-images-disk');
});

function setPublishLayerEnvVar(string $key, ?string $value): void
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
