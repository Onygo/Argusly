<?php

namespace App\Services\Seo\Providers;

class RankMathProvider implements SEOProviderInterface
{
    /**
     * @return array<string,string>
     */
    private function mapping(): array
    {
        return [
            'rank_math_title' => 'seo_title',
            'rank_math_description' => 'seo_meta_description',
            'rank_math_focus_keyword' => 'primary_keyword',
            'rank_math_canonical_url' => 'seo_canonical',
            'rank_math_facebook_title' => 'seo_og_title',
            'rank_math_facebook_description' => 'seo_og_description',
            'rank_math_facebook_image' => 'seo_og_image',
            'rank_math_twitter_title' => 'seo_twitter_title',
            'rank_math_twitter_description' => 'seo_twitter_description',
        ];
    }

    public function key(): string
    {
        return 'rankmath';
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
