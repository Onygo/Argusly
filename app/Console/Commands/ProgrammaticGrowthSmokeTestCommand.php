<?php

namespace App\Console\Commands;

use App\Models\ContentPublication;
use App\Models\ProgrammaticBriefBlueprint;
use App\Models\ProgrammaticCluster;
use App\Models\ProgrammaticDraftRequest;
use App\Models\ProgrammaticDraftReview;
use App\Models\ProgrammaticOpportunity;
use App\Models\ProgrammaticPublicationPlan;
use App\Models\ProgrammaticPublicationReadiness;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Growth\ProgrammaticGrowthDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

class ProgrammaticGrowthSmokeTestCommand extends Command
{
    protected $signature = 'argusly:programmatic-growth-smoke-test {--create-demo : Create a safe internal demo flow} {--workspace-id= : Workspace id for demo creation}';

    protected $description = 'Check Programmatic Growth beta routes, models, policies, and config without mutating data by default';

    public function handle(ProgrammaticGrowthDemoSeeder $demoSeeder): int
    {
        $failures = [];

        foreach ($this->requiredRoutes() as $route) {
            if (! Route::has($route)) {
                $failures[] = "Missing route: {$route}";
            }
        }

        foreach ($this->requiredModels() as $model) {
            if (! class_exists($model)) {
                $failures[] = "Missing model: {$model}";
            }
        }

        foreach ($this->requiredPolicies() as $model) {
            if (Gate::getPolicyFor($model) === null) {
                $failures[] = "Missing policy registration for: {$model}";
            }
        }

        if (! is_array(config('argusly_programmatic', []))) {
            $failures[] = 'Missing argusly_programmatic config.';
        }

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error($failure);
            }

            return self::FAILURE;
        }

        $this->info('Programmatic Growth smoke checks passed.');

        if (! $this->option('create-demo')) {
            $this->line('No demo data created. Pass --create-demo to seed the safe internal flow.');

            return self::SUCCESS;
        }

        $workspace = $this->resolveWorkspace();
        if (! $workspace) {
            $this->error('No workspace found. Provide --workspace-id or seed/create a workspace first.');

            return self::FAILURE;
        }

        $owner = User::query()
            ->where('organization_id', $workspace->organization_id)
            ->whereIn('role', ['owner', 'admin'])
            ->orderBy('created_at')
            ->first();

        $program = $demoSeeder->seed($workspace, $owner);

        $livePublications = ContentPublication::query()
            ->whereHas('content', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->where('meta->demo', true)
            ->where(function ($query): void {
                $query->where('delivery_status', ContentPublication::STATUS_DELIVERED)
                    ->orWhere('remote_status', ContentPublication::REMOTE_PUBLISHED);
            })
            ->count();

        if ($livePublications > 0) {
            $this->error('Demo flow created a live publication state, which is not allowed.');

            return self::FAILURE;
        }

        $this->info('Safe demo flow is ready: '.$program->name);
        $this->line('Growth program id: '.$program->id);

        return self::SUCCESS;
    }

    /**
     * @return array<int,string>
     */
    private function requiredRoutes(): array
    {
        return [
            'app.growth-programs.index',
            'app.programmatic-opportunities.index',
            'app.programmatic-clusters.index',
            'app.programmatic-brief-blueprints.index',
            'app.programmatic-draft-requests.index',
            'app.programmatic-draft-reviews.index',
            'app.programmatic-publication-readiness.index',
            'app.programmatic-publication-plans.index',
        ];
    }

    /**
     * @return array<int,class-string>
     */
    private function requiredModels(): array
    {
        return [
            ProgrammaticOpportunity::class,
            ProgrammaticCluster::class,
            ProgrammaticBriefBlueprint::class,
            ProgrammaticDraftRequest::class,
            ProgrammaticDraftReview::class,
            ProgrammaticPublicationReadiness::class,
            ProgrammaticPublicationPlan::class,
        ];
    }

    /**
     * @return array<int,class-string>
     */
    private function requiredPolicies(): array
    {
        return $this->requiredModels();
    }

    private function resolveWorkspace(): ?Workspace
    {
        $workspaceId = $this->option('workspace-id');

        return Workspace::query()
            ->when($workspaceId, fn ($query) => $query->whereKey($workspaceId))
            ->orderBy('created_at')
            ->first();
    }
}
