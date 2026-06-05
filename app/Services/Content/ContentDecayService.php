<?php

namespace App\Services\Content;

use App\Enums\ContentDecayRiskLevel;
use App\Models\Content;
use App\Models\ContentLifecycleEvent;

class ContentDecayService
{
    /**
     * @param  array<string,mixed>  $metrics
     * @return array{level:ContentDecayRiskLevel,reasons:array<int,string>,should_refresh:bool}
     */
    public function detect(Content $content, array $metrics): array
    {
        $reasons = collect([
            ($metrics['freshness_score'] ?? 100) < 45 ? 'Content age exceeds refresh threshold.' : null,
            ($metrics['ai_visibility_score'] ?? 100) < 45 ? 'AI visibility is below the target baseline.' : null,
            ($metrics['answer_block_score'] ?? 100) < 40 ? 'Answer blocks are missing or incomplete.' : null,
            ($metrics['semantic_coverage_score'] ?? 100) < 50 ? 'Semantic coverage is thin for the topic.' : null,
            ($metrics['competitor_freshness_risk'] ?? 0) >= 70 ? 'Competitors appear fresher on this topic.' : null,
        ])->filter()->values()->all();

        $level = match (true) {
            ($metrics['content_health_score'] ?? 100) < 35 => ContentDecayRiskLevel::CRITICAL,
            ($metrics['content_health_score'] ?? 100) < 50 => ContentDecayRiskLevel::HIGH,
            ($metrics['content_health_score'] ?? 100) < 70 => ContentDecayRiskLevel::MEDIUM,
            default => ContentDecayRiskLevel::LOW,
        };

        return [
            'level' => $level,
            'reasons' => $reasons,
            'should_refresh' => in_array($level, [ContentDecayRiskLevel::HIGH, ContentDecayRiskLevel::CRITICAL], true),
        ];
    }

    /**
     * @param  array<string,mixed>  $metrics
     */
    public function recordIfNeeded(Content $content, array $metrics): ?ContentLifecycleEvent
    {
        $detection = $this->detect($content, $metrics);

        if (! $detection['should_refresh']) {
            return null;
        }

        return ContentLifecycleEvent::create([
            'content_id' => $content->id,
            'from_stage' => $content->lifecycleStageEnum()->value,
            'to_stage' => $content->lifecycleStageEnum()->value,
            'event_type' => ContentLifecycleEvent::TYPE_DECAY_DETECTED,
            'actor_type' => ContentLifecycleEvent::ACTOR_SYSTEM,
            'notes' => 'Content intelligence detected decay risk.',
            'metadata' => [
                'decay_risk_level' => $detection['level']->value,
                'reasons' => $detection['reasons'],
            ],
        ]);
    }
}
