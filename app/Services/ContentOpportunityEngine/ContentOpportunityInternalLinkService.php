<?php

namespace App\Services\ContentOpportunityEngine;

use App\Models\Content;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class ContentOpportunityInternalLinkService
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function supportingContent(Workspace $workspace, string $topic, array $entities = [], ?string $clientSiteId = null): array
    {
        $terms = collect(array_merge([$topic], $entities))
            ->map(fn (string $term): string => strtolower(trim($term)))
            ->filter()
            ->unique()
            ->take(8);

        if ($terms->isEmpty()) {
            return [];
        }

        $content = Content::query()
            ->where('workspace_id', $workspace->id)
            ->when($clientSiteId, fn ($query) => $query->where('client_site_id', $clientSiteId))
            ->latest('updated_at')
            ->limit(120)
            ->get(['id', 'title', 'primary_keyword', 'published_url', 'content_health_score', 'aeo_score']);

        return $this->rank($content, $terms)->take(6)->values()->all();
    }

    /**
     * @param Collection<int, Content> $content
     * @param Collection<int, string> $terms
     * @return Collection<int,array<string,mixed>>
     */
    private function rank(Collection $content, Collection $terms): Collection
    {
        return $content
            ->map(function (Content $item) use ($terms): array {
                $haystack = strtolower(trim($item->title . ' ' . $item->primary_keyword));
                $score = $terms->sum(fn (string $term): int => str_contains($haystack, $term) ? 2 : (str_contains($haystack, strtok($term, ' ') ?: $term) ? 1 : 0));

                return [
                    'id' => (string) $item->id,
                    'title' => $item->title,
                    'primary_keyword' => $item->primary_keyword,
                    'url' => $item->published_url,
                    'score' => $score,
                    'anchor_suggestion' => $item->primary_keyword ?: $item->title,
                ];
            })
            ->filter(fn (array $item): bool => (int) $item['score'] > 0)
            ->sortByDesc('score');
    }
}
