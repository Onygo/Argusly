<?php

namespace App\Services\OpportunityReview;

use App\Models\SignalDetection;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

class OpportunityReviewService
{
    public function candidatesQuery(Workspace $workspace): Builder
    {
        return SignalDetection::query()
            ->where('workspace_id', $workspace->id)
            ->open()
            ->where(function (Builder $query): void {
                $query->where('category', SignalDetection::CATEGORY_OPPORTUNITY_DETECTION)
                    ->orWhere('opportunity_score', '>=', 70);
            });
    }

    public function firstCandidate(Workspace $workspace): ?SignalDetection
    {
        return (clone $this->candidatesQuery($workspace))
            ->with(['clientSite'])
            ->orderByDesc('opportunity_score')
            ->orderByDesc('priority_score')
            ->oldest('created_at')
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(Workspace $workspace): array
    {
        $query = $this->candidatesQuery($workspace);

        return [
            'candidate_count' => (clone $query)->count(),
            'first_candidate' => $this->firstCandidate($workspace),
            'avg_opportunity_score' => round((float) ((clone $query)->avg('opportunity_score') ?? 0), 1),
            'high_confidence_count' => (clone $query)->where('confidence_score', '>=', 75)->count(),
            'latest_candidates' => (clone $query)
                ->with(['clientSite'])
                ->orderByDesc('opportunity_score')
                ->orderByDesc('last_seen_at')
                ->limit(12)
                ->get(),
        ];
    }
}
