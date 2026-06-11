<?php

namespace App\Services\Onboarding\Providers;

use App\Services\Onboarding\ModuleReadinessResult;
use App\Services\Onboarding\ReadinessAction;
use App\Services\Onboarding\ReadinessProvider;
use App\Services\Onboarding\ReadinessRequirement;
use Illuminate\Support\Facades\Route;

abstract class BaseReadinessProvider implements ReadinessProvider
{
    /**
     * @param array<int,ReadinessRequirement> $requirements
     * @param array<int,ReadinessAction> $actions
     */
    protected function result(array $requirements, array $actions, ?string $blockingMessage = null, bool $active = false): ModuleReadinessResult
    {
        $completed = collect($requirements)->where('completed', true)->count();
        $total = max(1, count($requirements));
        $progress = (int) round(($completed / $total) * 100);
        $status = match (true) {
            $active => 'active',
            $completed <= 1 => 'not_ready',
            $completed <= 3 => 'partially_ready',
            default => 'ready',
        };

        return new ModuleReadinessResult(
            key: $this->key(),
            label: $this->label(),
            description: $this->description(),
            status: $status,
            progress: $progress,
            requirements: $requirements,
            missing_requirements: collect($requirements)->reject(fn (ReadinessRequirement $requirement): bool => $requirement->completed)->values()->all(),
            recommended_actions: $actions,
            blocking_message: $blockingMessage,
            is_active: $active,
        );
    }

    protected function routeOrNull(string $route, mixed $parameters = []): ?string
    {
        return Route::has($route) ? route($route, $parameters) : null;
    }

    protected function action(string $label, string $description, ?string $route, string $type = 'secondary'): ReadinessAction
    {
        return new ReadinessAction($label, $description, $route, $route ? $type : 'disabled');
    }
}
