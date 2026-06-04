<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(LanguageSeeder::class);
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(SubscriptionCatalogSeeder::class);
        $this->call(CreditCostCatalogSeeder::class);
        $this->call(LlmProviderSeeder::class);
        $this->call(IntegrationCatalogSeeder::class);
        $this->call(ConnectorCatalogSeeder::class);
        $this->call(TenantIsolationSeeder::class);
        $this->call(RoleDemoUsersSeeder::class);
        $this->call(PublishingFoundationSeeder::class);
        $this->call(IntelligenceSignalSeeder::class);
        $this->call(ContentAssetSeeder::class);
    }
}
