<?php

namespace App\Services\Seo\Providers;

class YoastProvider implements SEOProviderInterface
{
    /**
     * @return array<string,string>
     */
    private function mapping(): array
    {
        return [
            '_yoast_wpseo_title' => 'seo_title',
            '_yoast_wpseo_metadesc' => 'seo_meta_description',
            '_yoast_wpseo_focuskw' => 'primary_keyword',
            '_yoast_wpseo_canonical' => 'seo_canonical',
            '_yoast_wpseo_opengraph-title' => 'seo_og_title',
            '_yoast_wpseo_opengraph-description' => 'seo_og_description',
            '_yoast_wpseo_opengraph-image' => 'seo_og_image',
        ];
    }

    public function key(): string
    {
        return 'yoast';
    }

    public function supportsMetaTitle(): bool
    {
        return true;
    }

    public function supportsMetaDescription(): bool
    {
        return true;
    }

    public function supportsCanonical(): bool
    {
        return true;
    }

    public function supportsOgTags(): bool
    {
        return true;
    }

    /**
     * @return array<int,string>
     */
    public function syncableFieldKeys(): array
    {
        return array_values(array_unique(array_values($this->mapping())));
    }

    /**
     * @param array<string,mixed> $seo
     * @return array<string,string>
     */
    public function mapToWordPressMeta(array $seo): array
    {
        $result = [];
        foreach ($this->mapping() as $metaKey => $seoField) {
            $candidate = trim((string) ($seo[$seoField] ?? ''));
            if ($candidate === '') {
                continue;
            }

            $result[$metaKey] = $candidate;
        }

        return $result;
    }
}
