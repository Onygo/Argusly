<?php

namespace App\Services\Integrations;

use App\Enums\ContentDestinationType;
use App\Models\ContentDestination;
use App\Support\SiteUrl;
use Illuminate\Support\Facades\Crypt;

class LaravelConnectorDestinationConfigurator
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function mergeConfig(ContentDestination $destination, array $attributes): array
    {
        $config = is_array($destination->config) ? $destination->config : [];
        if (array_key_exists('config', $attributes) && is_array($attributes['config'])) {
            $config = array_replace_recursive($config, $attributes['config']);
        }

        $type = (string) ($attributes['type'] ?? ($destination->type?->value ?? $destination->type ?? ''));
        if ($type !== ContentDestinationType::LARAVEL->value) {
            return $config;
        }

        $settings = is_array(data_get($config, 'laravel_connector')) ? data_get($config, 'laravel_connector') : [];

        $baseUrl = SiteUrl::normalizeBaseUrl((string) data_get($settings, 'base_url', ''));
        $syncEndpoint = trim((string) data_get($settings, 'sync_endpoint', '/publishlayer/sync'));
        $siteId = trim((string) data_get($settings, 'site_id', ''));
        $mode = trim((string) data_get($settings, 'mode', 'hosted_views'));
        $enabled = (bool) data_get($settings, 'enabled', true);
        $plainApiKey = trim((string) data_get($settings, 'api_key', ''));

        $normalizedSettings = [
            'base_url' => $baseUrl,
            'sync_endpoint' => '/'.ltrim($syncEndpoint !== '' ? $syncEndpoint : '/publishlayer/sync', '/'),
            'site_id' => $siteId,
            'enabled' => $enabled,
            'mode' => in_array($mode, ['hosted_views', 'headless'], true) ? $mode : 'hosted_views',
        ];

        $existingEncryptedApiKey = trim((string) data_get($destination->config, 'laravel_connector.api_key_encrypted', ''));
        if ($plainApiKey !== '') {
            $normalizedSettings['api_key_encrypted'] = Crypt::encryptString($plainApiKey);
        } elseif ($existingEncryptedApiKey !== '') {
            $normalizedSettings['api_key_encrypted'] = $existingEncryptedApiKey;
        }

        $config['laravel_connector'] = $normalizedSettings;

        return $config;
    }
}
