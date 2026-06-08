<?php

namespace App\Services\CompetitorIntelligence;

use App\Models\Content;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class CompetitorCoverageComparator
{
    /**
     * @return array{argusly_content_count:int,coverage_status:string,overlap_score:float,matches:array<int,array<string,mixed>>}
     */
    public function compare(Workspace $workspace, string $topic, ?string $clientSiteId = null): array
    {
        $needle = strtolower(trim($topic));
        $query = Content::query()
            ->where('workspace_id', $workspace->id)
            ->when($clientSiteId, fn ($query) => $query->where('client_site_id', $clientSiteId))
            ->where(function ($query) use ($needle): void {
                $query->whereRaw('LOWER(title) LIKE ?', ['%' . $needle . '%'])
                    ->orWhereRaw("LOWER(COALESCE(primary_keyword, '')) LIKE ?", ['%' . $needle . '%'])
                    ->orWhereRaw("LOWER(COALESCE(seo_title, '')) LIKE ?", ['%' . $needle . '%'])
                    ->orWhereRaw("LOWER(COALESCE(seo_meta_description, '')) LIKE ?", ['%' . $needle . '%']);
            })
            ->limit(8)
            ->get(['id', 'title', 'primary_keyword', 'content_health_score', 'aeo_score', 'answer_block_score', 'published_url']);

        $count = $query->count();
        $weakMatches = $query->filter(fn (Content $content): bool => $this->isWeak($content));

        return [
            'argusly_content_count' => $count,
            'coverage_status' => match (true) {
                $count === 0 => 'missing',
                $weakMatches->isNotEmpty() => 'weak',
                default => 'covered',
            },
            'overlap_score' => $this->overlapScore($query),
            'matches' => $query->map(fn (Content $content): array => [
                'id' => (string) $content->id,
                'title' => $content->title,
                'primary_keyword' => $content->primary_keyword,
                'content_health_score' => $content->content_health_score,
                'aeo_score' => $content->aeo_score,
                'answer_block_score' => $content->answer_block_score,
                'published_url' => $content->published_url,
                'weak' => $this->isWeak($content),
            ])->values()->all(),
        ];
    }

    private function isWeak(Content $content): bool
    {
        return ($content->content_health_score !== null && (int) $content->content_health_score < 70)
            || ($content->aeo_score !== null && (int) $content->aeo_score < 70)
            || ($content->answer_block_score !== null && (int) $content->answer_block_score < 60);
    }

    /**
     * @param Collection<int, Content> $matches
     */
    private function overlapScore(Collection $matches): float
    {
        if ($matches->isEmpty()) {
            return 0.0;
        }

        $strong = $matches->reject(fn (Content $content): bool => $this->isWeak($content))->count();

        return min(100.0, 35.0 + ($strong * 22.0) + (($matches->count() - $strong) * 10.0));
    }
}
