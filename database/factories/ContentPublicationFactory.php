<?php

namespace Database\Factories;

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentPublication>
 */
class ContentPublicationFactory extends Factory
{
    protected $model = ContentPublication::class;

    public function definition(): array
    {
        return [
            'content_id' => Content::factory(),
            'destination_id' => null,
            'client_site_id' => ClientSite::factory(),
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'remote_id' => null,
            'remote_type' => 'post',
            'remote_url' => null,
            'remote_status' => null,
            'delivery_status' => ContentPublication::STATUS_PENDING,
            'payload_checksum' => null,
            'last_verified_at' => null,
            'last_delivered_at' => null,
            'last_error_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'meta' => [],
        ];
    }

    public function delivered(): static
    {
        return $this->state(fn (): array => [
            'remote_id' => (string) $this->faker->numberBetween(1000, 99999),
            'remote_url' => $this->faker->url(),
            'remote_status' => ContentPublication::REMOTE_PUBLISHED,
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
            'last_delivered_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'delivery_status' => ContentPublication::STATUS_FAILED,
            'last_error_at' => now(),
            'last_error_code' => '500',
            'last_error_message' => 'Remote server error',
        ]);
    }

    public function missingRemote(): static
    {
        return $this->state(fn (): array => [
            'remote_id' => null,
            'remote_status' => null,
            'delivery_status' => ContentPublication::STATUS_MISSING_REMOTE,
            'meta' => [
                'previous_remote_ids' => [(string) $this->faker->numberBetween(1000, 99999)],
            ],
        ]);
    }

    public function wordpress(): static
    {
        return $this->state(fn (): array => [
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
        ]);
    }

    public function laravel(): static
    {
        return $this->state(fn (): array => [
            'provider' => ContentPublication::PROVIDER_LARAVEL,
        ]);
    }

    public function forDestination(ContentDestination $destination): static
    {
        return $this->state(fn (): array => [
            'destination_id' => $destination->id,
            'client_site_id' => null,
        ]);
    }
}
