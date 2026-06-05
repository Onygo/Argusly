<?php

namespace App\Actions\Research;

use App\Enums\ResearchProjectStatus;
use App\Enums\ResearchSourceFetchStatus;
use App\Jobs\Research\RunResearchJob;
use App\Models\ResearchProject;
use App\Models\ResearchSource;
use App\Models\User;
use App\Services\Entitlements\FeatureGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StartResearchProjectAction
{
    public function __construct(
        private readonly FeatureGate $featureGate,
    ) {
    }

    public function execute(ResearchProject $project, User $user, bool $force = false): ResearchProject
    {
        $project->loadMissing(['workspace', 'sources']);

        if (! $project->workspace) {
            throw new RuntimeException('Workspace context is missing for this project.');
        }

        $this->assertResearchEnabled($project);

        $queued = DB::transaction(function () use ($project, $user, $force): ResearchProject {
            $locked = ResearchProject::query()
                ->with('sources')
                ->whereKey($project->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->sources->isEmpty()) {
                throw new RuntimeException('Add at least one source URL before starting research.');
            }

            if ($force) {
                $locked->findings()->delete();

                foreach ($locked->sources as $source) {
                    $meta = is_array($source->meta) ? $source->meta : [];
                    $source->update([
                        'title' => null,
                        'content_text' => null,
                        'fetch_status' => ResearchSourceFetchStatus::PENDING,
                        'fetched_at' => null,
                        'meta' => array_replace_recursive($meta, [
                            'fetch' => [
                                'status' => ResearchSourceFetchStatus::PENDING->value,
                                'started_at' => null,
                                'fetched_at' => null,
                                'failed_at' => null,
                                'error' => null,
                            ],
                            'extraction' => [
                                'status' => 'pending',
                                'started_at' => null,
                                'completed_at' => null,
                                'failed_at' => null,
                                'error' => null,
                            ],
                        ]),
                    ]);
                }

                $locked->summary = null;
                $locked->human_summary = null;
                $locked->completed_at = null;
                $locked->failed_at = null;
                $locked->failure_reason = null;
                $locked->started_at = null;
            }

            $locked->status = ResearchProjectStatus::QUEUED;
            $locked->failure_reason = null;
            $locked->failed_at = null;

            $config = is_array($locked->config) ? $locked->config : [];
            $locked->config = array_replace_recursive($config, [
                'runtime' => [
                    'last_started_by' => $user->id,
                    'last_started_at' => now()->toIso8601String(),
                    'forced_rerun' => $force,
                ],
            ]);

            $locked->save();

            return $locked->fresh();
        });

        RunResearchJob::dispatch((string) $queued->id, $force)
            ->onQueue((string) config('research.queue', 'research'))
            ->afterCommit();

        return $queued;
    }

    private function assertResearchEnabled(ResearchProject $project): void
    {
        if (! $this->toBool($this->featureGate->value($project->workspace, 'research_enabled', false), false)) {
            throw new AuthorizationException('Research is not enabled for this workspace.');
        }
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        $normalized = strtolower(trim((string) $value));

        return ! in_array($normalized, ['', '0', 'false', 'off', 'no'], true);
    }
}
