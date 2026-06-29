<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticMarketingObjective;
use Throwable;

class AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook
{
    public const DIAGNOSTICS_KEY = 'mos.agentic_planner_default_selection_scoped_runtime_guard.planner_path.last_diagnostics';

    public function __construct(
        private readonly AgenticPlannerDefaultSelectionScopedRuntimeGuardService $guard,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function inspectObjective(AgenticMarketingObjective $objective): ?array
    {
        if (! (bool) config('mos.agentic_planner.default_selection.scoped_runtime_enabled', false)) {
            return null;
        }

        $requestedScope = [
            'workspace_id' => (string) $objective->workspace_id,
            'objective_ids' => [(string) $objective->id],
            'site_id' => $objective->client_site_id ? (string) $objective->client_site_id : null,
        ];

        try {
            $decision = $this->guard->decide([
                'workspace' => $requestedScope['workspace_id'],
                'objectives' => $requestedScope['objective_ids'],
                'site' => $requestedScope['site_id'],
                'limit' => 1,
            ]);

            $diagnostics = [
                'ok' => true,
                'guard_called' => true,
                'guard_allowed' => (bool) ($decision['allowed'] ?? false),
                'blocked_reasons' => array_values((array) ($decision['blocked_reasons'] ?? [])),
                'rollback_mode' => (string) ($decision['rollback_mode'] ?? AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE),
                'requested_scope' => $requestedScope,
                'runtime_activation_statement' => (string) ($decision['runtime_activation_statement'] ?? 'Diagnostic hook only. Default selection remains legacy.'),
                'selected_planner_remains' => 'legacy',
            ];
        } catch (Throwable $exception) {
            $diagnostics = [
                'ok' => false,
                'guard_called' => true,
                'guard_allowed' => false,
                'blocked_reasons' => ['scoped_runtime_guard_exception'],
                'rollback_mode' => AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE,
                'requested_scope' => $requestedScope,
                'runtime_activation_statement' => 'Diagnostic hook failed closed. Default selection remains legacy.',
                'selected_planner_remains' => 'legacy',
                'error' => $exception->getMessage(),
                'exception' => $exception::class,
            ];
        }

        app()->instance(self::DIAGNOSTICS_KEY, $diagnostics);

        return $diagnostics;
    }
}
