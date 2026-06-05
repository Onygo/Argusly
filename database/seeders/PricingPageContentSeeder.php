<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class PricingPageContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(MarketingPricingPageSeeder::class);
    }
}
