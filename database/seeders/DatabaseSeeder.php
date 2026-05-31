<?php

namespace Database\Seeders;

use App\Models\User;
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
        $this->call(IntegrationCatalogSeeder::class);
        $this->call(ConnectorCatalogSeeder::class);
        $this->call(TenantIsolationSeeder::class);
        $this->call(PublishingFoundationSeeder::class);
        $this->call(IntelligenceSignalSeeder::class);
        $this->call(ContentAssetSeeder::class);

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
