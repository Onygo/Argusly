<?php

namespace App\Http\Controllers\Api\Plugin;

use App\Http\Controllers\Controller;
use App\Services\PluginUpdates\LicenseKeyService;
use App\Services\PluginUpdates\PluginDownloadTokenService;
use App\Services\PluginUpdates\PluginReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckUpdateController extends Controller
{
    public function __invoke(
        Request $request,
        LicenseKeyService $licenseKeyService,
        PluginReleaseService $pluginReleaseService,
        PluginDownloadTokenService $pluginDownloadTokenService
    ): JsonResponse {
        $data = $request->validate([
            'license_key' => ['required', 'string'],
            'domain' => ['nullable', 'string'],
            'site_url' => ['nullable', 'string'],
            'wp_version' => ['nullable', 'string'],
            'plugin_version' => ['required', 'string'],
        ]);

        $license = $licenseKeyService->findByPlainKey((string) $data['license_key']);
        $domain = $licenseKeyService->normalizeDomain(
            domain: $data['domain'] ?? null,
            siteUrl: $data['site_url'] ?? null
        );
        $wpVersion = trim((string) ($data['wp_version'] ?? ''));
        $pluginVersion = trim((string) $data['plugin_version']);

        if (
            ! $license ||
            ! $license->isActiveAndValid() ||
            ! $license->workspace ||
            ! $license->workspace->organization ||
            $license->workspace->organization->status !== 'active' ||
            $domain === '' ||
            ! $licenseKeyService->domainBelongsToWorkspace($license, $domain)
        ) {
            return response()->json([
                'update_available' => false,
            ]);
        }

        $release = $pluginReleaseService->latestCompatibleRelease($pluginVersion, $wpVersion);
        if (! $release) {
            Log::info('plugin.check_update.no_update', [
                'workspace_id' => $license->workspace_id,
                'domain' => $domain,
                'license_hash_prefix' => substr($license->license_key_hash, 0, 12),
                'plugin_version' => $pluginVersion,
                'wp_version' => $wpVersion,
            ]);

            return response()->json([
                'update_available' => false,
            ]);
        }

        $downloadToken = $pluginDownloadTokenService->issueToken($license, $domain, $release);
        $downloadUrl = route('api.v1.plugin.download', ['token' => rawurlencode($downloadToken)]);

        Log::info('plugin.check_update.available', [
            'workspace_id' => $license->workspace_id,
            'domain' => $domain,
            'license_hash_prefix' => substr($license->license_key_hash, 0, 12),
            'current_version' => $pluginVersion,
            'new_version' => $release->version,
        ]);

        return response()->json([
            'update_available' => true,
            'version' => $release->version,
            'min_wp_version' => $release->min_wp_version,
            'tested_wp_version' => $release->tested_wp_version,
            'is_security_release' => (bool) $release->is_security_release,
            'download_token' => $downloadToken,
            'download_url' => $downloadUrl,
        ]);
    }
}
