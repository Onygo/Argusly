<?php

namespace App\Services\Mos\OperatingSystem;

use App\Models\MarketingInitiative;
use App\Models\MarketingObjective;
use App\Models\User;

class MarketingInitiativeLifecycle
{
    public function __construct(
        private readonly MarketingTimeline $timeline,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(MarketingObjective $objective, array $attributes): MarketingInitiative
    {
        $initiative = MarketingInitiative::query()->create(array_merge($attributes, [
            'organization_id' => $attributes['organization_id'] ?? $objective->organization_id,
            'workspace_id' => $objective->workspace_id,
            'client_site_id' => $attributes['client_site_id'] ?? $objective->client_site_id,
            'marketing_objective_id' => $objective->id,
            'marketing_theme_id' => $attributes['marketing_theme_id'] ?? $objective->marketing_theme_id,
            'status' => $attributes['status'] ?? MarketingInitiative::STATUS_PLANNED,
            'priority' => $attributes['priority'] ?? $objective->priority,
            'market_pack_key' => $attributes['market_pack_key'] ?? $objective->market_pack_key,
        ]));

        $this->timeline->record(
            $initiative,
            'initiative.created',
            'Marketing initiative created',
            $initiative->name,
            metadata: ['status' => $initiative->status, 'priority' => $initiative->priority],
        );

        return $initiative;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function transition(MarketingInitiative $initiative, string $status, ?User $actor = null, array $metadata = []): MarketingInitiative
    {
        $previous = (string) $initiative->status;
        $initiative->forceFill(['status' => $status])->save();

        $this->timeline->record(
            $initiative,
            'initiative.status_changed',
            'Marketing initiative status changed',
            "{$previous} -> {$status}",
            $actor,
            ['from' => $previous, 'to' => $status] + $metadata,
        );

        return $initiative->refresh();
    }
}
