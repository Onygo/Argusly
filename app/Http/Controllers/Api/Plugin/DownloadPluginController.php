<?php

namespace App\Http\Controllers\Api\Plugin;

use App\Http\Controllers\Controller;
use App\Models\LicenseKey;
use App\Models\PluginRelease;
use App\Models\WorkspaceDomain;
use App\Services\PluginUpdates\PluginDownloadTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DownloadPluginController extends Controller
{
    public function __invoke(string $token, Request $request, PluginDownloadTokenService $pluginDownloadTokenService)
    {
        $payload = $pluginDownloadTokenService->parseToken($token);
        if (! $payload) {
            return response()->json(['error' => 'Invalid or expired token'], 403);
        }

        $license = LicenseKey::query()->with('workspace.organization')->find($payload['license_key_id'] ?? null);
        $release = PluginRelease::query()->find($payload['release_id'] ?? null);
        $domain = (string) ($payload['domain'] ?? '');

        if (! $license || ! $release || $domain === '') {
            return response()->json(['error' => 'Invalid download context'], 403);
        }

        if (
            ! $license->isActiveAndValid() ||
            ! $license->workspace ||
            ! $license->workspace->organization ||
            $license->workspace->organization->status !== 'active'
        ) {
            return response()->json(['error' => 'License inactive'], 403);
        }

        $domainAllowed = WorkspaceDomain::query()
            ->where('workspace_id', $license->workspace_id)
            ->where('domain', $domain)
            ->exists();

        if (! $domainAllowed) {
            return response()->json(['error' => 'Domain not allowed'], 403);
        }

        $disk = (string) config('publishlayer.plugin_updates.disk', 'local');
        $path = (string) $release->zip_storage_path;

        if (! Storage::disk($disk)->exists($path)) {
            return response()->json(['error' => 'Release archive not found'], 404);
        }

        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            return response()->json(['error' => 'Unable to read release archive'], 500);
        }

        return response()->streamDownload(
            static function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            'publishlayer-connector-' . $release->version . '.zip',
            ['Content-Type' => 'application/zip']
        );
    }
}

