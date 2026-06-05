<?php

namespace App\Jobs;

use App\Models\Ga4Property;
use App\Services\Integrations\Google\GA4DataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncGA4PropertyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $ga4PropertyId,
        public readonly int $days = 30,
    ) {
        $this->onQueue(config('queue.names.integrations', 'integrations'));
    }

    public function handle(GA4DataService $ga4): void
    {
        $property = Ga4Property::query()->findOrFail($this->ga4PropertyId);

        $ga4->sync($property, $this->days);
    }
}
