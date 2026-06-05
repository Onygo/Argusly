<?php

namespace App\Services\AgenticMarketing\OpportunityDetection;

use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingObjective;
use App\Models\Content;

class SeoIndexabilityOpportunityDetector implements AgenticMarketingOpportunityDetector
{
    use DetectsObjectiveContent;

    public function detect(AgenticMarketingObjective $objective): array
    {
        return $this->contentQuery($objective, [
            'id',
            'workspace_id',
            'client_site_id',
            'title',
            'language',
            'seo_title',
            'seo_meta_description',
            'seo_canonical',
            'robots_index',
            'robots_follow',
            'schema_type',
            'primary_keyword',
        ])
            ->with('indexationHealth:id,content_id,indexed,canonical_accepted,duplicate_detected,redirect_issue,crawled_not_indexed,noindex_detected,sitemap_status,health_score,canonical_url,google_selected_canonical,issues_json')
            ->where(function ($query): void {
                $query->where('robots_index', false)
                    ->orWhereNull('seo_title')
                    ->orWhereNull('seo_meta_description')
                    ->orWhereNull('schema_type')
                    ->orWhereHas('indexationHealth', function ($healthQuery): void {
                        $healthQuery
                            ->where('indexed', false)
                            ->orWhere('canonical_accepted', false)
                            ->orWhere('duplicate_detected', true)
                            ->orWhere('redirect_issue', true)
                            ->orWhere('crawled_not_indexed', true)
                            ->orWhere('noindex_detected', true)
                            ->orWhere('health_score', '<', 80);
                    });
            })
            ->limit(75)
            ->get()
            ->map(fn (Content $content): DetectedOpportunity => $this->opportunity($content))
            ->all();
    }

    private function opportunity(Content $content): DetectedOpportunity
    {
        $health = $content->indexationHealth;
        $issues = array_values(array_filter([
            $content->robots_index === false ? 'robots_noindex' : null,
            trim((string) $content->seo_title) === '' ? 'missing_seo_title' : null,
            trim((string) $content->seo_meta_description) === '' ? 'missing_meta_description' : null,
            trim((string) $content->schema_type) === '' ? 'missing_schema_type' : null,
            $health && $health->indexed === false ? 'not_indexed' : null,
            $health && $health->canonical_accepted === false ? 'canonical_not_accepted' : null,
            $health && $health->duplicate_detected ? 'duplicate_detected' : null,
            $health && $health->redirect_issue ? 'redirect_issue' : null,
            $health && $health->crawled_not_indexed ? 'crawled_not_indexed' : null,
            $health && $health->noindex_detected ? 'noindex_detected' : null,
        ]));
        $healthScore = $health ? (int) ($health->health_score ?? 0) : null;

        return new DetectedOpportunity(
            title: 'Resolve SEO indexability signals for ' . (string) $content->title,
            type: AgenticMarketingOpportunityType::SeoIndexability,
            priorityScore: $this->scoreFromSignals(
                52,
                min(24, count($issues) * 6),
                $healthScore !== null && $healthScore > 0 ? max(0, 80 - $healthScore) / 2 : 0,
            ),
            payload: [
                'detector' => 'seo_indexability',
                'content_id' => (string) $content->id,
                'signals' => [
                    'issues' => $issues,
                    'robots_index' => $content->robots_index,
                    'robots_follow' => $content->robots_follow,
                    'schema_type' => (string) ($content->schema_type ?? ''),
                    'health_score' => $healthScore,
                    'sitemap_status' => (string) ($health?->sitemap_status ?? ''),
                    'canonical_url' => (string) ($health?->canonical_url ?? $content->seo_canonical ?? ''),
                    'google_selected_canonical' => (string) ($health?->google_selected_canonical ?? ''),
                ],
            ],
            contentId: (string) $content->id,
        );
    }
}
