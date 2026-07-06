<?php

namespace Database\Factories;

use App\Models\ScheduledPageIntelligenceBriefing;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PageIntelligence\Reports\ReportBuilder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledPageIntelligenceBriefing>
 */
class ScheduledPageIntelligenceBriefingFactory extends Factory
{
    protected $model = ScheduledPageIntelligenceBriefing::class;

    public function definition(): array
    {
        $workspace = Workspace::factory()->create();

        return [
            'workspace_id' => $workspace->id,
            'client_site_id' => null,
            'report_type' => ReportBuilder::TYPE_WEEKLY,
            'market_pack_key' => null,
            'frequency' => ScheduledPageIntelligenceBriefing::FREQUENCY_WEEKLY,
            'day_of_week' => 1,
            'day_of_month' => null,
            'timezone' => 'UTC',
            'recipients_json' => [],
            'delivery_channels_json' => [],
            'delivery_state_json' => ['status' => 'not_delivered'],
            'is_active' => true,
            'last_generated_at' => null,
            'last_failed_at' => null,
            'last_error' => null,
            'failure_count' => 0,
            'next_run_at' => now()->startOfDay()->addDay(),
            'scheduler_claimed_at' => null,
            'scheduler_claim_expires_at' => null,
            'scheduler_claim_token' => null,
            'created_by' => User::factory()->create([
                'organization_id' => $workspace->organization_id,
                'role' => 'owner',
                'active' => true,
                'approved_at' => now(),
                'email_code_verified_at' => now(),
            ])->id,
        ];
    }

    public function due(): static
    {
        return $this->state(fn (): array => [
            'next_run_at' => now()->subMinute(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
