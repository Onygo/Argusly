<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class CreditPacksSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(MarketingPricingPageSeeder::class);
    }
}
