<?php

namespace App\Services\Subscriptions;

use App\Models\Account;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionModule;
use App\Services\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SubscriptionService
{
    /**
     * Start or replace an account subscription from a plan.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function activatePlan(Account $account, Plan|string $plan, array $metadata = []): Subscription
    {
        $plan = $plan instanceof Plan
            ? $plan
            : Plan::query()->where('key', $plan)->firstOrFail();

        if (! $plan->is_active) {
            throw new InvalidArgumentException("Plan [{$plan->key}] is not active.");
        }

        return DB::transaction(function () use ($account, $plan, $metadata): Subscription {
            $account->subscriptions()
                ->active()
                ->update([
                    'status' => 'canceled',
                    'canceled_at' => now(),
                ]);

            $subscription = $account->subscriptions()->create([
                'plan_id' => $plan->id,
                'status' => 'active',
                'billing_interval' => $plan->billing_interval,
                'currency' => $plan->currency,
                'amount' => $plan->amount,
                'metadata' => $metadata,
                'current_period_starts_at' => now(),
                'current_period_ends_at' => $this->periodEndFor($plan->billing_interval),
            ]);

            $this->syncModulesFromPlan($subscription, $plan);

            return $subscription->load(['plan', 'modules.module']);
        });
    }

    /**
     * Activate one module on an account's active subscription.
     *
     * @param  array<string, mixed>|null  $limits
     * @param  array<string, mixed>  $metadata
     */
    public function activateModule(Account $account, Module|string $module, ?array $limits = null, array $metadata = []): void
    {
        $subscription = $account->activeSubscription()->first();

        if (! $subscription) {
            throw new InvalidArgumentException('The account does not have an active subscription.');
        }

        $module = $module instanceof Module
            ? $module
            : Module::query()->where('key', $module)->firstOrFail();

        if (! $module->is_active) {
            throw new InvalidArgumentException("Module [{$module->key}] is not active.");
        }

        $subscriptionModule = $subscription->modules()->updateOrCreate(
            ['module_id' => $module->id],
            [
                'account_id' => $account->id,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => null,
                'limits' => $limits,
                'metadata' => $metadata,
            ],
        );

        $this->logModuleActivated($account, $module, $subscriptionModule);
    }

    public function cancel(Account $account): void
    {
        DB::transaction(function () use ($account): void {
            $account->subscriptions()
                ->active()
                ->update([
                    'status' => 'canceled',
                    'canceled_at' => now(),
                ]);

            $account->subscriptionModules()
                ->where('status', 'active')
                ->update([
                    'status' => 'canceled',
                    'ends_at' => now(),
                ]);
        });
    }

    private function syncModulesFromPlan(Subscription $subscription, Plan $plan): void
    {
        $plan->modules()
            ->where('is_active', true)
            ->get()
            ->each(function (Module $module) use ($subscription): void {
                $subscriptionModule = $subscription->modules()->updateOrCreate(
                    ['module_id' => $module->id],
                    [
                        'account_id' => $subscription->account_id,
                        'status' => 'active',
                        'starts_at' => now(),
                        'ends_at' => null,
                    ],
                );

                if ($account = $subscription->account()->first()) {
                    $this->logModuleActivated($account, $module, $subscriptionModule);
                }
            });
    }

    private function logModuleActivated(Account $account, Module $module, SubscriptionModule $subscriptionModule): void
    {
        app(ActivityLogger::class)->log(
            event: 'module.activated',
            description: "Module {$module->name} was activated.",
            account: $account,
            subject: $subscriptionModule,
            properties: [
                'module_id' => $module->id,
                'module_key' => $module->key,
            ],
        );
    }

    private function periodEndFor(string $interval): ?Carbon
    {
        return match ($interval) {
            'monthly' => now()->addMonth(),
            'yearly' => now()->addYear(),
            default => null,
        };
    }
}
