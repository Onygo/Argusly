<?php

namespace Database\Factories;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    public function definition(): array
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'factory-org'],
            ['name' => 'Factory Organization', 'status' => Organization::STATUS_ACTIVE, 'approved_at' => now()]
        );

        $workspace = Workspace::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Factory Workspace'],
            ['organization_id' => $organization->id, 'name' => 'Factory Workspace']
        );

        return [
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'platform' => SocialPlatform::LINKEDIN,
            'account_type' => 'organization',
            'display_name' => $this->faker->company().' LinkedIn',
            'platform_account_id' => $this->faker->uuid(),
            'status' => SocialAccountStatus::OAUTH_PENDING,
            'oauth' => ['status' => 'placeholder'],
            'token_ref' => [],
            'profile' => [],
            'publishing_rules' => ['approval_required' => true],
            'rate_limit_policy' => ['bucket' => 'publish'],
        ];
    }

    public function connected(): static
    {
        return $this->state(fn (): array => [
            'status' => SocialAccountStatus::CONNECTED,
            'connected_at' => now(),
        ]);
    }
}
