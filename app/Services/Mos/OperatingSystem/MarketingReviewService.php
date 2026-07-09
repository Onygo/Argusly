<?php

namespace App\Services\Mos\OperatingSystem;

use App\Models\MarketingInitiative;
use App\Models\MarketingObjective;
use App\Models\MarketingReview;
use App\Models\User;

class MarketingReviewService
{
    public function __construct(
        private readonly MarketingTimeline $timeline,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function request(MarketingObjective|MarketingInitiative $subject, array $attributes = []): MarketingReview
    {
        $objective = $subject instanceof MarketingObjective ? $subject : $subject->objective;
        $initiative = $subject instanceof MarketingInitiative ? $subject : null;

        $review = MarketingReview::query()->create(array_merge([
            'organization_id' => $subject->organization_id,
            'workspace_id' => $subject->workspace_id,
            'marketing_objective_id' => $objective?->id,
            'marketing_initiative_id' => $initiative?->id,
            'review_type' => 'operating_review',
            'status' => MarketingReview::STATUS_PENDING,
            'evidence_json' => [],
            'metadata_json' => [],
        ], $attributes));

        $this->timeline->record(
            $subject,
            'review.requested',
            'Marketing review requested',
            $review->summary,
            metadata: ['review_id' => $review->id, 'review_type' => $review->review_type],
        );

        return $review;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function decide(
        MarketingReview $review,
        string $decision,
        ?User $reviewer = null,
        ?string $summary = null,
        array $metadata = [],
    ): MarketingReview {
        $status = match ($decision) {
            'approve', 'approved' => MarketingReview::STATUS_APPROVED,
            'request_changes', 'changes_requested' => MarketingReview::STATUS_CHANGES_REQUESTED,
            'dismiss', 'dismissed' => MarketingReview::STATUS_DISMISSED,
            default => $decision,
        };

        $review->forceFill([
            'reviewer_id' => $reviewer?->id ?? $review->reviewer_id,
            'decision' => $decision,
            'status' => $status,
            'summary' => $summary ?? $review->summary,
            'reviewed_at' => now(),
            'metadata_json' => array_replace_recursive((array) $review->metadata_json, $metadata),
        ])->save();

        $subject = $review->initiative ?: $review->objective;

        if ($subject) {
            $this->timeline->record(
                $subject,
                'review.decided',
                'Marketing review decided',
                $decision,
                $reviewer,
                ['review_id' => $review->id, 'status' => $status] + $metadata,
            );
        }

        return $review->refresh();
    }
}
