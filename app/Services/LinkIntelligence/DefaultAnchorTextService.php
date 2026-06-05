<?php

namespace App\Services\LinkIntelligence;

use App\Contracts\LinkIntelligence\AnchorTextService;
use App\Models\ArticleEntity;
use App\Models\Draft;

class DefaultAnchorTextService implements AnchorTextService
{
    public function generateAnchorVariants(Draft $source, Draft $target): array
    {
        $max = (int) config('link_intelligence.limits.max_anchor_variants', 5);
        $variants = [];

        $variants[] = trim((string) $target->title);

        preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', (string) $target->content_html, $matches);
        foreach (($matches[1] ?? []) as $heading) {
            $variants[] = trim(strip_tags((string) $heading));
        }

        $sharedEntity = ArticleEntity::query()
            ->where('article_id', $target->id)
            ->where('entity_type', 'primary')
            ->value('entity');

        if ($sharedEntity) {
            $variants[] = 'Read more about ' . trim((string) $sharedEntity);
        }

        $variants[] = 'Related: ' . trim((string) $target->title);

        return collect($variants)
            ->map(fn ($variant) => trim((string) $variant))
            ->filter(fn ($variant) => $variant !== '')
            ->filter(fn ($variant) => mb_strlen($variant) <= 90)
            ->unique()
            ->take($max)
            ->values()
            ->all();
    }
}
