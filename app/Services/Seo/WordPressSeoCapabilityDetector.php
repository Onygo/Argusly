<?php

namespace App\Services\Seo;

use App\Services\Seo\Providers\SEOProviderInterface;

class WordPressSeoCapabilityDetector
{
    public function __construct(
        private readonly SeoProviderRegistry $providers,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function detect(array $payload): array
    {
        $candidates = $this->normalizePluginCandidates($payload);
        $provider = $this->resolveProvider($payload, $candidates);

        return [
            'seo_provider' => $provider->key(),
            'supports_meta_title' => $provider->supportsMetaTitle(),
            'supports_meta_description' => $provider->supportsMetaDescription(),
            'supports_canonical' => $provider->supportsCanonical(),
            'supports_og_tags' => $provider->supportsOgTags(),
            'detected_plugins' => $candidates,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function normalizePluginCandidates(array $payload): array
    {
        $plugins = [];
        $sources = [
            $payload['plugins'] ?? null,
            $payload['active_plugins'] ?? null,
            data_get($payload, 'capabilities.plugins'),
            data_get($payload, 'capabilities.active_plugins'),
            data_get($payload, 'capabilities.seo.plugins'),
            data_get($payload, 'capabilities.seo.active_plugins'),
            data_get($payload, 'connector_meta.plugins'),
            data_get($payload, 'connector_meta.active_plugins'),
        ];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            foreach ($source as $row) {
                if (is_string($row)) {
                    $plugins[] = strtolower(trim($row));
                    continue;
                }

                if (! is_array($row)) {
                    continue;
                }

                foreach (['slug', 'path', 'plugin', 'name'] as $key) {
                    $value = strtolower(trim((string) ($row[$key] ?? '')));
                    if ($value !== '') {
                        $plugins[] = $value;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($plugins)));
    }

    /**
     * @param array<int,string> $candidates
     */
    private function resolveProvider(array $payload, array $candidates): SEOProviderInterface
    {
        $explicitProvider = $this->normalizeProviderCandidate((string) data_get($payload, 'capabilities.seo.provider', ''));
        if ($explicitProvider !== null) {
            return $this->providers->resolve($explicitProvider);
        }

        return $this->resolveProviderFromCandidates($candidates);
    }

    /**
     * @param array<int,string> $candidates
     */
    private function resolveProviderFromCandidates(array $candidates): SEOProviderInterface
    {
        if ($this->hasAnySignature($candidates, ['wordpress-seo', 'wp-seo.php', 'yoast'])) {
            return $this->providers->resolve('yoast');
        }

        if ($this->hasAnySignature($candidates, ['rank-math', 'rankmath', 'seo-by-rank-math'])) {
            return $this->providers->resolve('rankmath');
        }

        if ($this->hasAnySignature($candidates, ['all-in-one-seo-pack', 'aioseo', 'all in one seo'])) {
            return $this->providers->resolve('aioseo');
        }

        if ($this->hasAnySignature($candidates, ['publishlayer/publishlayer.php', 'publishlayer'])) {
            return $this->providers->resolve('publishlayer');
        }

        return $this->providers->resolve('none');
    }

    /**
     * @param array<int,string> $candidates
     * @param array<int,string> $signatures
     */
    private function hasAnySignature(array $candidates, array $signatures): bool
    {
        foreach ($candidates as $candidate) {
            foreach ($signatures as $signature) {
                if (str_contains($candidate, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeProviderCandidate(string $provider): ?string
    {
        $normalized = strtolower(trim($provider));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'yoast' => 'yoast',
            'rankmath', 'rank_math' => 'rankmath',
            'aioseo', 'all_in_one_seo' => 'aioseo',
            'publishlayer', 'publishlayer_wp', 'pl' => 'publishlayer',
            default => null,
        };
    }
}
