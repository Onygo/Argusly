<?php

namespace App\Services\Seo;

use App\Models\ClientSite;
use Illuminate\Support\Str;

class SeoFieldSyncCapabilityResolver
{
    public function __construct(
        private readonly SeoProviderRegistry $providers,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function forSite(?ClientSite $site, bool $isActionable = true): array
    {
        $siteType = ClientSite::normalizeType((string) ($site?->type ?? ''));
        $provider = $this->providers->resolve((string) ($site?->seo_provider ?? 'none'));
        $providerKey = $provider->key();
        $providerLabel = $this->providerLabel($providerKey);
        $syncableSet = array_fill_keys($provider->syncableFieldKeys(), true);

        $supportsMetaDescription = (bool) ($site?->supports_meta_description ?? false)
            || isset($syncableSet['seo_meta_description']);
        $supportsCanonical = (bool) ($site?->supports_canonical ?? false)
            || isset($syncableSet['seo_canonical']);
        $supportsOg = (bool) ($site?->supports_og_tags ?? false)
            || isset($syncableSet['seo_og_title'])
            || isset($syncableSet['seo_og_description'])
            || isset($syncableSet['seo_og_image']);

        $fields = [
            $this->fieldStatusFor($siteType, $providerLabel, $isActionable, 'seo_title', 'Title', true),
            $this->fieldStatusFor($siteType, $providerLabel, $isActionable, 'seo_h1', 'H1', true),
            $this->fieldStatusFor($siteType, $providerLabel, $isActionable, 'seo_meta_description', 'Meta description', $supportsMetaDescription),
            $this->fieldStatusFor($siteType, $providerLabel, $isActionable, 'seo_canonical', 'Canonical URL', $supportsCanonical),
            $this->fieldStatusFor($siteType, $providerLabel, $isActionable, 'seo_og_title', 'OG title', $supportsOg || isset($syncableSet['seo_og_title'])),
            $this->fieldStatusFor($siteType, $providerLabel, $isActionable, 'seo_og_description', 'OG description', $supportsOg || isset($syncableSet['seo_og_description'])),
            $this->fieldStatusFor($siteType, $providerLabel, $isActionable, 'seo_twitter_title', 'Twitter title', isset($syncableSet['seo_twitter_title'])),
            $this->fieldStatusFor($siteType, $providerLabel, $isActionable, 'seo_twitter_description', 'Twitter description', isset($syncableSet['seo_twitter_description'])),
        ];

        $counts = [
            'sync' => 0,
            'advisory' => 0,
            'requires_provider' => 0,
        ];

        foreach ($fields as $field) {
            $status = (string) ($field['status'] ?? 'advisory');
            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }

        return [
            'site_type' => $siteType,
            'provider_key' => $providerKey,
            'provider_label' => $providerLabel,
            'is_actionable' => $isActionable,
            'fields' => $fields,
            'counts' => $counts,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function fieldStatusFor(
        string $siteType,
        string $providerLabel,
        bool $isActionable,
        string $fieldKey,
        string $fieldLabel,
        bool $supportsWordPressSync
    ): array {
        if (! $isActionable) {
            return $this->composeFieldStatus(
                key: $fieldKey,
                label: $fieldLabel,
                status: 'advisory',
                note: 'Advice only. No linked PublishLayer draft for apply.',
            );
        }

        if ($siteType !== ClientSite::TYPE_WORDPRESS) {
            $note = $siteType === ClientSite::TYPE_LARAVEL
                ? 'Advice only. Laravel connector sync is pull based.'
                : 'Advice only. WordPress sync requires a WordPress connector.';

            return $this->composeFieldStatus(
                key: $fieldKey,
                label: $fieldLabel,
                status: 'advisory',
                note: $note,
            );
        }

        if ($supportsWordPressSync) {
            return $this->composeFieldStatus(
                key: $fieldKey,
                label: $fieldLabel,
                status: 'sync',
                note: 'Can sync to WordPress with the current connector capabilities.',
            );
        }

        return $this->composeFieldStatus(
            key: $fieldKey,
            label: $fieldLabel,
            status: 'requires_provider',
            note: sprintf('%s does not expose this field mapping. Requires supported SEO plugin.', $providerLabel),
        );
    }

    /**
     * @return array<string,string>
     */
    private function composeFieldStatus(string $key, string $label, string $status, string $note): array
    {
        [$statusLabel, $badgeClass] = match ($status) {
            'sync' => ['Can sync to WordPress', 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700'],
            'requires_provider' => ['Requires supported SEO plugin', 'border-rose-500/30 bg-rose-500/10 text-rose-700'],
            default => ['Advice only', 'border-amber-500/30 bg-amber-500/10 text-amber-700'],
        };

        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'status_label' => $statusLabel,
            'status_badge_class' => $badgeClass,
            'note' => $note,
        ];
    }

    private function providerLabel(string $providerKey): string
    {
        return match (strtolower(trim($providerKey))) {
            'yoast' => 'Yoast SEO',
            'rankmath' => 'Rank Math',
            'aioseo' => 'AIOSEO',
            'publishlayer' => 'PublishLayer SEO',
            'none' => 'No SEO plugin detected',
            default => Str::headline($providerKey),
        };
    }
}
