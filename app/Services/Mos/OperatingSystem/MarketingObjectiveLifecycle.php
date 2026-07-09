<?php

namespace App\Services\Mos\OperatingSystem;

use App\Models\MarketingObjective;
use App\Models\MarketingTheme;
use App\Models\User;
use App\Models\Workspace;

class MarketingObjectiveLifecycle
{
    public function __construct(
        private readonly MarketingTimeline $timeline,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Workspace $workspace, array $attributes): MarketingObjective
    {
        $objective = MarketingObjective::query()->create(array_merge($attributes, [
            'organization_id' => $attributes['organization_id'] ?? $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $attributes['client_site_id'] ?? null,
            'status' => $attributes['status'] ?? MarketingObjective::STATUS_DRAFT,
            'priority' => $attributes['priority'] ?? MarketingObjective::PRIORITY_MEDIUM,
        ]));

        $this->timeline->record(
            $objective,
            'objective.created',
            'Marketing objective created',
            $objective->name,
            metadata: ['status' => $objective->status, 'priority' => $objective->priority],
        );

        return $objective;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createTheme(Workspace $workspace, array $attributes): MarketingTheme
    {
        return MarketingTheme::query()->create(array_merge($attributes, [
            'organization_id' => $attributes['organization_id'] ?? $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $attributes['client_site_id'] ?? null,
            'status' => $attributes['status'] ?? 'active',
            'priority' => $attributes['priority'] ?? MarketingObjective::PRIORITY_MEDIUM,
        ]));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function transition(MarketingObjective $objective, string $status, ?User $actor = null, array $metadata = []): MarketingObjective
    {
        $previous = (string) $objective->status;
        $objective->forceFill(['status' => $status])->save();

        $this->timeline->record(
            $objective,
            'objective.status_changed',
            'Marketing objective status changed',
            "{$previous} -> {$status}",
            $actor,
            ['from' => $previous, 'to' => $status] + $metadata,
        );

        return $objective->refresh();
    }
}
