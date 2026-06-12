<?php

namespace App\Services\Onboarding;

use App\Models\Workspace;
use App\Services\Onboarding\Providers\AiVisibilityReadinessProvider;
use App\Services\Onboarding\Providers\ContentOperationsReadinessProvider;
use App\Services\Onboarding\Providers\ExecutionPlanningReadinessProvider;
use App\Services\Onboarding\Providers\OpportunityIntelligenceReadinessProvider;
use App\Services\Onboarding\Providers\SignalIntelligenceReadinessProvider;
use Illuminate\Support\Collection;

class WorkspaceReadinessService
{
    /**
     * @var array<int,ReadinessProvider>
     */
    private array $providers;

    public function __construct(
        SignalIntelligenceReadinessProvider $signalIntelligence,
        AiVisibilityReadinessProvider $aiVisibility,
        OpportunityIntelligenceReadinessProvider $opportunityIntelligence,
        ExecutionPlanningReadinessProvider $executionPlanning,
        ContentOperationsReadinessProvider $contentOperations,
    ) {
        $this->providers = [
            $signalIntelligence,
            $aiVisibility,
            $opportunityIntelligence,
            $executionPlanning,
            $contentOperations,
        ];
    }

    /**
     * @return array{workspace:Workspace,score:int,modules:Collection<int,ModuleReadinessResult>,quick_actions:Collection<int,ReadinessAction>}
     */
    public function getWorkspaceReadiness(Workspace $workspace): array
    {
        $modules = collect($this->providers)
            ->map(fn (ReadinessProvider $provider): ModuleReadinessResult => $provider->evaluate($workspace))
            ->values();

        return [
            'workspace' => $workspace,
            'score' => (int) round($modules->avg('progress') ?? 0),
            'modules' => $modules,
            'quick_actions' => $modules
                ->flatMap(fn (ModuleReadinessResult $result): array => $result->recommended_actions)
                ->unique(fn (ReadinessAction $action): string => $action->label.'|'.($action->route ?? ''))
                ->values(),
        ];
    }

    public function getModuleReadiness(Workspace $workspace, string $moduleKey): ?ModuleReadinessResult
    {
        return collect($this->providers)
            ->first(fn (ReadinessProvider $provider): bool => $provider->key() === $moduleKey)
            ?->evaluate($workspace);
    }

    public function getBlockingMessage(Workspace $workspace, string $moduleKey): ?string
    {
        return $this->getModuleReadiness($workspace, $moduleKey)?->blocking_message;
    }

    /**
     * @return array<string,mixed>
     */
    public function getEmptyState(Workspace $workspace, string $moduleKey): array
    {
        $result = $this->getModuleReadiness($workspace, $moduleKey);

        if (! $result) {
            return [];
        }

        return $this->emptyStateFromResult($result);
    }

    /**
     * @return array<string,mixed>
     */
    public function emptyStateFromResult(ModuleReadinessResult $result): array
    {
        return [
            'title' => match ($result->status) {
                'active' => $result->label.' is active',
                'ready' => $result->label.' is ready',
                default => $result->label.' needs setup',
            },
            'message' => $result->blocking_message ?: 'Complete the missing setup steps before this module can produce useful results.',
            'result' => $result,
            'missing_requirements' => $result->missing_requirements,
            'actions' => $result->recommended_actions,
        ];
    }
}
