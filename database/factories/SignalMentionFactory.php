<?php

namespace Database\Factories;

use App\Models\SignalEntity;
use App\Models\SignalMention;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalMention>
 */
class SignalMentionFactory extends Factory
{
    protected $model = SignalMention::class;

    public function definition(): array
    {
        $entity = SignalEntity::factory()->create(['entity_type' => 'brand']);
        $observedAt = now()->subHours($this->faker->numberBetween(1, 48));

        return [
            'organization_id' => $entity->organization_id,
            'workspace_id' => $entity->workspace_id,
            'client_site_id' => $entity->client_site_id,
            'signal_entity_id' => $entity->id,
            'source_type' => 'manual',
            'mention_type' => SignalMention::TYPE_BRAND,
            'entity_type' => 'brand',
            'entity_name' => $entity->entity_name,
            'entity_key' => $entity->entity_key,
            'url' => 'https://example.com/signals/'.$this->faker->unique()->slug(),
            'url_hash' => hash('sha256', $this->faker->unique()->url()),
            'context' => 'Argusly was mentioned in a relevant content operations discussion.',
            'sentiment_label' => 'neutral',
            'sentiment_score' => 0,
            'position_score' => 72,
            'confidence_score' => 84,
            'observed_at' => $observedAt,
            'metadata' => ['source' => 'factory'],
            'dedupe_hash' => hash('sha256', (string) $entity->id.$observedAt->toIso8601String().$this->faker->unique()->uuid()),
        ];
    }
}
