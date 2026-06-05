<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class CampaignOrchestrationSeeder extends Seeder
{
    public function run(): void
    {
        // Campaign orchestration records are tenant-specific and may contain
        // credentials, brand voice rules, or strategic plans. Production
        // provisioning should create them from onboarding or admin workflows.
    }
}
