<?php

namespace App\Jobs;

use App\Services\Billing\CreditExpirationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreditExpiryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $limit = 200)
    {
    }

    public function handle(CreditExpirationService $expiration): void
    {
        $expiration->expireCredits($this->limit);
    }
}
