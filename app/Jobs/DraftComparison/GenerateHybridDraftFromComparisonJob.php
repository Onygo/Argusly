<?php

namespace App\Jobs\DraftComparison;

use App\Services\DraftComparison\DraftComparisonService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateHybridDraftFromComparisonJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 300;

    public int $uniqueFor = 1800;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function __construct(
        public readonly string $comparisonId,
    ) {}

    public function uniqueId(): string
    {
        return 'draft_compare:hybrid:' . $this->comparisonId;
    }

    public function handle(DraftComparisonService $draftComparisonService): void
    {
        $draftComparisonService->generateHybridDraftForComparison($this->comparisonId);
    }
}

