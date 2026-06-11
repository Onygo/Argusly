<?php

namespace Database\Factories;

use App\Enums\SignalStatus;
use App\Models\SignalFeedItem;
use App\Models\SignalSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalFeedItem>
 */
class SignalFeedItemFactory extends Factory
{
    protected $model = SignalFeedItem::class;

    public function definition(): array
    {
        $source = SignalSource::factory()->create();
        $url = 'https://example.com/'.$this->faker->slug();
        $body = 'Argusly is mentioned alongside CompetitorOS in AI visibility discussions.';

        return [
            'organization_id' => $source->organization_id,
            'workspace_id' => $source->workspace_id,
            'client_site_id' => $source->client_site_id,
            'signal_source_id' => $source->id,
            'external_id' => $this->faker->uuid(),
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'title' => 'AI visibility market update',
            'summary' => 'A market update mentioning Argusly.',
            'body' => $body,
            'author' => $this->faker->name(),
            'published_at' => now()->subDay(),
            'fetched_at' => now(),
            'language' => 'en',
            'raw_payload' => ['factory' => true],
            'content_hash' => hash('sha256', $url.'|'.$body),
            'processing_status' => SignalStatus::NEW->value,
        ];
    }
}
