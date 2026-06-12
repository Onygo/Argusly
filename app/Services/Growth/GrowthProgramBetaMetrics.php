<?php

namespace App\Services\Growth;

use App\Models\GrowthAsset;
use App\Models\GrowthProgram;
use App\Models\GrowthProgramBetaEvent;
use App\Models\ProgrammaticPublicationReadiness;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class GrowthProgramBetaMetrics
{
    /**
     * @return array<string,mixed>
     */
    public function forProgram(GrowthProgram $program): array
    {
        $assets = $program->relationLoaded('assets')
            ? $program->assets
            : $program->assets()->get();

        $metrics = $program->metrics ?? [];
        $productMetrics = $this->productMetrics($assets, $metrics);
        $timeToValue = $this->timeToValue($program, $assets);
        $friction = $this->frictionSummary((string) $program->workspace_id, (string) $program->id);
        $feedback = $this->feedbackSummary((string) $program->workspace_id, (string) $program->id);
        $successScore = $this->successScore($productMetrics, $metrics);

        return [
            'time_to_value' => $timeToValue,
            'product_metrics' => $productMetrics,
            'success_score' => $successScore,
            'friction' => $friction,
            'feedback' => $feedback,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function reportForWorkspace(Workspace $workspace): array
    {
        $programs = GrowthProgram::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotIn('status', ['published', 'measured'])
            ->with('assets')
            ->latest()
            ->get();

        $programMetrics = $programs->map(fn (GrowthProgram $program): array => [
            'program' => $program,
            'metrics' => $this->forProgram($program),
        ]);

        $timeKeys = [
            'first_cluster_minutes',
            'first_blueprint_minutes',
            'first_brief_minutes',
            'first_draft_minutes',
            'first_content_asset_minutes',
            'first_scheduled_publication_record_minutes',
        ];

        $averageTimeToValue = collect($timeKeys)->mapWithKeys(function (string $key) use ($programMetrics): array {
            $values = $programMetrics
                ->pluck('metrics.time_to_value.'.$key)
                ->filter(fn ($value) => $value !== null)
                ->values();

            return [$key => $values->isEmpty() ? null : round((float) $values->avg(), 1)];
        })->all();

        $feedback = $this->feedbackSummary((string) $workspace->id);

        return [
            'active_growth_programs' => $programs->count(),
            'average_time_to_value' => $averageTimeToValue,
            'average_success_score' => $programMetrics->isEmpty() ? 0 : round((float) $programMetrics->pluck('metrics.success_score')->avg(), 1),
            'top_blockers' => $this->topReasons((string) $workspace->id, GrowthProgramBetaEvent::TYPE_BLOCKED),
            'top_conflicts' => $this->topReasons((string) $workspace->id, GrowthProgramBetaEvent::TYPE_CONFLICT),
            'feedback' => $feedback,
            'programs' => $programMetrics,
        ];
    }

    /**
     * @param  Collection<int,GrowthAsset>  $assets
     * @param  array<string,mixed>  $metrics
     * @return array<string,int>
     */
    private function productMetrics(Collection $assets, array $metrics): array
    {
        return [
            'opportunities_detected' => (int) ($metrics['programmatic_opportunities_count'] ?? $assets->where('role', GrowthAsset::ROLE_PROGRAMMATIC_OPPORTUNITY)->count()),
            'opportunities_converted' => (int) ($metrics['programmatic_clusters_count'] ?? $assets->where('role', GrowthAsset::ROLE_PROGRAMMATIC_CLUSTER)->count()),
            'clusters_created' => (int) ($metrics['programmatic_clusters_count'] ?? $assets->where('role', GrowthAsset::ROLE_PROGRAMMATIC_CLUSTER)->count()),
            'blueprints_created' => (int) ($metrics['brief_blueprints_count'] ?? $assets->where('role', GrowthAsset::ROLE_BRIEF_BLUEPRINT)->count()),
            'briefs_created' => (int) ($metrics['programmatic_briefs_count'] ?? $assets->where('role', GrowthAsset::ROLE_BRIEF)->count()),
            'drafts_generated' => (int) ($metrics['generated_programmatic_drafts_count'] ?? $assets->where('role', GrowthAsset::ROLE_DRAFT)->count()),
            'content_created' => (int) ($metrics['converted_content_count'] ?? $assets->where('role', GrowthAsset::ROLE_CONTENT)->count()),
            'readiness_approved' => (int) ($metrics['approved_publication_readiness_count'] ?? $this->approvedReadinessCount($assets)),
            'publication_plans_created' => (int) ($metrics['publication_plans_count'] ?? $assets->where('role', GrowthAsset::ROLE_PUBLICATION_PLAN)->count()),
        ];
    }

    /**
     * @param  Collection<int,GrowthAsset>  $assets
     * @return array<string,int|null>
     */
    private function timeToValue(GrowthProgram $program, Collection $assets): array
    {
        return [
            'first_cluster_minutes' => $this->minutesToFirstAsset($program, $assets, GrowthAsset::ROLE_PROGRAMMATIC_CLUSTER),
            'first_blueprint_minutes' => $this->minutesToFirstAsset($program, $assets, GrowthAsset::ROLE_BRIEF_BLUEPRINT),
            'first_brief_minutes' => $this->minutesToFirstAsset($program, $assets, GrowthAsset::ROLE_BRIEF),
            'first_draft_minutes' => $this->minutesToFirstAsset($program, $assets, GrowthAsset::ROLE_DRAFT),
            'first_content_asset_minutes' => $this->minutesToFirstAsset($program, $assets, GrowthAsset::ROLE_CONTENT),
            'first_scheduled_publication_record_minutes' => $this->minutesToFirstAsset($program, $assets, GrowthAsset::ROLE_PUBLICATION),
        ];
    }

    /**
     * @param  Collection<int,GrowthAsset>  $assets
     */
    private function minutesToFirstAsset(GrowthProgram $program, Collection $assets, string $role): ?int
    {
        $first = $assets
            ->where('role', $role)
            ->filter(fn (GrowthAsset $asset) => $asset->created_at !== null)
            ->sortBy('created_at')
            ->first();

        if (! $program->created_at || ! $first?->created_at) {
            return null;
        }

        return max(0, (int) $program->created_at->diffInMinutes($first->created_at, false));
    }

    /**
     * @param  array<string,int>  $productMetrics
     * @param  array<string,mixed>  $metrics
     */
    private function successScore(array $productMetrics, array $metrics): int
    {
        $stagePoints = [
            'opportunities_detected',
            'clusters_created',
            'blueprints_created',
            'briefs_created',
            'drafts_generated',
            'content_created',
            'readiness_approved',
            'publication_plans_created',
        ];

        $stageProgress = collect($stagePoints)
            ->filter(fn (string $key): bool => ($productMetrics[$key] ?? 0) > 0)
            ->count() / count($stagePoints);

        $assetsProduced = min(1, (
            ($productMetrics['blueprints_created'] ?? 0)
            + ($productMetrics['briefs_created'] ?? 0)
            + ($productMetrics['drafts_generated'] ?? 0)
            + ($productMetrics['content_created'] ?? 0)
        ) / 8);

        $readiness = min(1, ($productMetrics['readiness_approved'] ?? 0) / max(1, (int) ($metrics['publication_readiness_count'] ?? 1)));
        $scheduled = min(1, (int) ($metrics['scheduled_programmatic_publications_count'] ?? 0) / max(1, (int) ($metrics['approved_publication_plan_items_count'] ?? 1)));

        return (int) round(($stageProgress * 30) + ($assetsProduced * 25) + ($readiness * 25) + ($scheduled * 20));
    }

    /**
     * @return array<string,int>
     */
    private function frictionSummary(string $workspaceId, ?string $programId = null): array
    {
        $types = [
            GrowthProgramBetaEvent::TYPE_WORKFLOW_ABANDONED,
            GrowthProgramBetaEvent::TYPE_BACK_NAVIGATION,
            GrowthProgramBetaEvent::TYPE_ACTION_FAILED,
            GrowthProgramBetaEvent::TYPE_CONFLICT,
            GrowthProgramBetaEvent::TYPE_BLOCKED,
            GrowthProgramBetaEvent::TYPE_CANCEL,
        ];

        $counts = GrowthProgramBetaEvent::query()
            ->where('workspace_id', $workspaceId)
            ->when($programId, fn ($query) => $query->where('growth_program_id', $programId))
            ->whereIn('event_type', $types)
            ->selectRaw('event_type, count(*) as aggregate')
            ->groupBy('event_type')
            ->pluck('aggregate', 'event_type');

        return collect($types)
            ->mapWithKeys(fn (string $type): array => [$type => (int) ($counts[$type] ?? 0)])
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function feedbackSummary(string $workspaceId, ?string $programId = null): array
    {
        $counts = GrowthProgramBetaEvent::query()
            ->where('workspace_id', $workspaceId)
            ->when($programId, fn ($query) => $query->where('growth_program_id', $programId))
            ->where('event_type', GrowthProgramBetaEvent::TYPE_FEEDBACK)
            ->selectRaw('clarity, count(*) as aggregate')
            ->groupBy('clarity')
            ->pluck('aggregate', 'clarity');

        return [
            'yes' => (int) ($counts['yes'] ?? 0),
            'somewhat' => (int) ($counts['somewhat'] ?? 0),
            'no' => (int) ($counts['no'] ?? 0),
            'total' => (int) $counts->sum(),
        ];
    }

    /**
     * @return array<int,array{reason:string,count:int}>
     */
    private function topReasons(string $workspaceId, string $type): array
    {
        return GrowthProgramBetaEvent::query()
            ->where('workspace_id', $workspaceId)
            ->where('event_type', $type)
            ->latest()
            ->limit(250)
            ->get()
            ->map(fn (GrowthProgramBetaEvent $event): string => (string) data_get($event->metadata, 'reason', $event->message ?: 'unspecified'))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->take(5)
            ->map(fn (int $count, string $reason): array => ['reason' => $reason, 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,GrowthAsset>  $assets
     */
    private function approvedReadinessCount(Collection $assets): int
    {
        return $assets
            ->where('role', GrowthAsset::ROLE_PUBLICATION_READINESS)
            ->filter(fn (GrowthAsset $asset): bool => $asset->assetable instanceof ProgrammaticPublicationReadiness
                && $asset->assetable->status === ProgrammaticPublicationReadiness::STATUS_APPROVED)
            ->count();
    }
}
