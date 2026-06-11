<?php

namespace Database\Factories;

use App\Enums\SignalCategory;
use App\Enums\SignalSeverity;
use App\Enums\SignalStatus;
use App\Enums\SignalType;
use App\Models\SignalEvent;
use App\Models\SignalMention;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalEvent>
 */
class SignalEventFactory extends Factory
{
    protected $model = SignalEvent::class;

    public function definition(): array
    {
        $mention = SignalMention::factory()->create();
        $observedAt = now()->subHours($this->faker->numberBetween(1, 24));

        return [
            'organization_id' => $mention->organization_id,
            'workspace_id' => $mention->workspace_id,
            'client_site_id' => $mention->client_site_id,
            'signal_mention_id' => $mention->id,
            'signal_entity_id' => $mention->signal_entity_id,
            'category' => SignalCategory::BRAND_VISIBILITY,
            'type' => SignalType::BRAND_MENTIONED,
            'severity' => SignalSeverity::INFO,
            'status' => SignalStatus::DETECTED,
            'topic' => 'AI visibility',
            'entity_name' => $mention->entity_name,
            'entity_key' => $mention->entity_key,
            'signal_strength' => 74,
            'confidence_score' => 82,
            'impact_score' => 61,
            'urgency_score' => 44,
            'observed_at' => $observedAt,
            'evidence' => [['label' => 'Mention context', 'value' => $mention->context]],
            'metrics' => ['mentions' => 1],
            'metadata' => ['source' => 'factory'],
            'dedupe_hash' => hash('sha256', (string) $mention->id.$observedAt->toIso8601String()),
        ];
    }
}
