<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SubscriptionsAssign extends Command
{
    protected $signature = 'subscriptions:assign {client_site_id} {plan_key} {--status=active}';
    protected $description = 'Create or update a subscription for a client site.';

    public function handle(): int
    {
        $clientSiteId = (string) $this->argument('client_site_id');
        $planKey = (string) $this->argument('plan_key');
        $status = (string) $this->option('status');

        $plan = Plan::query()->where('key', $planKey)->first();
        if (! $plan) {
            $this->error('Plan not found: ' . $planKey);
            return self::FAILURE;
        }

        $sub = Subscription::query()->firstOrNew(['client_site_id' => $clientSiteId]);

        if (! $sub->exists) {
            $sub->id = (string) Str::uuid();
        }

        $sub->plan_id = $plan->id;
        $sub->status = $status;
        $sub->save();

        $sub->refresh();

        $this->info('Subscription assigned.');
        $this->table(
            ['subscription_id', 'client_site_id', 'plan_key', 'status', 'current_period_start', 'current_period_end'],
            [[
                (string) $sub->id,
                (string) $sub->client_site_id,
                (string) $plan->key,
                (string) $sub->status,
                (string) ($sub->current_period_start ?? ''),
                (string) ($sub->current_period_end ?? ''),
            ]]
        );

        return self::SUCCESS;
    }
}
