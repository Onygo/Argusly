<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Services\SubscriptionLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MandateActivationRetryJob implements ShouldQueue
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
        $subs = Subscription::query()
            ->where('status', 'pending_mandate')
            ->orderBy('updated_at')
            ->limit($this->limit)
            ->get();

        foreach ($subs as $subscription) {
            $lifecycle->activateRecurringIfMandateReady($subscription);
        }
    }
}
