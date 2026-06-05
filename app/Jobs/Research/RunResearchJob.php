<?php

namespace App\Jobs\Research;

use App\Enums\ResearchProjectStatus;
use App\Enums\ResearchSourceFetchStatus;
use App\Models\ResearchProject;
use App\Models\ResearchSource;
use App\Services\Research\ResearchSummaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class RunResearchJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 120;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(
        public readonly string $projectId,
        public readonly bool $force = false,
    ) {
        $this->onQueue((string) config('research.queue', 'research'));
    }

    public function uniqueId(): string
    {
        return 'research:run:' . $this->projectId;
    }

    public function handle(ResearchSummaryService $summaryService): void
    {
        $project = ResearchProject::query()
            ->with(['sources', 'findings'])
            ->find($this->projectId);

        if (! $project) {
            return;
        }

        if (! $this->force && (string) ($project->status?->value ?? $project->status) === ResearchProjectStatus::COMPLETED->value) {
            return;
        }

        try {
            $project = $this->beginOrResumeProject($project);
            if (! $project) {
                return;
            }

            if ($project->sources->isEmpty()) {
                $this->markProjectFailed($project, 'No sources are configured for this research project.');

                return;
            }

            $pendingFetchSources = $project->sources->filter(function (ResearchSource $source): bool {
                $status = (string) ($source->fetch_status?->value ?? $source->fetch_status);

                return in_array($status, [
                    ResearchSourceFetchStatus::PENDING->value,
                    ResearchSourceFetchStatus::FETCHING->value,
                ], true);
            })->values();

            if ($pendingFetchSources->isNotEmpty()) {
                $this->updateProjectStatus($project, ResearchProjectStatus::FETCHING);

                foreach ($pendingFetchSources as $source) {
                    FetchResearchSourceJob::dispatch((string) $source->id)
                        ->onQueue((string) config('research.queue', 'research'))
                        ->afterCommit();
                }

                return;
            }

            $fetchedSources = $project->sources->filter(function (ResearchSource $source): bool {
                return (string) ($source->fetch_status?->value ?? $source->fetch_status) === ResearchSourceFetchStatus::FETCHED->value
                    && trim((string) ($source->content_text ?? '')) !== '';
            })->values();

            if ($fetchedSources->isEmpty()) {
                $this->markProjectFailed($project, 'No source content could be fetched successfully.');

                return;
            }

            $pendingExtraction = $fetchedSources->filter(function (ResearchSource $source): bool {
                $extractionStatus = strtolower(trim((string) data_get($source->meta, 'extraction.status', 'pending')));

                return ! in_array($extractionStatus, ['succeeded', 'failed'], true);
            })->values();

            if ($pendingExtraction->isNotEmpty()) {
                $this->updateProjectStatus($project, ResearchProjectStatus::EXTRACTING);

                foreach ($pendingExtraction as $source) {
                    ExtractResearchFindingsJob::dispatch((string) $source->id)
                        ->onQueue((string) config('research.queue', 'research'))
                        ->afterCommit();
                }

                return;
            }

            $this->updateProjectStatus($project, ResearchProjectStatus::SUMMARIZING);
            $project = $summaryService->persistSummary($project);

            $project->update([
                'status' => ResearchProjectStatus::COMPLETED,
                'completed_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
            ]);
        } catch (Throwable $exception) {
            $project->refresh();
            $this->markProjectFailed($project, $exception->getMessage());

            throw $exception;
        }
    }

    private function beginOrResumeProject(ResearchProject $project): ?ResearchProject
    {
        return DB::transaction(function () use ($project): ?ResearchProject {
            $locked = ResearchProject::query()
                ->with(['sources', 'findings'])
                ->whereKey($project->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return null;
            }

            $status = (string) ($locked->status?->value ?? $locked->status);

            if (! $this->force && $status === ResearchProjectStatus::COMPLETED->value) {
                return null;
            }

            $updates = [
                'started_at' => $locked->started_at ?: now(),
            ];

            if ($this->force) {
                $updates['completed_at'] = null;
                $updates['failed_at'] = null;
                $updates['failure_reason'] = null;
            }

            if (in_array($status, [ResearchProjectStatus::DRAFT->value, ResearchProjectStatus::QUEUED->value], true) || $this->force) {
                $updates['status'] = ResearchProjectStatus::FETCHING;
            }

            $locked->update($updates);

            return $locked->fresh(['sources', 'findings']);
        });
    }

    private function updateProjectStatus(ResearchProject $project, ResearchProjectStatus $status): void
    {
        $project->update([
            'status' => $status,
            'started_at' => $project->started_at ?: now(),
            'failed_at' => null,
            'failure_reason' => null,
        ]);
    }

    private function markProjectFailed(ResearchProject $project, string $reason): void
    {
        $project->update([
            'status' => ResearchProjectStatus::FAILED,
            'failed_at' => now(),
            'failure_reason' => mb_substr($reason, 0, 5000),
        ]);
    }
}
