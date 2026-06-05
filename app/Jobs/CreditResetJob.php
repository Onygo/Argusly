<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Services\SubscriptionLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreditResetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(private readonly int $limit = 200)
    {
        $this->onQueue('billing');
    }

    public function handle(SubscriptionLifecycleService $lifecycle): void
    {
        $subscriptions = Subscription::query()
            ->whereIn('status', ['active', 'trialing'])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->orderBy('current_period_end')
            ->limit($this->limit)
            ->get();

        foreach ($subscriptions as $subscription) {
            // Renewal credits are granted only by paid provider webhooks.
            $lifecycle->markPastDue($subscription, 'renewal_payment_overdue');
            $lifecycle->refreshProviderState($subscription);
        }
    }
}
