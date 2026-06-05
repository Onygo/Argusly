<?php

namespace App\Console\Commands;

use App\Jobs\AgenticMarketing\ExecuteAgenticMarketingActionJob;
use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingExecutionSetting;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Services\AgenticMarketing\AgenticActionRunLogger;
use App\Services\AgenticMarketing\AgenticApprovalGate;
use App\Services\AgenticMarketing\AgenticMarketingActionPlanner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RunAutonomousAgenticMarketingCommand extends Command
{
    protected $signature = 'agentic:run-autonomous
        {--dry-run : Evaluate eligible actions without creating, claiming, or dispatching work}
        {--workspace= : Limit execution to one workspace UUID}
        {--brand= : Limit execution to one brand voice UUID}
        {--limit=25 : Maximum actions to dispatch in this run}
        {--action-type= : Limit to an Agentic Marketing action type}';

    protected $description = 'Safely evaluate and dispatch autonomous Agentic Marketing actions within workspace policy limits.';

    public function handle(
        AgenticMarketingActionPlanner $planner,
        AgenticApprovalGate $gate,
        AgenticActionRunLogger $runs,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, min(100, (int) $this->option('limit')));
        $actionTypeFilter = trim((string) $this->option('action-type')) ?: null;
        $actionTypeValues = $this->actionTypeValues($actionTypeFilter);

        $summary = [
            'evaluated' => 0,
            'planned' => 0,
            'dispatched' => 0,
            'blocked' => 0,
            'approval_required' => 0,
            'duplicates' => 0,
            'dry_run_allowed' => 0,
        ];

        $settings = $this->autonomousSettings();
        if ($settings->isEmpty()) {
            $this->info('No autonomous Agentic Marketing workspaces matched the filters.');

            return self::SUCCESS;
        }

        foreach ($settings as $setting) {
            $remainingForWorkspace = $this->remainingDailyActions($setting);
            if ($remainingForWorkspace <= 0) {
                $this->line(sprintf('Workspace %s skipped: daily autonomous action limit reached.', $setting->workspace_id));
                continue;
            }

            $workspaceDispatchLimit = min($remainingForWorkspace, $limit - $summary['dispatched']);
            if ($workspaceDispatchLimit <= 0) {
                break;
            }

            $opportunities = $this->candidateOpportunities($setting);
            foreach ($opportunities as $opportunity) {
                if ($summary['dispatched'] >= $limit || $workspaceDispatchLimit <= 0) {
                    break 2;
                }

                $beforeIds = AgenticMarketingAction::query()
                    ->where('opportunity_id', $opportunity->id)
                    ->pluck('id')
                    ->map(fn (mixed $id): string => (string) $id)
                    ->all();

                if (! $dryRun) {
                    $result = $planner->planForOpportunity($opportunity);
                    $summary['planned'] += (int) ($result['created'] ?? 0);
                    $summary['duplicates'] += (int) ($result['reused'] ?? 0);
                }

                $actions = AgenticMarketingAction::query()
                    ->with(['objective.workspace', 'opportunity', 'content'])
                    ->where('opportunity_id', $opportunity->id)
                    ->open()
                    ->when($dryRun && $beforeIds !== [], fn (Builder $query) => $query->whereIn('id', $beforeIds))
                    ->when($actionTypeValues !== [], fn (Builder $query) => $query->whereIn('action_type', $actionTypeValues))
                    ->orderBy('created_at')
                    ->get();

                foreach ($actions as $action) {
                    if ($summary['dispatched'] >= $limit || $workspaceDispatchLimit <= 0) {
                        break 2;
                    }

                    $summary['evaluated']++;
                    $decision = $gate->forMarketingAction($action, [
                        'has_customer_approval' => false,
                        'brand_voice_id' => $setting->brand_voice_id,
                    ]);

                    if (! (bool) $decision['allowed']) {
                        if ((bool) $decision['blocked']) {
                            $summary['blocked']++;
                        } else {
                            $summary['approval_required']++;
                        }

                        if (! $dryRun) {
                            $runs->recordGateDecision($action, $decision, null, [
                                'source' => 'agentic:run-autonomous',
                            ]);
                        }

                        continue;
                    }

                    if ($dryRun) {
                        $summary['dry_run_allowed']++;
                        $this->line(sprintf('[dry-run] Would dispatch %s for opportunity %s.', $action->action_type, $opportunity->id));

                        continue;
                    }

                    $claimId = $this->claimForAutonomousExecution($action);
                    if (! $claimId) {
                        $summary['duplicates']++;
                        continue;
                    }

                    $runs->markQueued($action->fresh(['objective', 'opportunity']), $decision, null, $claimId);
                    ExecuteAgenticMarketingActionJob::dispatch((string) $action->id, null, $claimId)
                        ->onQueue('agentic-marketing')
                        ->afterCommit();

                    $setting->forceFill(['last_autonomous_action_at' => now()])->save();

                    $summary['dispatched']++;
                    $workspaceDispatchLimit--;
                }
            }
        }

        $this->info(sprintf(
            'Autonomous Agentic Marketing evaluated %d action(s), dispatched %d, dry-run allowed %d, blocked %d, approval required %d, duplicates/reused %d.',
            $summary['evaluated'],
            $summary['dispatched'],
            $summary['dry_run_allowed'],
            $summary['blocked'],
            $summary['approval_required'],
            $summary['duplicates'],
        ));

        return self::SUCCESS;
    }

    private function autonomousSettings(): Collection
    {
        return AgenticMarketingExecutionSetting::query()
            ->with('workspace')
            ->where('agentic_execution_mode', AgenticMarketingExecutionSetting::MODE_AUTONOMOUS)
            ->when($this->option('workspace'), fn (Builder $query, string $workspace): Builder => $query->where('workspace_id', $workspace))
            ->when($this->option('brand'), fn (Builder $query, string $brand): Builder => $query->where('brand_voice_id', $brand))
            ->where(function (Builder $query): void {
                $query
                    ->where('autonomous_publication_enabled', true)
                    ->orWhere('autonomous_refresh_enabled', true)
                    ->orWhere('autonomous_internal_linking_enabled', true)
                    ->orWhere('autonomous_brief_generation_enabled', true)
                    ->orWhere('autonomous_chained_plans_enabled', true);
            })
            ->orderBy('updated_at')
            ->get()
            ->filter(fn (AgenticMarketingExecutionSetting $setting): bool => (bool) $setting->workspace);
    }

    /**
     * @return array<int,string>
     */
    private function actionTypeValues(?string $actionType): array
    {
        return match ($actionType) {
            null => [],
            AgenticApprovalGate::ACTION_REFRESH_EXISTING_CONTENT => ['refresh_article', 'update_meta', 'add_schema'],
            AgenticApprovalGate::ACTION_CREATE_NEW_CONTENT => ['create_article', 'create_locale_variant'],
            AgenticApprovalGate::ACTION_ADD_INTERNAL_LINKS => ['improve_internal_links'],
            AgenticApprovalGate::ACTION_UPDATE_ANSWER_BLOCKS,
            AgenticApprovalGate::ACTION_RUN_AI_VISIBILITY_REFRESH => ['add_answer_block'],
            default => [$actionType],
        };
    }

    private function candidateOpportunities(AgenticMarketingExecutionSetting $setting): Collection
    {
        $allowedSiteIds = array_values((array) ($setting->allowed_site_ids ?? []));

        return AgenticMarketingOpportunity::query()
            ->with(['objective.workspace', 'content'])
            ->whereHas('objective', function (Builder $query) use ($setting): void {
                $query
                    ->where('workspace_id', $setting->workspace_id)
                    ->where('status', 'active');
            })
            ->where('status', 'open')
            ->when($allowedSiteIds !== [], function (Builder $query) use ($allowedSiteIds): void {
                $query->whereHas('objective', fn (Builder $objective): Builder => $objective->whereIn('client_site_id', $allowedSiteIds));
            })
            ->orderByDesc('priority_score')
            ->limit(100)
            ->get();
    }

    private function remainingDailyActions(AgenticMarketingExecutionSetting $setting): int
    {
        $limit = max(0, (int) $setting->max_autonomous_actions_per_day);
        if ($limit <= 0) {
            return 0;
        }

        $used = AgenticActionRun::query()
            ->where('workspace_id', $setting->workspace_id)
            ->where('executed_by_agent', true)
            ->whereIn('status', [
                AgenticActionRun::STATUS_QUEUED,
                AgenticActionRun::STATUS_RUNNING,
                AgenticActionRun::STATUS_COMPLETED,
            ])
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        return max(0, $limit - $used);
    }

    private function claimForAutonomousExecution(AgenticMarketingAction $action): ?string
    {
        return DB::transaction(function () use ($action): ?string {
            $locked = AgenticMarketingAction::query()
                ->lockForUpdate()
                ->findOrFail($action->id);

            if (! in_array($locked->status, [AgenticMarketingAction::STATUS_PROPOSED, AgenticMarketingAction::STATUS_APPROVED], true)) {
                return null;
            }

            $claimId = (string) Str::uuid();
            $locked->forceFill([
                'status' => AgenticMarketingAction::STATUS_RUNNING,
                'execution_claim_id' => $claimId,
                'execution_claimed_at' => now(),
                'started_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'error_message' => null,
            ])->save();

            return $claimId;
        });
    }
}
