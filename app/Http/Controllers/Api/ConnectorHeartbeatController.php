<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientSite;
use App\Services\Seo\WordPressSeoCapabilityDetector;
use App\Support\SiteUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConnectorHeartbeatController extends Controller
{
    public function __invoke(Request $request, WordPressSeoCapabilityDetector $seoDetector): JsonResponse
    {
        $siteToken = $request->attributes->get('siteToken');

        if (! $siteToken || ! $siteToken->hasScope('heartbeat:write')) {
            return $this->errorResponse($request, 'Forbidden', 403);
        }

        /** @var ClientSite|null $clientSite */
        $clientSite = $request->attributes->get('clientSite');
        if (! $clientSite) {
            return $this->errorResponse($request, 'Client site not resolved', 401);
        }

        $data = $request->validate([
            'platform' => ['nullable', 'string', 'in:wp,laravel,other'],
            'connector_version' => ['nullable', 'string', 'max:50'],
            'framework_version' => ['nullable', 'string', 'max:50'],
            'php_version' => ['nullable', 'string', 'max:20'],
            'site_url' => ['nullable', 'string', 'max:255'],
            'app_url' => ['nullable', 'string', 'max:500'],
            'capabilities' => ['nullable', 'array'],
            'plugins' => ['nullable', 'array'],
            'active_plugins' => ['nullable', 'array'],
            'environment' => ['nullable', 'string', 'max:20'],
            // Backwards compatibility with old WP heartbeat
            'wp_version' => ['nullable', 'string', 'max:40'],
            'plugin_version' => ['nullable', 'string', 'max:40'],
        ]);

        // Validate site_url if provided (backwards compat with old /wp/heartbeat)
        if (! empty($data['site_url'])) {
            $incoming = SiteUrl::normalizeBaseUrl((string) $data['site_url']);
            $incomingHost = SiteUrl::hostFromUrl($incoming);
            $siteHost = SiteUrl::hostFromUrl((string) ($clientSite->base_url ?: $clientSite->site_url));

            if ($incomingHost !== '' && $siteHost !== '' && $incomingHost !== $siteHost) {
                Log::info('connector.heartbeat.site_mismatch', [
                    'site_id' => $clientSite->id,
                    'expected' => $siteHost,
                    'received' => $incomingHost,
                ]);

                return $this->errorResponse($request, 'Site URL mismatch', 422);
            }
        }

        // Determine platform from request or site type
        $platform = $this->resolvePlatform($data, $clientSite, $request);

        // Build connector_meta JSON
        $connectorMeta = array_filter([
            'php_version' => $data['php_version'] ?? null,
            'framework_version' => $data['framework_version'] ?? $data['wp_version'] ?? null,
            'app_url' => $data['app_url'] ?? null,
            'capabilities' => $data['capabilities'] ?? null,
            'plugins' => $data['plugins'] ?? null,
            'active_plugins' => $data['active_plugins'] ?? null,
            'environment' => $data['environment'] ?? null,
        ], fn ($v) => $v !== null);

        // Resolve connector_version (prefer new field, fall back to plugin_version)
        $connectorVersion = $data['connector_version'] ?? $data['plugin_version'] ?? null;

        // Update site
        $clientSite->status = 'connected';
        $clientSite->last_seen_at = now();
        $clientSite->last_healthcheck_at = now();
        $clientSite->last_heartbeat_at = now();
        $clientSite->last_error = null;
        $clientSite->connector_platform = $platform;
        $clientSite->is_active = true;

        if ($connectorVersion !== null) {
            $clientSite->connector_version = $connectorVersion;
            $clientSite->plugin_version = $connectorVersion; // backwards compat
        }

        $currentCapabilities = $this->normalizeCapabilities(
            is_array($data['capabilities'] ?? null) ? $data['capabilities'] : (is_array($clientSite->capabilities) ? $clientSite->capabilities : []),
            $platform,
            $clientSite,
        );
        $clientSite->capabilities = $currentCapabilities;
        if ($platform === 'wp') {
            $seoDetection = $seoDetector->detect([
                'capabilities' => $data['capabilities'] ?? [],
                'plugins' => $data['plugins'] ?? [],
                'active_plugins' => $data['active_plugins'] ?? [],
                'connector_meta' => $connectorMeta,
            ]);

            $clientSite->seo_provider = (string) ($seoDetection['seo_provider'] ?? 'none');
            $clientSite->supports_meta_title = (bool) ($seoDetection['supports_meta_title'] ?? false);
            $clientSite->supports_meta_description = (bool) ($seoDetection['supports_meta_description'] ?? false);
            $clientSite->supports_canonical = (bool) ($seoDetection['supports_canonical'] ?? false);
            $clientSite->supports_og_tags = (bool) ($seoDetection['supports_og_tags'] ?? false);

            $currentCapabilities = array_replace_recursive($currentCapabilities, [
                'seo' => [
                    'provider' => $clientSite->seo_provider,
                    'supports_meta_title' => $clientSite->supports_meta_title,
                    'supports_meta_description' => $clientSite->supports_meta_description,
                    'supports_canonical' => $clientSite->supports_canonical,
                    'supports_og_tags' => $clientSite->supports_og_tags,
                    'detected_plugins' => (array) ($seoDetection['detected_plugins'] ?? []),
                ],
            ]);
            $clientSite->capabilities = $currentCapabilities;

            $connectorMeta = array_merge($connectorMeta, [
                'seo_provider' => $clientSite->seo_provider,
                'seo_capabilities' => [
                    'supports_meta_title' => $clientSite->supports_meta_title,
                    'supports_meta_description' => $clientSite->supports_meta_description,
                    'supports_canonical' => $clientSite->supports_canonical,
                    'supports_og_tags' => $clientSite->supports_og_tags,
                ],
                'seo_detected_plugins' => (array) ($seoDetection['detected_plugins'] ?? []),
            ]);
        } else {
            $clientSite->seo_provider = 'none';
            $clientSite->supports_meta_title = false;
            $clientSite->supports_meta_description = false;
            $clientSite->supports_canonical = false;
            $clientSite->supports_og_tags = false;
        }

        // Merge connector_meta (keep existing keys not in this update)
        $existingMeta = is_array($clientSite->connector_meta) ? $clientSite->connector_meta : [];
        $clientSite->connector_meta = array_merge($existingMeta, $connectorMeta);

        // Backwards compat: store wp_version if provided
        if (! empty($data['wp_version'])) {
            $clientSite->wp_version = $data['wp_version'];
        }

        $clientSite->save();

        Log::debug('connector.heartbeat.success', [
            'site_id' => $clientSite->id,
            'platform' => $platform,
            'version' => $connectorVersion,
        ]);

        $payload = [
            'ok' => true,
            'site_id' => $clientSite->id,
            'capabilities' => $clientSite->capabilities,
            'server_time' => now()->toIso8601String(),
            'next_recommended_heartbeat_seconds' => 300,
            'connector_public_url' => trim((string) config('argusly.webhooks.connector_public_url', '')),
        ];

        if ($this->isCanonicalConnectorRoute($request)) {
            return response()->json([
                'ok' => true,
                'data' => [
                    'site_id' => $payload['site_id'],
                    'capabilities' => $payload['capabilities'],
                    'connector_public_url' => $payload['connector_public_url'],
                ],
                'meta' => [
                    'server_time' => $payload['server_time'],
                    'next_recommended_heartbeat_seconds' => $payload['next_recommended_heartbeat_seconds'],
                ],
                'errors' => [],
            ]);
        }

        return response()->json($payload);
    }

    private function errorResponse(Request $request, string $message, int $status): JsonResponse
    {
        if ($this->isCanonicalConnectorRoute($request)) {
            return response()->json([
                'ok' => false,
                'data' => null,
                'meta' => [],
                'errors' => [
                    [
                        'message' => $message,
                    ],
                ],
            ], $status);
        }

        return response()->json(['error' => $message], $status);
    }

    private function isCanonicalConnectorRoute(Request $request): bool
    {
        return str_starts_with($request->path(), 'api/v1/connectors/');
    }

    /**
     * @param array<string|int,mixed> $incoming
     * @return array<string,mixed>
     */
    private function normalizeCapabilities(array $incoming, string $platform, ClientSite $clientSite): array
    {
        $legacyList = array_values(array_filter($incoming, static fn ($key): bool => is_int($key), ARRAY_FILTER_USE_KEY));
        $hasLegacy = static fn (string $name): bool => in_array($name, $legacyList, true) || data_get($incoming, $name) === true;
        $agentic = is_array(data_get($incoming, 'agentic')) ? data_get($incoming, 'agentic') : [];

        $defaults = [
            'create_content' => true,
            'update_content' => true,
            'publish_content' => $platform === 'wp' || $platform === 'laravel',
            'schedule_content' => $platform === 'wp',
            'republish_content' => true,
            'update_internal_links' => true,
            'update_answer_blocks' => true,
            'update_schema' => true,
            'update_meta_title' => true,
            'update_meta_description' => true,
            'update_canonical' => true,
            'update_hreflang' => $platform === 'wp' || $platform === 'laravel',
            'update_locale' => true,
            'read_publication_status' => true,
            'rollback_last_update' => false,
            'preview_content' => true,
            'draft_only' => false,
            'autonomous_allowed' => false,
        ];

        $resolved = [];
        foreach ($defaults as $capability => $default) {
            $resolved[$capability] = (bool) data_get($agentic, $capability, data_get($incoming, $capability, $hasLegacy($capability) ?: $default));
        }

        $settings = is_array($clientSite->automation_settings) ? $clientSite->automation_settings : [];
        if (array_key_exists('autonomous_allowed', $settings)) {
            $resolved['autonomous_allowed'] = (bool) $settings['autonomous_allowed'];
        }

        return array_replace_recursive($incoming, [
            'agentic' => $resolved,
            'supported_operations' => array_values(array_filter(array_keys($resolved), fn (string $key): bool => (bool) $resolved[$key])),
        ]);
    }

    private function resolvePlatform(array $data, ClientSite $clientSite, Request $request): string
    {
        // Explicit platform in payload takes precedence
        if (! empty($data['platform'])) {
            return $data['platform'];
        }

        // Infer from route name (old /wp/heartbeat route implies wp)
        $routeName = $request->route()?->getName();
        if ($routeName === 'api.wp.heartbeat') {
            return 'wp';
        }

        // Fall back to site type
        if ($clientSite->isWordPress()) {
            return 'wp';
        }

        if ($clientSite->isLaravel()) {
            return 'laravel';
        }

        return 'other';
    }
}
