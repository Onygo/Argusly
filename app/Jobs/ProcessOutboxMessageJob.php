<?php

namespace App\Jobs;

use App\Models\OutboxMessage;
use App\Services\OutboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessOutboxMessageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $outboxMessageId) {}

    public function handle(OutboxService $outbox): void
    {
        $message = OutboxMessage::query()->findOrFail($this->outboxMessageId);

        $outbox->process($message);
    }
}
