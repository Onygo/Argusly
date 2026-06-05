<?php

namespace App\Http\Controllers\Api\Plugin;

use App\Http\Controllers\Controller;
use App\Models\WorkspaceDomain;
use App\Services\PluginUpdates\LicenseKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RegisterDomainController extends Controller
{
    public function __invoke(Request $request, LicenseKeyService $licenseKeyService): JsonResponse
    {
        $data = $request->validate([
            'license_key' => ['required', 'string'],
            'domain' => ['nullable', 'string'],
            'site_url' => ['nullable', 'string'],
            'wp_version' => ['nullable', 'string'],
            'plugin_version' => ['nullable', 'string'],
        ]);

        $license = $licenseKeyService->findByPlainKey((string) $data['license_key']);
        $domain = $licenseKeyService->normalizeDomain(
            domain: $data['domain'] ?? null,
            siteUrl: $data['site_url'] ?? null
        );

        if (! $license || ! $license->isActiveAndValid()) {
            return response()->json(['error' => 'Invalid license'], 403);
        }

        if ($domain === '') {
            return response()->json(['error' => 'Invalid domain'], 422);
        }

        $organization = $license->workspace?->organization;
        if (! $organization || $organization->status !== 'active') {
            return response()->json(['error' => 'Workspace is not active'], 403);
        }

        $domainInOtherWorkspace = WorkspaceDomain::query()
            ->where('domain', $domain)
            ->where('workspace_id', '!=', $license->workspace_id)
            ->exists();

        if ($domainInOtherWorkspace) {
            return response()->json(['error' => 'Domain already registered to another workspace'], 409);
        }

        WorkspaceDomain::query()->updateOrCreate(
            [
                'workspace_id' => $license->workspace_id,
                'domain' => $domain,
            ],
            [
                'verified_at' => now(),
            ]
        );

        $clientSecret = $licenseKeyService->deriveClientSecret($license, $domain);

        Log::info('plugin.register_domain', [
            'workspace_id' => $license->workspace_id,
            'domain' => $domain,
            'license_hash_prefix' => substr($license->license_key_hash, 0, 12),
        ]);

        return response()->json([
            'ok' => true,
            'client_secret' => $clientSecret,
            'workspace' => [
                'id' => (string) $license->workspace_id,
                'organization_id' => (int) ($organization->id ?? 0),
                'organization_status' => (string) ($organization->status ?? ''),
            ],
        ]);
    }
}

