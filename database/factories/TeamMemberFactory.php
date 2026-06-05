<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\TeamMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMember>
 */
class TeamMemberFactory extends Factory
{
    protected $model = TeamMember::class;

    public function definition(): array
    {
        $perspectives = [
            'First-person practitioner sharing hands-on experience',
            'Third-person analyst providing objective analysis',
            'Industry expert offering strategic insights',
            'Technical specialist with deep domain knowledge',
        ];

        $traits = [
            'Pragmatic, direct, results-focused',
            'Analytical, thorough, evidence-based',
            'Creative, innovative, forward-thinking',
            'Collaborative, empathetic, audience-focused',
        ];

        return [
            'organization_id' => null, // Must be set via forOrganization() or state()
            'name' => fake()->name(),
            'title' => fake()->jobTitle(),
            'email' => fake()->safeEmail(),
            'status' => TeamMember::STATUS_APPROVED,
            'role' => fake()->jobTitle(),
            'expertise' => fake()->sentence(6),
            'writing_perspective' => fake()->randomElement($perspectives),
            'personality_traits' => fake()->randomElement($traits),
            'profile_data' => [
                'expert_summary' => fake()->sentence(),
                'expertise_areas' => [fake()->word(), fake()->word()],
                'tone_traits' => ['Pragmatic', 'Clear'],
            ],
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $organization->id,
        ]);
    }
}
