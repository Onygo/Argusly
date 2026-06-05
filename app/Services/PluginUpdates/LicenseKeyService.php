<?php

namespace App\Services\PluginUpdates;

use App\Models\LicenseKey;
use App\Models\WorkspaceDomain;
use Illuminate\Support\Str;

class LicenseKeyService
{
    public function hashLicenseKey(string $plainLicenseKey): string
    {
        return hash('sha256', trim($plainLicenseKey));
    }

    public function findByPlainKey(string $plainLicenseKey): ?LicenseKey
    {
        $hash = $this->hashLicenseKey($plainLicenseKey);

        return LicenseKey::query()
            ->with('workspace.organization')
            ->where('license_key_hash', $hash)
            ->first();
    }

    public function normalizeDomain(?string $domain, ?string $siteUrl = null): string
    {
        $value = trim((string) ($domain ?: $siteUrl ?: ''));
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '://')) {
            $host = (string) parse_url($value, PHP_URL_HOST);
        } else {
            $host = preg_split('/[\/\s]/', $value)[0] ?? '';
        }

        $host = strtolower(trim($host));
        return Str::startsWith($host, 'www.') ? substr($host, 4) : $host;
    }

    public function deriveClientSecret(LicenseKey $licenseKey, string $domain): string
    {
        $appKey = (string) config('app.key');
        $normalizedDomain = $this->normalizeDomain($domain);

        return hash_hmac(
            'sha256',
            implode('|', [
                $licenseKey->license_key_hash,
                (string) $licenseKey->workspace_id,
                $normalizedDomain,
            ]),
            $appKey
        );
    }

    public function domainBelongsToWorkspace(LicenseKey $licenseKey, string $domain): bool
    {
        $normalizedDomain = $this->normalizeDomain($domain);

        if ($normalizedDomain === '') {
            return false;
        }

        return WorkspaceDomain::query()
            ->where('workspace_id', $licenseKey->workspace_id)
            ->where('domain', $normalizedDomain)
            ->exists();
    }
}

