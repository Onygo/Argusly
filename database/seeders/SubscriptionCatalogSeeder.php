<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\PlanFeature;
use Illuminate\Database\Seeder;

class SubscriptionCatalogSeeder extends Seeder
{
    /**
     * Seed modules and plans.
     */
    public function run(): void
    {
        $this->call(CreditCostCatalogSeeder::class);

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
                    'limits' => $definition['limits'] ?? null,
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

            collect($definition['modules'])
                ->each(function (string $moduleKey) use ($modules, $plan): void {
                    $module = $modules[$moduleKey] ?? null;

                    if (! $module) {
                        return;
                    }

                    PlanFeature::query()->updateOrCreate(
                        ['plan_id' => $plan->id, 'feature' => $moduleKey],
                        [
                            'module_id' => $module->id,
                            'name' => $module->name,
                            'enabled' => true,
                            'settings' => ['source' => 'subscription_catalog'],
                        ],
                    );

                    PlanEntitlement::query()->updateOrCreate(
                        ['plan_id' => $plan->id, 'key' => "module:{$moduleKey}"],
                        [
                            'enabled' => true,
                            'value' => ['module' => $moduleKey],
                            'metadata' => ['source' => 'subscription_catalog'],
                        ],
                    );
                });

            foreach (($definition['limits'] ?? []) as $limitKey => $value) {
                $feature = config("subscriptions.limit_features.{$limitKey}", $limitKey);
                $unlimited = $value === null;

                $plan->featureLimits()->updateOrCreate(
                    ['feature' => $feature, 'limit_key' => $limitKey],
                    [
                        'name' => str($limitKey)->headline()->toString(),
                        'value' => $unlimited ? null : (int) $value,
                        'unlimited' => $unlimited,
                        'metadata' => ['source' => 'subscription_catalog'],
                    ],
                );

                PlanEntitlement::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'key' => "limit:{$limitKey}"],
                    [
                        'enabled' => true,
                        'value' => [
                            'feature' => $feature,
                            'limit_key' => $limitKey,
                            'value' => $unlimited ? null : (int) $value,
                            'unlimited' => $unlimited,
                        ],
                        'metadata' => ['source' => 'subscription_catalog'],
                    ],
                );
            }
        }
    }
}
