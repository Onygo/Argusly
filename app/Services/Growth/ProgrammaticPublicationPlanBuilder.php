<?php

namespace App\Services\Growth;

use App\Enums\GrowthAssetType;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\GrowthProgram;
use App\Models\ProgrammaticCluster;
use App\Models\ProgrammaticPublicationPlan;
use App\Models\ProgrammaticPublicationPlanItem;
use App\Models\ProgrammaticPublicationReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ProgrammaticPublicationPlanBuilder
{
    /**
     * @param array<string,mixed> $attributes
     */
    public function createFromReadiness(ProgrammaticPublicationReadiness $readiness, array $attributes = []): ProgrammaticPublicationPlan
    {
        if ($readiness->status !== ProgrammaticPublicationReadiness::STATUS_APPROVED) {
            throw new InvalidArgumentException('Only approved publication readiness records can be planned.');
        }

        $readiness->loadMissing(['content', 'growthProgram']);
        $workspaceId = (string) $readiness->workspace_id;
        $destinationId = $this->destinationId($readiness, $attributes);

        return DB::transaction(function () use ($readiness, $attributes, $workspaceId, $destinationId): ProgrammaticPublicationPlan {
            $plan = ProgrammaticPublicationPlan::query()->create([
                'workspace_id' => $workspaceId,
                'growth_program_id' => $attributes['growth_program_id'] ?? $readiness->growth_program_id,
                'name' => trim((string) ($attributes['name'] ?? 'Publication plan: '.$readiness->content?->title)),
                'description' => trim((string) ($attributes['description'] ?? '')) ?: null,
                'status' => ProgrammaticPublicationPlan::STATUS_DRAFT,
                'planned_start_at' => $this->date($attributes['planned_start_at'] ?? null),
                'cadence' => $this->cadence((string) ($attributes['cadence'] ?? config('argusly_programmatic.default_publication_cadence', ProgrammaticPublicationPlan::CADENCE_MANUAL))),
                'destination_id' => $destinationId,
                'metadata' => [
                    'source' => 'publication_readiness',
                    'publication_readiness_id' => (string) $readiness->id,
                    'allow_auto_scheduling' => (bool) config('argusly_programmatic.allow_auto_scheduling', false),
                    'require_plan_approval' => (bool) config('argusly_programmatic.require_plan_approval', true),
                    'custom_interval_days' => (int) ($attributes['custom_interval_days'] ?? 0),
                ],
            ]);

            $this->addReadinessToPlan($plan, $readiness);
            $this->recalculateCadence($plan);

            return $plan->refresh();
        });
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function createForCluster(ProgrammaticCluster $cluster, array $attributes = []): ProgrammaticPublicationPlan
    {
        $readinessRecords = ProgrammaticPublicationReadiness::query()
            ->where('programmatic_cluster_id', $cluster->id)
            ->where('status', ProgrammaticPublicationReadiness::STATUS_APPROVED)
            ->with(['content', 'item'])
            ->get()
            ->sortBy(fn (ProgrammaticPublicationReadiness $readiness): array => $this->sortKey($readiness))
            ->take((int) config('argusly_programmatic.max_plan_items_per_cluster', 25))
            ->values();

        if ($readinessRecords->isEmpty()) {
            throw new InvalidArgumentException('No approved publication readiness records are available for this cluster.');
        }

        return $this->createPlanFromReadinessCollection($readinessRecords, array_replace([
            'name' => 'Publication plan: '.$cluster->name,
            'description' => 'Programmatic publication plan for cluster '.$cluster->name.'.',
            'growth_program_id' => $cluster->growth_program_id,
            'source' => 'programmatic_cluster',
            'programmatic_cluster_id' => (string) $cluster->id,
        ], $attributes));
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function createForProgram(GrowthProgram $program, array $attributes = []): ProgrammaticPublicationPlan
    {
        $readinessRecords = ProgrammaticPublicationReadiness::query()
            ->where('growth_program_id', $program->id)
            ->where('status', ProgrammaticPublicationReadiness::STATUS_APPROVED)
            ->with(['content', 'item'])
            ->get()
            ->sortBy(fn (ProgrammaticPublicationReadiness $readiness): array => $this->sortKey($readiness))
            ->take((int) config('argusly_programmatic.max_plan_items_per_growth_program', 100))
            ->values();

        if ($readinessRecords->isEmpty()) {
            throw new InvalidArgumentException('No approved publication readiness records are available for this growth program.');
        }

        return $this->createPlanFromReadinessCollection($readinessRecords, array_replace([
            'name' => 'Publication plan: '.$program->name,
            'description' => 'Programmatic publication plan for growth program '.$program->name.'.',
            'growth_program_id' => (string) $program->id,
            'source' => 'growth_program',
        ], $attributes));
    }

    public function addReadinessToPlan(ProgrammaticPublicationPlan $plan, ProgrammaticPublicationReadiness $readiness): ProgrammaticPublicationPlanItem
    {
        if ($readiness->status !== ProgrammaticPublicationReadiness::STATUS_APPROVED) {
            throw new InvalidArgumentException('Only approved publication readiness records can be planned.');
        }

        if ((string) $plan->workspace_id !== (string) $readiness->workspace_id) {
            throw new InvalidArgumentException('Publication readiness belongs to another workspace.');
        }

        $readiness->loadMissing(['content', 'item']);
        $content = $readiness->content;
        if (! $content instanceof Content) {
            throw new InvalidArgumentException('Publication readiness has no linked content.');
        }

        $item = ProgrammaticPublicationPlanItem::query()->updateOrCreate(
            [
                'programmatic_publication_plan_id' => (string) $plan->id,
                'publication_readiness_id' => (string) $readiness->id,
            ],
            [
                'workspace_id' => (string) $plan->workspace_id,
                'content_id' => (string) $content->id,
                'growth_asset_type' => $this->growthAssetTypeValue($readiness),
                'title' => $content->title,
                'slug' => $content->canonical_url_key ?: $content->publish_url_key ?: Str::slug($content->title),
                'destination_id' => $plan->destination_id ?: $content->content_destination_id,
                'status' => ProgrammaticPublicationPlanItem::STATUS_PLANNED,
                'priority_score' => $this->priorityScore($readiness),
                'publication_risk_score' => (float) $readiness->publication_risk_score,
                'metadata' => [
                    'source' => 'publication_readiness',
                    'publication_readiness_status' => $readiness->status,
                    'readiness_score' => (float) $readiness->readiness_score,
                    'programmatic_cluster_id' => $readiness->programmatic_cluster_id,
                    'programmatic_cluster_item_id' => $readiness->programmatic_cluster_item_id,
                ],
            ]
        );

        $plan->refreshCounters();

        return $item->refresh();
    }

    public function recalculateCadence(ProgrammaticPublicationPlan $plan): ProgrammaticPublicationPlan
    {
        $plan->loadMissing('items.readiness.item');
        $items = $plan->items
            ->sortBy(fn (ProgrammaticPublicationPlanItem $item): array => [
                -1 * (float) $item->priority_score,
                (float) $item->publication_risk_score,
                $this->assetTypeWeight($item->growth_asset_type instanceof GrowthAssetType ? $item->growth_asset_type->value : (string) $item->growth_asset_type),
                (string) $item->title,
            ])
            ->values();

        $start = $plan->planned_start_at ? CarbonImmutable::parse($plan->planned_start_at) : null;
        $interval = $this->cadenceIntervalDays($plan);

        foreach ($items as $index => $item) {
            $date = $start && $interval !== null
                ? $start->addDays($interval * $index)
                : null;

            $item->forceFill(['planned_publish_at' => $date])->save();
        }

        return $plan->refreshCounters();
    }

    /**
     * @param Collection<int,ProgrammaticPublicationReadiness> $readinessRecords
     * @param array<string,mixed> $attributes
     */
    private function createPlanFromReadinessCollection(Collection $readinessRecords, array $attributes): ProgrammaticPublicationPlan
    {
        $first = $readinessRecords->first();
        $destinationId = $this->destinationId($first, $attributes);

        return DB::transaction(function () use ($readinessRecords, $attributes, $first, $destinationId): ProgrammaticPublicationPlan {
            $plan = ProgrammaticPublicationPlan::query()->create([
                'workspace_id' => (string) $first->workspace_id,
                'growth_program_id' => $attributes['growth_program_id'] ?? $first->growth_program_id,
                'name' => trim((string) ($attributes['name'] ?? 'Programmatic publication plan')),
                'description' => trim((string) ($attributes['description'] ?? '')) ?: null,
                'status' => ProgrammaticPublicationPlan::STATUS_DRAFT,
                'planned_start_at' => $this->date($attributes['planned_start_at'] ?? null),
                'cadence' => $this->cadence((string) ($attributes['cadence'] ?? config('argusly_programmatic.default_publication_cadence', ProgrammaticPublicationPlan::CADENCE_MANUAL))),
                'destination_id' => $destinationId,
                'metadata' => [
                    'source' => (string) ($attributes['source'] ?? 'programmatic_publication_plan_builder'),
                    'programmatic_cluster_id' => $attributes['programmatic_cluster_id'] ?? null,
                    'allow_auto_scheduling' => (bool) config('argusly_programmatic.allow_auto_scheduling', false),
                    'require_plan_approval' => (bool) config('argusly_programmatic.require_plan_approval', true),
                    'custom_interval_days' => (int) ($attributes['custom_interval_days'] ?? 0),
                ],
            ]);

            $readinessRecords->each(fn (ProgrammaticPublicationReadiness $readiness): ProgrammaticPublicationPlanItem => $this->addReadinessToPlan($plan, $readiness));
            $this->recalculateCadence($plan);

            return $plan->refresh();
        });
    }

    /**
     * @return array<int,mixed>
     */
    private function sortKey(ProgrammaticPublicationReadiness $readiness): array
    {
        return [
            -1 * $this->priorityScore($readiness),
            (float) $readiness->publication_risk_score,
            $this->assetTypeWeight($this->growthAssetTypeValue($readiness)),
            (string) ($readiness->content?->title ?? ''),
        ];
    }

    private function priorityScore(ProgrammaticPublicationReadiness $readiness): float
    {
        return max(
            (float) ($readiness->item?->priority_score ?? 0),
            (float) $readiness->readiness_score - ((float) $readiness->publication_risk_score * 0.35)
        );
    }

    private function growthAssetTypeValue(ProgrammaticPublicationReadiness $readiness): ?string
    {
        return $readiness->growth_asset_type instanceof GrowthAssetType
            ? $readiness->growth_asset_type->value
            : ($readiness->growth_asset_type ?: null);
    }

    private function assetTypeWeight(?string $type): int
    {
        return match ($type) {
            GrowthAssetType::PILLAR_PAGE->value => 0,
            GrowthAssetType::INDUSTRY_PAGE->value,
            GrowthAssetType::LANDING_PAGE->value,
            GrowthAssetType::COMPARISON_PAGE->value => 1,
            GrowthAssetType::SUPPORTING_PAGE->value,
            GrowthAssetType::FAQ_PAGE->value,
            GrowthAssetType::AI_ANSWER_PAGE->value => 2,
            default => 3,
        };
    }

    private function cadence(string $cadence): string
    {
        return in_array($cadence, ProgrammaticPublicationPlan::cadences(), true)
            ? $cadence
            : ProgrammaticPublicationPlan::CADENCE_MANUAL;
    }

    private function cadenceIntervalDays(ProgrammaticPublicationPlan $plan): ?int
    {
        return match ($plan->cadence) {
            ProgrammaticPublicationPlan::CADENCE_DAILY => 1,
            ProgrammaticPublicationPlan::CADENCE_EVERY_2_DAYS => 2,
            ProgrammaticPublicationPlan::CADENCE_WEEKLY => 7,
            ProgrammaticPublicationPlan::CADENCE_CUSTOM_INTERVAL_DAYS => max(1, (int) data_get($plan->metadata, 'custom_interval_days', 1)),
            default => null,
        };
    }

    private function date(mixed $value): mixed
    {
        return $value ? CarbonImmutable::parse($value) : null;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    private function destinationId(ProgrammaticPublicationReadiness $readiness, array $attributes): ?string
    {
        if (! empty($attributes['destination_id'])) {
            return (string) $attributes['destination_id'];
        }

        $readiness->loadMissing('content');

        if ($readiness->content?->content_destination_id) {
            return (string) $readiness->content->content_destination_id;
        }

        return ContentDestination::query()
            ->where('workspace_id', $readiness->workspace_id)
            ->where('status', 'active')
            ->value('id');
    }
}
