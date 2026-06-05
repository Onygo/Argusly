<?php

namespace Database\Seeders;

use App\Support\EditorialTaxonomyService;
use Illuminate\Database\Seeder;

class TaxonomySetSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(EditorialTaxonomyService::class);

        // Ensure default taxonomy baseline and attach default sets for all tenants.
        $tenantIds = \App\Models\Organization::query()->pluck('id');
        if ($tenantIds->isEmpty()) {
            // Keep default set/items available even when no tenant exists yet.
            $service->ensureDefaults(0);
            return;
        }

        foreach ($tenantIds as $tenantId) {
            $service->ensureDefaults((int) $tenantId);
        }
    }
}

