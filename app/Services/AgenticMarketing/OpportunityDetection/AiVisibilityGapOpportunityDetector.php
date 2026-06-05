<?php

namespace App\Services\AgenticMarketing\OpportunityDetection;

use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingObjective;
use App\Models\Content;

class AiVisibilityGapOpportunityDetector implements AgenticMarketingOpportunityDetector
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
            'ai_visibility_score',
            'aeo_score',
            'semantic_coverage_score',
        ])
            ->with('aiVisibilitySnapshots:id,content_id,provider,visibility_score,citation_count,avg_position,captured_at')
            ->where(function ($query): void {
                $query->where('ai_visibility_score', '<', 60)
                    ->orWhereHas('aiVisibilitySnapshots', function ($snapshotQuery): void {
                        $snapshotQuery
                            ->where('visibility_score', '<', 60)
                            ->orWhere('citation_count', 0);
                    });
            })
            ->orderBy('ai_visibility_score')
            ->limit(50)
            ->get()
            ->map(fn (Content $content): DetectedOpportunity => $this->opportunity($content))
            ->all();
    }

    private function opportunity(Content $content): DetectedOpportunity
    {
        $latestSnapshot = $content->aiVisibilitySnapshots->sortByDesc('captured_at')->first();
        $score = (int) ($content->ai_visibility_score ?? $latestSnapshot?->visibility_score ?? 0);
        $citations = $latestSnapshot ? (int) ($latestSnapshot->citation_count ?? 0) : null;

        return new DetectedOpportunity(
            title: 'Improve AI visibility for ' . (string) $content->title,
            type: AgenticMarketingOpportunityType::AiVisibility,
            priorityScore: $this->scoreFromSignals(54, $score > 0 ? max(0, 60 - $score) / 2 : 12, $citations === 0 ? 10 : 0),
            payload: [
                'detector' => 'ai_visibility_gaps',
                'content_id' => (string) $content->id,
                'signals' => [
                    'ai_visibility_score' => $score,
                    'aeo_score' => (int) ($content->aeo_score ?? 0),
                    'semantic_coverage_score' => (int) ($content->semantic_coverage_score ?? 0),
                    'latest_snapshot' => $latestSnapshot ? [
                        'provider' => (string) $latestSnapshot->provider,
                        'visibility_score' => (int) $latestSnapshot->visibility_score,
                        'citation_count' => $citations,
                        'avg_position' => $latestSnapshot->avg_position,
                        'captured_at' => optional($latestSnapshot->captured_at)->toIso8601String(),
                    ] : null,
                ],
            ],
            contentId: (string) $content->id,
        );
    }
}
