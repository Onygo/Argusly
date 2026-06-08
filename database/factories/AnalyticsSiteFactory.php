<?php

namespace Database\Factories;

use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsSite>
 */
class AnalyticsSiteFactory extends Factory
{
    protected $model = AnalyticsSite::class;

    public function definition(): array
    {
        return [
            'public_key' => AnalyticsSite::generatePublicKey(),
            'verification_token' => AnalyticsSite::generateVerificationToken(),
            'allowed_domains' => [],
            'verified_at' => null,
            'retention_days' => 365,
            'is_enabled' => true,
            'respect_dnt' => true,
            'sampling_rate' => 100,
            'flags' => [],
        ];
    }

    public function forClientSite(ClientSite $clientSite): static
    {
        return $this->state(fn (array $attributes) => [
            'client_site_id' => $clientSite->id,
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verified_at' => now(),
        ]);
    }

    public function internallyVerified(string $domain = 'argusly.com'): static
    {
        return $this->state(fn (array $attributes) => [
            'verified_at' => now(),
            'flags' => [
                'internally_verified' => true,
                'internal_domain' => $domain,
                'verified_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_enabled' => false,
        ]);
    }
}
