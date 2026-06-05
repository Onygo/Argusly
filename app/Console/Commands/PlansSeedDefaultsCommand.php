<?php

namespace App\Console\Commands;

use App\Services\MarketingPricingService;
use Database\Seeders\MarketingPricingPageSeeder;
use Illuminate\Console\Command;

class PlansSeedDefaultsCommand extends Command
{
    protected $signature = 'plans:seed-defaults';

    protected $description = 'Seed default billing plans, plan features, and credit packs (idempotent).';

    public function handle(MarketingPricingService $pricing): int
    {
        $this->callSilent('db:seed', ['--class' => MarketingPricingPageSeeder::class, '--force' => true]);
        $pricing->clearCaches();

        $this->info('Default plans, credit packs, and pricing content seeded.');

        return self::SUCCESS;
    }
}
