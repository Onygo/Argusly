<?php

namespace App\Services\Seo\Providers;

class PublishLayerProvider implements SEOProviderInterface
{
    /**
     * @return array<string,string>
     */
    private function mapping(): array
    {
        return [
            '_pl_seo_title' => 'seo_title',
            '_pl_seo_meta_description' => 'seo_meta_description',
            '_pl_seo_focus_keyword' => 'primary_keyword',
            '_pl_seo_canonical' => 'seo_canonical',
            '_pl_seo_og_title' => 'seo_og_title',
            '_pl_seo_og_description' => 'seo_og_description',
            '_pl_seo_og_image' => 'seo_og_image',
            '_pl_seo_twitter_title' => 'seo_twitter_title',
            '_pl_seo_twitter_description' => 'seo_twitter_description',
        ];
    }

    public function key(): string
    {
        return 'publishlayer';
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
