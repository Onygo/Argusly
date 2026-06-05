<?php

namespace App\Services\Integrations;

use App\Enums\ContentDestinationType;
use App\Models\ClientSite;
use App\Models\ContentDestination;
use App\Support\SiteUrl;
use Illuminate\Support\Str;

class DestinationBillingSiteService
{
    public function ensureBillingSite(ContentDestination $destination): ClientSite
    {
        $workspace = $destination->workspace;
        $config = is_array($destination->config) ? $destination->config : [];
        $configuredSiteId = trim((string) ($config['billing_client_site_id'] ?? ''));

        if ($configuredSiteId !== '') {
            $existing = ClientSite::query()
                ->where('workspace_id', $workspace->id)
                ->where('id', $configuredSiteId)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $default = ClientSite::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->first();

        if (! $default) {
            $slug = strtolower(trim((string) ($workspace->name ?: 'workspace')));
            $slug = preg_replace('/[^a-z0-9]+/', '-', $slug ?: 'workspace') ?: 'workspace';
            $slug = trim($slug, '-') ?: 'workspace';

            $baseUrl = $this->resolveDestinationBaseUrl($destination, (string) $workspace->id);
            $host = SiteUrl::hostFromUrl($baseUrl);

            $default = ClientSite::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'type' => ClientSite::TYPE_LARAVEL,
                'name' => $this->resolveBillingSiteName($destination, $slug),
                'site_url' => $baseUrl,
                'base_url' => $baseUrl,
                'allowed_domains' => [$host],
                'is_active' => true,
                'status' => 'connected',
            ]);
        } else {
            $baseUrl = $this->resolveDestinationBaseUrl($destination, (string) $workspace->id);
            $host = SiteUrl::hostFromUrl($baseUrl);

            $default->forceFill([
                'type' => ClientSite::TYPE_LARAVEL,
                'name' => $this->resolveBillingSiteName($destination, (string) $default->name),
                'site_url' => $baseUrl,
                'base_url' => $baseUrl,
                'allowed_domains' => $host !== '' ? [$host] : (is_array($default->allowed_domains) ? $default->allowed_domains : []),
                'is_active' => true,
                'status' => $default->status ?: 'connected',
            ])->save();
        }

        $config['billing_client_site_id'] = (string) $default->id;
        $destination->config = $config;
        $destination->save();

        return $default;
    }

    private function resolveDestinationBaseUrl(ContentDestination $destination, string $workspaceId): string
    {
        if ($destination->hasType(ContentDestinationType::LARAVEL) && $destination->laravelConnectorBaseUrl()) {
            return (string) $destination->laravelConnectorBaseUrl();
        }

        $subdomain = 'api-'.substr(strtolower($workspaceId), 0, 8);

        return 'https://'.$subdomain.'.publishlayer.local';
    }

    private function resolveBillingSiteName(ContentDestination $destination, string $fallback): string
    {
        if ($destination->hasType(ContentDestinationType::LARAVEL)) {
            return (string) ($destination->name ?: $fallback);
        }

        return str_starts_with($fallback, 'API Billing Site')
            ? $fallback
            : 'API Billing Site';
    }
}
