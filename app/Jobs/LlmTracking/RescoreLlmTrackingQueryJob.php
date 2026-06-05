<?php

namespace App\Jobs\LlmTracking;

use App\Jobs\Stats\UpdateContentAiVisibilityJob;
use App\Models\LlmTrackingQuery;
use App\Services\LlmTracking\LlmAuthorityCandidateService;
use App\Services\LlmTracking\LlmVisibilityTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RescoreLlmTrackingQueryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $queryId,
    ) {}

    public function handle(LlmVisibilityTrackingService $service, LlmAuthorityCandidateService $candidateService): void
    {
        $query = LlmTrackingQuery::query()
            ->with(['site.analyticsSite', 'runs' => fn ($builder) => $builder->where('status', 'succeeded')->latest('run_at')->limit(100)])
            ->find($this->queryId);

        if (! $query) {
            return;
        }

        foreach ($query->runs as $run) {
            if (trim((string) $run->answer_text) === '') {
                continue;
            }

            $run->update($service->analyzeStoredRun($run));
            $candidateService->recordRun($run->refresh());
        }

        BuildLlmTrackingAggregatesJob::dispatch()->onQueue($this->queue ?? 'default');

        $analyticsSiteId = (string) ($query->site?->analyticsSite?->id ?? '');
        if ($analyticsSiteId !== '') {
            UpdateContentAiVisibilityJob::dispatch($analyticsSiteId)->onQueue($this->queue ?? 'default');
        }
    }
}
