<?php

namespace App\Services\Seo\Providers;

class AioSeoProvider implements SEOProviderInterface
{
    /**
     * @return array<string,string>
     */
    private function mapping(): array
    {
        return [
            '_aioseo_title' => 'seo_title',
            '_aioseo_description' => 'seo_meta_description',
            '_aioseo_focus_keyphrase' => 'primary_keyword',
            '_aioseo_canonical_url' => 'seo_canonical',
            '_aioseo_og_title' => 'seo_og_title',
            '_aioseo_og_description' => 'seo_og_description',
            '_aioseo_og_image' => 'seo_og_image',
            '_aioseo_twitter_title' => 'seo_twitter_title',
            '_aioseo_twitter_description' => 'seo_twitter_description',
        ];
    }

    public function key(): string
    {
        return 'aioseo';
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
