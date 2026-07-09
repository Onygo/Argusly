<?php

namespace App\Services\Mos\OperatingSystem;

use App\Models\MarketingInitiative;
use App\Models\MarketingObjective;
use App\Models\MarketingPriority;
use App\Services\AgenticMarketing\Intelligence\MarketingRecommendation;

class MarketingPriorityService
{
    public function __construct(
        private readonly MarketingTimeline $timeline,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(MarketingObjective|MarketingInitiative $subject, array $attributes): MarketingPriority
    {
        $objective = $subject instanceof MarketingObjective ? $subject : $subject->objective;
        $initiative = $subject instanceof MarketingInitiative ? $subject : null;

        $priority = MarketingPriority::query()->create(array_merge([
            'organization_id' => $subject->organization_id,
            'workspace_id' => $subject->workspace_id,
            'marketing_objective_id' => $objective?->id,
            'marketing_initiative_id' => $initiative?->id,
            'priority_level' => 'medium',
            'priority_score' => 50,
            'status' => 'active',
            'evidence_json' => [],
            'metadata_json' => [],
        ], $attributes));

        $this->timeline->recordModelLink(
            $subject,
            'priority.created',
            'Marketing priority created',
            $priority,
            $priority->name,
            [
                'priority_level' => $priority->priority_level,
                'priority_score' => $priority->priority_score,
                'confidence_score' => $priority->confidence_score,
            ],
        );

        return $priority;
    }

    public function fromRecommendation(MarketingObjective|MarketingInitiative $subject, MarketingRecommendation $recommendation): MarketingPriority
    {
        return $this->create($subject, [
            'name' => $recommendation->title,
            'priority_level' => $this->levelForScore($recommendation->priority),
            'priority_score' => $recommendation->priority,
            'confidence_score' => $recommendation->confidence,
            'reason' => $recommendation->summary,
            'evidence_json' => $recommendation->evidence->toArray(),
            'metadata_json' => [
                'recommendation_key' => $recommendation->key,
                'recommendation_type' => $recommendation->type,
                'supporting_insights' => $recommendation->supportingInsightKeys,
            ],
        ]);
    }

    private function levelForScore(int $score): string
    {
        return match (true) {
            $score >= 85 => 'critical',
            $score >= 70 => 'high',
            $score >= 40 => 'medium',
            default => 'low',
        };
    }
}
