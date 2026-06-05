<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\BillingSetting;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BillingBackfillCommand extends Command
{
    protected $signature = 'billing:backfill {--dry-run}';

    protected $description = 'Backfill organization billing links, plan fields, and active subscriptions safely.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? 'Running billing backfill in dry-run mode' : 'Running billing backfill');

        $planUpdates = 0;
        foreach (Plan::query()->get() as $plan) {
            $updates = [
                'price_cents' => $plan->price_cents ?: $plan->monthly_price_cents,
                'included_credits_per_interval' => $plan->included_credits_per_interval ?: $plan->included_credits,
                'seat_limit' => $plan->seat_limit ?: max(1, (int) data_get($plan->limits, 'users', 1)),
                'interval' => $plan->interval ?: 'month',
            ];

            if (! $dryRun) {
                $plan->fill($updates)->save();
            }

            $planUpdates++;
        }

        if (! $dryRun) {
            Subscription::query()->orderBy('id')->chunkById(200, function ($subs): void {
                foreach ($subs as $sub) {
                    if (! $sub->organization_id) {
                        $orgId = DB::table('client_sites as cs')
                            ->join('workspaces as w', 'w.id', '=', 'cs.workspace_id')
                            ->where('cs.id', $sub->client_site_id)
                            ->value('w.organization_id');

                        if ($orgId) {
                            $sub->organization_id = $orgId;
                            $sub->save();
                        }
                    }
                }
            }, 'id');
        }

        $orgUpdates = 0;
        foreach (Organization::query()->get() as $organization) {
            $primaryUserId = DB::table('users')
                ->where('organization_id', $organization->id)
                ->orderByRaw("CASE WHEN role = 'owner' THEN 0 WHEN role = 'admin' THEN 1 ELSE 2 END")
                ->orderBy('created_at')
                ->value('id');

            $activeSubId = Subscription::query()
                ->where('organization_id', $organization->id)
                ->whereIn('status', ['active', 'trialing'])
                ->orderByDesc('updated_at')
                ->value('id');

            if (! $dryRun) {
                $organization->primary_user_id = $organization->primary_user_id ?: $primaryUserId;
                $organization->active_subscription_id = $activeSubId;
                $organization->billing_company_name = $organization->billing_company_name ?: $organization->name;
                $organization->save();
            }

            $orgUpdates++;
        }

        $subUpdates = 0;
        foreach (Subscription::query()->with('plan')->get() as $subscription) {
            $plan = $subscription->plan;
            if (! $plan) {
                continue;
            }

            if (! $dryRun) {
                $subscription->interval = $subscription->interval ?: $plan->interval;
                $subscription->price_cents = $subscription->price_cents ?: $plan->price_cents ?: $plan->monthly_price_cents;
                $subscription->currency = $subscription->currency ?: $plan->currency;
                $subscription->included_credits_per_interval = $subscription->included_credits_per_interval ?: $plan->included_credits_per_interval ?: $plan->included_credits;
                $subscription->seat_limit = $subscription->seat_limit ?: $plan->seat_limit;
                $subscription->next_payment_at = $subscription->next_payment_at ?: $subscription->current_period_end;
                if (! in_array((string) $subscription->status, ['active', 'trialing', 'past_due', 'canceled', 'pending_mandate', 'suspended'], true)) {
                    $subscription->status = 'active';
                }
                $subscription->save();
            }

            $subUpdates++;
        }

        $this->table(['entity', 'updated'], [
            ['plans', $planUpdates],
            ['organizations', $orgUpdates],
            ['subscriptions', $subUpdates],
        ]);

        if (! $dryRun) {
            $defaults = [
                'plan_change.defaults' => [
                    'upgrade_strategy' => 'next_period',
                    'downgrade_strategy' => 'next_period',
                    'allow_immediate_downgrade' => false,
                ],
                'dunning.defaults' => [
                    'grace_days' => 7,
                    'suspend_after_grace' => true,
                ],
                'credits.defaults' => [
                    'included_rollover_enabled' => false,
                    'consumption_order' => 'included_first_then_addon',
                ],
            ];

            foreach ($defaults as $key => $value) {
                BillingSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        }

        return self::SUCCESS;
    }
}
