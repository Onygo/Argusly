<?php

namespace Database\Factories;

use App\Models\ContentAsset;
use App\Models\PublishingAction;
use App\Models\PublishingChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublishingAction>
 */
class PublishingActionFactory extends Factory
{
    protected $model = PublishingAction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $contentAsset = ContentAsset::factory()->create();

        return [
            'account_id' => $contentAsset->account_id,
            'brand_id' => $contentAsset->brand_id,
            'content_asset_id' => $contentAsset->id,
            'publishing_channel_id' => null,
            'language' => $contentAsset->language,
            'locale' => $contentAsset->locale,
            'action' => fake()->randomElement(PublishingAction::ACTIONS),
            'status' => fake()->randomElement(PublishingAction::STATUSES),
            'scheduled_at' => null,
            'published_at' => null,
            'external_id' => null,
            'external_url' => null,
            'request_payload' => ['demo' => true],
            'response_payload' => null,
            'error_message' => null,
            'created_by' => null,
        ];
    }

    public function forContentAsset(ContentAsset $contentAsset): static
    {
        return $this->state(fn () => [
            'account_id' => $contentAsset->account_id,
            'brand_id' => $contentAsset->brand_id,
            'content_asset_id' => $contentAsset->id,
            'language' => $contentAsset->language,
            'locale' => $contentAsset->locale,
        ]);
    }

    public function forPublishingChannel(PublishingChannel $channel): static
    {
        return $this->state(fn () => [
            'account_id' => $channel->account_id,
            'brand_id' => $channel->brand_id,
            'publishing_channel_id' => $channel->id,
        ]);
    }
}
