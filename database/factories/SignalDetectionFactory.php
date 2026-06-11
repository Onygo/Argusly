<?php

namespace Database\Factories;

use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Models\SignalDetection;
use App\Models\SignalEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalDetection>
 */
class SignalDetectionFactory extends Factory
{
    protected $model = SignalDetection::class;

    public function definition(): array
    {
        $event = SignalEvent::factory()->create();
        $topic = (string) ($event->topic ?: 'AI visibility');

        return [
            'organization_id' => $event->organization_id,
            'workspace_id' => $event->workspace_id,
            'client_site_id' => $event->client_site_id,
            'category' => SignalDetection::CATEGORY_BRAND_MONITORING,
            'type' => 'brand_visibility_change',
            'status' => SignalStatus::DETECTED,
            'title' => 'Brand visibility movement for '.$topic,
            'summary' => 'Stored signal evidence indicates a meaningful brand visibility movement.',
            'primary_topic' => $topic,
            'primary_entity' => $event->entity_name,
            'severity' => SignalSeverity::MEDIUM,
            'priority_score' => 68,
            'confidence_score' => 82,
            'impact_score' => 64,
            'urgency_score' => 48,
            'risk_score' => 30,
            'opportunity_score' => 72,
            'score_breakdown' => ['formula' => 'factory'],
            'evidence_summary' => ['signals' => 1],
            'recommended_actions' => [['type' => 'review', 'label' => 'Review evidence']],
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
            'dedupe_hash' => hash('sha256', (string) $event->id.'brand_visibility_change'),
            'metadata' => ['source' => 'factory'],
        ];
    }
}
