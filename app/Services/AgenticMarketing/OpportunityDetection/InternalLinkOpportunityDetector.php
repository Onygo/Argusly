<?php

namespace App\Services\AgenticMarketing\OpportunityDetection;

use App\Enums\AgenticMarketingOpportunityType;
use App\Models\AgenticMarketingObjective;
use App\Models\LinkOpportunity;
use Illuminate\Support\Collection;

class InternalLinkOpportunityDetector implements AgenticMarketingOpportunityDetector
{
    use DetectsObjectiveContent;

    public function detect(AgenticMarketingObjective $objective): array
    {
        if (! $objective->workspace_id) {
            return [];
        }

        return LinkOpportunity::query()
            ->where('workspace_id', $objective->workspace_id)
            ->where('status', LinkOpportunity::STATUS_SUGGESTED)
            ->when($objective->client_site_id, function ($query) use ($objective): void {
                $query->whereHas('sourceContent', fn ($contentQuery) => $contentQuery->where('client_site_id', $objective->client_site_id));
            })
            ->with([
                'sourceContent:id,title,language,client_site_id',
                'targetContent:id,title,language,client_site_id',
            ])
            ->orderByDesc('relevance_score')
            ->limit(100)
            ->get()
            ->groupBy('source_content_id')
            ->map(fn (Collection $group): DetectedOpportunity => $this->opportunity($group))
            ->values()
            ->all();
    }

    private function opportunity(Collection $group): DetectedOpportunity
    {
        /** @var LinkOpportunity $first */
        $first = $group->sortByDesc('relevance_score')->first();
        $source = $first->sourceContent;
        $signalRows = $group
            ->sortByDesc('relevance_score')
            ->take(8)
            ->map(fn (LinkOpportunity $row): array => [
                'link_opportunity_id' => (string) $row->id,
                'target_content_id' => (string) $row->target_content_id,
                'target_title' => (string) ($row->targetContent?->title ?? ''),
                'anchor_text_suggestion' => (string) ($row->anchor_text_suggestion ?? ''),
                'relevance_score' => round((float) ($row->relevance_score ?? 0), 4),
            ])
            ->values()
            ->all();

        return new DetectedOpportunity(
            title: 'Improve internal links for ' . (string) ($source?->title ?? 'content'),
            type: AgenticMarketingOpportunityType::InternalLinks,
            priorityScore: $this->scoreFromSignals(50, min(25, $group->count() * 5), (int) round((float) ($first->relevance_score ?? 0) * 20)),
            payload: [
                'detector' => 'internal_links',
                'content_id' => (string) $first->source_content_id,
                'signals' => [
                    'suggested_link_count' => $group->count(),
                    'link_opportunities' => $signalRows,
                ],
            ],
            contentId: (string) $first->source_content_id,
        );
    }
}
