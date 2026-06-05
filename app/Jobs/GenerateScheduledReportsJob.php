<?php

namespace App\Jobs;

use App\Services\ExecutiveReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateScheduledReportsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $type = 'weekly',
        public readonly int $limit = 50,
    ) {
        $this->onQueue('maintenance');
    }

    public function handle(ExecutiveReportService $reports): void
    {
        $reports->generateScheduled($this->type, $this->limit);
    }
}
