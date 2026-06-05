<?php

namespace App\Jobs\Research;

use App\Enums\ResearchSourceFetchStatus;
use App\Models\ResearchSource;
use App\Services\Research\ResearchExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExtractResearchFindingsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 600;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 180, 600];
    }

    public function __construct(
        public readonly string $sourceId,
    ) {
        $this->onQueue((string) config('research.queue', 'research'));
    }

    public function uniqueId(): string
    {
        return 'research:extract:' . $this->sourceId;
    }

    public function handle(ResearchExtractionService $extraction): void
    {
        $source = ResearchSource::query()
            ->with('project')
            ->find($this->sourceId);

        if (! $source || ! $source->project) {
            return;
        }

        $sourceStatus = (string) ($source->fetch_status?->value ?? $source->fetch_status);
        if ($sourceStatus !== ResearchSourceFetchStatus::FETCHED->value) {
            RunResearchJob::dispatch((string) $source->project->id)
                ->onQueue((string) config('research.queue', 'research'))
                ->afterCommit();

            return;
        }

        $extraction->extractFromSource($source, false);

        RunResearchJob::dispatch((string) $source->project->id)
            ->onQueue((string) config('research.queue', 'research'))
            ->afterCommit();
    }

    public function failed(Throwable $exception): void
    {
        $source = ResearchSource::query()
            ->with('project')
            ->find($this->sourceId);

        if (! $source || ! $source->project) {
            return;
        }

        $source->update([
            'meta' => array_replace_recursive(is_array($source->meta) ? $source->meta : [], [
                'extraction' => [
                    'status' => 'failed',
                    'failed_at' => now()->toIso8601String(),
                    'error' => mb_substr($exception->getMessage(), 0, 1000),
                ],
            ]),
        ]);

        RunResearchJob::dispatch((string) $source->project->id)
            ->onQueue((string) config('research.queue', 'research'));
    }
}
