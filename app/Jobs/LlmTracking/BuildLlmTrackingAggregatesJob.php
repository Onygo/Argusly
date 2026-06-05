<?php

namespace App\Jobs\LlmTracking;

use App\Services\LlmTracking\LlmTrackingAggregateBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BuildLlmTrackingAggregatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly ?string $fromDate = null,
    ) {}

    public function handle(LlmTrackingAggregateBuilder $builder): void
    {
        $builder->build(
            $this->fromDate ? CarbonImmutable::parse($this->fromDate) : null
        );
    }
}
