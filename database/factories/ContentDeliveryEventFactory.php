<?php

namespace Database\Factories;

use App\Models\ContentDeliveryEvent;
use App\Models\ContentPublication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentDeliveryEvent>
 */
class ContentDeliveryEventFactory extends Factory
{
    protected $model = ContentDeliveryEvent::class;

    public function definition(): array
    {
        return [
            'content_publication_id' => ContentPublication::factory(),
            'event_type' => ContentDeliveryEvent::TYPE_CREATE_REMOTE,
            'status' => ContentDeliveryEvent::STATUS_SUCCESS,
            'message' => 'Remote resource created successfully.',
            'request_payload_json' => ['title' => $this->faker->sentence()],
            'response_payload_json' => ['wp_post_id' => (string) $this->faker->numberBetween(1000, 99999)],
            'http_status' => 201,
            'correlation_id' => $this->faker->uuid(),
            'duration_ms' => $this->faker->numberBetween(100, 2000),
        ];
    }

    public function create(): static
    {
        return $this->state(fn (): array => [
            'event_type' => ContentDeliveryEvent::TYPE_CREATE_REMOTE,
            'status' => ContentDeliveryEvent::STATUS_SUCCESS,
            'message' => 'Remote resource created successfully.',
            'http_status' => 201,
        ]);
    }

    public function update(): static
    {
        return $this->state(fn (): array => [
            'event_type' => ContentDeliveryEvent::TYPE_UPDATE_REMOTE,
            'status' => ContentDeliveryEvent::STATUS_SUCCESS,
            'message' => 'Remote resource updated successfully.',
            'http_status' => 200,
        ]);
    }

    public function recreate(): static
    {
        return $this->state(fn (): array => [
            'event_type' => ContentDeliveryEvent::TYPE_RECREATE_REMOTE,
            'status' => ContentDeliveryEvent::STATUS_SUCCESS,
            'message' => 'Remote resource recreated. Previous ID: 12345',
            'http_status' => 201,
        ]);
    }

    public function verify(): static
    {
        return $this->state(fn (): array => [
            'event_type' => ContentDeliveryEvent::TYPE_VERIFY_REMOTE,
            'status' => ContentDeliveryEvent::STATUS_SUCCESS,
            'message' => 'Remote resource verified to exist.',
            'http_status' => 200,
        ]);
    }

    public function failure(): static
    {
        return $this->state(fn (): array => [
            'event_type' => ContentDeliveryEvent::TYPE_FAIL_REMOTE,
            'status' => ContentDeliveryEvent::STATUS_FAILED,
            'message' => '[500] Internal server error',
            'http_status' => 500,
            'response_payload_json' => ['error' => 'Internal server error'],
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => ContentDeliveryEvent::STATUS_PENDING,
            'http_status' => null,
            'response_payload_json' => null,
        ]);
    }
}
