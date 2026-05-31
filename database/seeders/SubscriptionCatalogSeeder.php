<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class SubscriptionCatalogSeeder extends Seeder
{
    /**
     * Seed modules and plans.
     */
    public function run(): void
    {
        $modules = collect(config('subscriptions.modules', []))
            ->mapWithKeys(fn (string $name, string $key) => [$key => Module::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name' => $name,
                    'is_active' => true,
                    'is_system' => true,
                ],
            )]);

        foreach (config('subscriptions.plans', []) as $key => $definition) {
            $plan = Plan::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name' => $definition['name'],
                    'billing_interval' => $definition['interval'],
                    'currency' => $definition['currency'],
                    'amount' => $definition['amount'],
                    'is_active' => true,
                    'is_system' => true,
                ],
            );

            $plan->modules()->sync(
                collect($definition['modules'])
                    ->map(fn (string $moduleKey) => $modules[$moduleKey]?->id)
                    ->filter()
                    ->values()
                    ->all(),
            );
        }
    }
}
