<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call([
            \Database\Seeders\AdminUserSeeder::class,
            \Database\Seeders\CreditActionsSeeder::class,
            \Database\Seeders\LlmSourceRulesSeeder::class,
            \Database\Seeders\TaxonomySetSeeder::class,
            \Database\Seeders\ProductUpdatesSeeder::class,
            \Database\Seeders\MarketingPricingPageSeeder::class,
        ]);

        if (app()->environment(['local', 'demo'])) {
            $organization = \App\Models\Organization::firstOrCreate(
                ['slug' => 'demo-org'],
                [
                    'name' => 'Demo Organization',
                    'status' => 'active',
                    'approved_at' => now(),
                ]
            );

            $workspace = \App\Models\Workspace::firstOrCreate(
                ['organization_id' => $organization->id, 'name' => 'Demo Workspace'],
                ['organization_id' => $organization->id, 'name' => 'Demo Workspace']
            );

            \App\Models\User::firstOrCreate(
                ['email' => 'demo@local.test'],
                [
                    'name' => 'Demo User',
                    'password' => \Illuminate\Support\Facades\Hash::make('password'),
                    'organization_id' => $organization->id,
                    'role' => 'owner',
                    'approved_at' => now(),
                    'active' => true,
                ]
            );

            $this->call([
                \Database\Seeders\CompanyProfileAndBrandVoiceSeeder::class,
                \Database\Seeders\DemoClientBriefSeeder::class,
                \Database\Seeders\DemoDraftComparisonSeeder::class,
                \Database\Seeders\DevelopmentContentSeriesSeeder::class,
                \Database\Seeders\WriterProfileSeeder::class,
                \Database\Seeders\MarketingPageSeeder::class,
            ]);

            if ($workspace) {
                app(\App\Services\Integrations\LegacyCredentialImportService::class)
                    ->importWorkspace($workspace);
            }
        }
    }
}
