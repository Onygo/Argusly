<?php

namespace App\Services\Mos\OperatingSystem;

use App\Models\MarketingInitiative;
use App\Models\MarketingObjective;
use App\Models\MarketingTimelineEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MarketingTimeline
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        MarketingObjective|MarketingInitiative $subject,
        string $eventType,
        string $title,
        ?string $summary = null,
        ?User $actor = null,
        array $metadata = [],
        ?array $resource = null,
    ): MarketingTimelineEvent {
        $objective = $subject instanceof MarketingObjective ? $subject : $subject->objective;
        $initiative = $subject instanceof MarketingInitiative ? $subject : null;

        return MarketingTimelineEvent::query()->create([
            'organization_id' => $subject->organization_id,
            'workspace_id' => $subject->workspace_id,
            'marketing_objective_id' => $objective?->id,
            'marketing_initiative_id' => $initiative?->id,
            'actor_id' => $actor?->id,
            'occurred_at' => Carbon::now(),
            'event_type' => $eventType,
            'title' => $title,
            'summary' => $summary,
            'resource_type' => $resource['type'] ?? null,
            'resource_id' => $resource['id'] ?? null,
            'resource_key' => $resource['key'] ?? null,
            'metadata_json' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordModelLink(
        MarketingObjective|MarketingInitiative $subject,
        string $eventType,
        string $title,
        Model $model,
        ?string $summary = null,
        array $metadata = [],
    ): MarketingTimelineEvent {
        return $this->record($subject, $eventType, $title, $summary, metadata: $metadata, resource: [
            'type' => $this->modelResourceType($model),
            'id' => (string) $model->getKey(),
            'key' => $this->modelResourceType($model).':'.$model->getKey(),
        ]);
    }

    private function modelResourceType(Model $model): string
    {
        return Str::of($model::class)->classBasename()->snake()->toString();
    }
}
