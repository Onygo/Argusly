<?php

namespace App\Services\PluginUpdates;

use App\Models\LicenseKey;
use App\Models\PluginRelease;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class PluginDownloadTokenService
{
    public function issueToken(LicenseKey $licenseKey, string $domain, PluginRelease $release): string
    {
        $ttlSeconds = (int) config('publishlayer.plugin_updates.download_token_ttl_seconds', 300);

        $payload = [
            'license_key_id' => $licenseKey->id,
            'workspace_id' => (string) $licenseKey->workspace_id,
            'domain' => trim(strtolower($domain)),
            'release_id' => $release->id,
            'exp' => now()->addSeconds(max($ttlSeconds, 1))->timestamp,
        ];

        return Crypt::encryptString(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function parseToken(string $token): ?array
    {
        try {
            $raw = Crypt::decryptString($token);
        } catch (DecryptException) {
            return null;
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return null;
        }

        $exp = (int) ($payload['exp'] ?? 0);
        if ($exp <= 0 || now()->timestamp > $exp) {
            return null;
        }

        return $payload;
    }
}

