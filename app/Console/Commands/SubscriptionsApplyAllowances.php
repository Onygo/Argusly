<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\CreditPolicyService;
use App\Services\CreditWalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionsApplyAllowances extends Command
{
    protected $signature = 'subscriptions:apply-allowances {--limit=200} {--dry=0} {--force=0}';
    protected $description = 'Apply plan credit allowances for active subscriptions per billing interval.';

    public function handle(CreditWalletService $credits, CreditPolicyService $creditPolicy): int
    {
        $limit = (int) $this->option('limit');
        $dry = (string) $this->option('dry') === '1';
        $force = (string) $this->option('force') === '1';

        $subs = Subscription::query()
            ->whereIn('status', ['active', 'trialing'])
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $applied = 0;
        $skipped = 0;

        foreach ($subs as $sub) {
            $plan = Plan::query()->find($sub->plan_id);
            if (! $plan || ! $plan->is_active) {
                $skipped++;
                continue;
            }

            $now = Carbon::now();

            $periodStart = $sub->current_period_start ? Carbon::parse($sub->current_period_start) : null;
            $periodEnd = $sub->current_period_end ? Carbon::parse($sub->current_period_end) : null;

            $needsNewPeriod = false;

            if ($force) {
                $needsNewPeriod = true;
            } elseif (! $periodStart || ! $periodEnd) {
                $needsNewPeriod = true;
            } elseif ($now->greaterThanOrEqualTo($periodEnd)) {
                $needsNewPeriod = true;
            }

            if (! $needsNewPeriod) {
                $skipped++;
                continue;
            }

            $newStart = $now->copy()->startOfDay();
            $interval = (string) ($sub->interval ?: $plan->interval ?: 'month');
            $newEnd = $interval === 'year'
                ? $newStart->copy()->addYear()->startOfDay()
                : $newStart->copy()->addMonth()->startOfDay();

            $creditsIncluded = (int) ($sub->included_credits_per_interval ?: $plan->included_credits_per_interval ?: $plan->included_credits);

            $idempotencyKey = 'allowance:sub:' . $sub->id . ':start:' . $newStart->format('Ymd');

            if ($dry) {
                $this->line(
                    'DRY apply allowance for subscription ' . $sub->id .
                    ' client_site ' . $sub->client_site_id .
                    ' credits ' . $creditsIncluded .
                    ' key ' . $idempotencyKey
                );
                $applied++;
                continue;
            }

            DB::transaction(function () use ($sub, $plan, $credits, $creditPolicy, $newStart, $newEnd, $idempotencyKey, $creditsIncluded, $interval) {
                $sub->current_period_start = $newStart;
                $sub->current_period_end = $newEnd;
                $sub->save();

                $credits->addWorkspaceCredits(
                    workspaceId: (string) $sub->workspace_id,
                    amount: $creditsIncluded,
                    type: CreditWalletService::TYPE_ALLOWANCE,
                    meta: [
                        'plan_key' => (string) $plan->key,
                        'plan_id' => (string) $plan->id,
                        'interval' => $interval,
                        'period_start' => $newStart->toIso8601String(),
                        'period_end' => $newEnd->toIso8601String(),
                    ],
                    sourceType: Subscription::class,
                    sourceId: (string) $sub->id,
                    expiresAt: $creditPolicy->resolveSubscriptionGrantExpiryAt($sub->fresh(['plan']) ?? $sub, $newStart, $newEnd),
                    idempotencyKey: $idempotencyKey,
                    preferredClientSiteId: (string) $sub->client_site_id
                );
            });

            $applied++;
        }

        $this->info('Applied: ' . $applied . ', skipped: ' . $skipped . ', scanned: ' . $subs->count());

        return self::SUCCESS;
    }
}
