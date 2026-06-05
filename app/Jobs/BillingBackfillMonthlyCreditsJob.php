<?php

namespace App\Jobs;

use App\Services\SubscriptionMonthlyCreditRecoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BillingBackfillMonthlyCreditsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(private readonly int $limit = 500)
    {
        $this->onQueue('billing');
    }

    public function handle(SubscriptionMonthlyCreditRecoveryService $recovery): void
    {
        $summary = $recovery->backfillMissingForActiveSubscriptions($this->limit);

        Log::info('billing.backfill_monthly_credits.completed', $summary);
    }
}
