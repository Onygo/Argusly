<?php

namespace App\View\Presenters;

use App\Enums\ContentLifecycleStatus;
use App\Enums\ContentIntelligenceStatus;
use App\Models\Content;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Presenter for the content lifecycle dashboard.
 *
 * Groups content by lifecycle stage and computes summaries
 * for display in the kanban-style dashboard.
 */
class ContentLifecycleDashboardPresenter
{
    /**
     * Group content collection by lifecycle stage.
     *
     * @param  Collection<int, Content>  $contents
     * @param  array<string, mixed>  $filters
     * @return array<string, array{stage: ContentLifecycleStatus, contents: Collection<int, Content>, summary: array}>
     */
    public function groupByStage(Collection $contents, array $filters = []): array
    {
        $stages = ContentLifecycleStatus::canonicalStages();
        $grouped = [];

        foreach ($stages as $stage) {
            $stageContents = $contents->filter(function (Content $content) use ($stage) {
                $contentStage = $content->lifecycleStageEnum()->normalized();

                return $contentStage === $stage->normalized();
            });

            $grouped[$stage->value] = [
                'stage' => $stage,
                'contents' => $stageContents->values(),
                'summary' => $this->getStageSummary($stage, $stageContents),
            ];
        }

        return $grouped;
    }

    /**
     * Get summary statistics for a stage.
     *
     * @param  ContentLifecycleStatus  $stage
     * @param  Collection<int, Content>  $contents
     * @return array{count: int, overdue: int, due_soon: int, avg_age_days: float|null, oldest_at: Carbon|null}
     */
    public function getStageSummary(ContentLifecycleStatus $stage, Collection $contents): array
    {
        $now = Carbon::now();

        $overdue = $contents->filter(fn (Content $c) => $c->isOverdue())->count();

        $dueSoon = $contents->filter(function (Content $c) use ($now) {
            if (! $c->due_at) {
                return false;
            }
            $daysUntilDue = $now->diffInDays($c->due_at, false);

            return $daysUntilDue > 0 && $daysUntilDue <= 7;
        })->count();

        // Calculate average age in days
        $avgAgeDays = null;
        if ($contents->isNotEmpty()) {
            $totalDays = $contents->sum(fn (Content $c) => $now->diffInDays($c->updated_at, false));
            $avgAgeDays = round(abs($totalDays) / $contents->count(), 1);
        }

        // Get oldest content entry date
        $oldestAt = $contents->min('updated_at');

        return [
            'count' => $contents->count(),
            'overdue' => $overdue,
            'due_soon' => $dueSoon,
            'avg_age_days' => $avgAgeDays,
            'oldest_at' => $oldestAt ? Carbon::parse($oldestAt) : null,
        ];
    }

    /**
     * Get summaries for all canonical stages.
     *
     * @param  Collection<int, Content>  $contents
     * @return array<string, array{count: int, overdue: int, due_soon: int, avg_age_days: float|null, oldest_at: Carbon|null}>
     */
    public function getAllStageSummaries(Collection $contents): array
    {
        $summaries = [];

        foreach (ContentLifecycleStatus::canonicalStages() as $stage) {
            $stageContents = $contents->filter(function (Content $content) use ($stage) {
                $contentStage = $content->lifecycleStageEnum()->normalized();

                return $contentStage === $stage->normalized();
            });

            $summaries[$stage->value] = $this->getStageSummary($stage, $stageContents);
        }

        // Add total summary
        $summaries['_total'] = [
            'count' => $contents->count(),
            'overdue' => $contents->filter(fn (Content $c) => $c->isOverdue())->count(),
            'due_soon' => $contents->filter(function (Content $c) {
                if (! $c->due_at) {
                    return false;
                }
                $daysUntilDue = Carbon::now()->diffInDays($c->due_at, false);

                return $daysUntilDue > 0 && $daysUntilDue <= 7;
            })->count(),
            'avg_age_days' => null,
            'oldest_at' => null,
        ];

        return $summaries;
    }

    /**
     * Format a content card for display.
     *
     * @return array<string, mixed>
     */
    public function formatContentCard(Content $content): array
    {
        $stage = $content->lifecycleStageEnum();
        $intelligenceStatus = $content->intelligence_status
            ?? ContentIntelligenceStatus::OPPORTUNITY;

        return [
            'id' => $content->id,
            'title' => $content->title,
            'stage' => $stage,
            'stage_label' => $stage->label(),
            'stage_color' => $stage->color(),
            'stage_icon' => $stage->icon(),
            'assigned_user' => $content->assignedUser?->name,
            'reviewer_user' => $content->reviewerUser?->name,
            'due_at' => $content->due_at,
            'is_overdue' => $content->isOverdue(),
            'is_due_soon' => $this->isDueSoon($content),
            'site_name' => $content->clientSite?->name,
            'series_name' => $content->series?->name,
            'automation_name' => $content->automation?->name,
            'locale' => $content->language?->value ?? 'en',
            'locale_label' => strtoupper($content->language?->value ?? 'EN'),
            'updated_at' => $content->updated_at,
            'created_at' => $content->created_at,
            'allowed_transitions' => $stage->allowedTransitions(),
            'rejection_reason' => $content->rejection_reason,
            'workflow_label' => $stage->label(),
            'workflow_color' => $stage->color(),
            'intelligence_label' => $intelligenceStatus->label(),
            'intelligence_color' => $intelligenceStatus->color(),
            'content_health_score' => (int) ($content->content_health_score ?? 0),
            'ai_visibility_score' => is_numeric($content->ai_visibility_score) ? (int) $content->ai_visibility_score : null,
            'decay_risk_level' => (string) ($content->decay_risk_level?->value ?? $content->decay_risk_level ?? ''),
            'signal_badges' => [],
            'recommendations_count' => (int) ($content->recommendations_count ?? 0),
            'provider_pills' => [],
            'ai_visibility_trend' => 0,
        ];
    }

    /**
     * Check if content is due within 7 days.
     */
    private function isDueSoon(Content $content): bool
    {
        if (! $content->due_at) {
            return false;
        }

        $daysUntilDue = Carbon::now()->diffInDays($content->due_at, false);

        return $daysUntilDue > 0 && $daysUntilDue <= 7;
    }

    /**
     * Get quick action buttons for a content item based on stage.
     *
     * @return array<int, array{label: string, action: string, icon: string, confirm: bool}>
     */
    public function getQuickActions(Content $content): array
    {
        $stage = $content->lifecycleStageEnum();
        $actions = [];

        foreach ($stage->allowedTransitions() as $targetStage) {
            // Skip archived unless explicitly needed
            if ($targetStage === ContentLifecycleStatus::ARCHIVED) {
                continue;
            }

            $actions[] = [
                'label' => $this->getTransitionLabel($stage, $targetStage),
                'action' => $targetStage->value,
                'icon' => $targetStage->icon(),
                'confirm' => $this->requiresConfirmation($targetStage),
            ];
        }

        // Always show archive option at the end
        $actions[] = [
            'label' => 'Archive',
            'action' => ContentLifecycleStatus::ARCHIVED->value,
            'icon' => 'archive',
            'confirm' => true,
        ];

        return $actions;
    }

    /**
     * Get a human-readable transition label.
     */
    private function getTransitionLabel(
        ContentLifecycleStatus $from,
        ContentLifecycleStatus $to
    ): string {
        return match ($to) {
            ContentLifecycleStatus::IDEA => 'Move to Ideas',
            ContentLifecycleStatus::BRIEF => 'Move to Brief',
            ContentLifecycleStatus::DRAFT => $from === ContentLifecycleStatus::REVIEW ? 'Request Changes' : 'Move to Draft',
            ContentLifecycleStatus::REVIEW => 'Send for Review',
            ContentLifecycleStatus::APPROVED => 'Approve',
            ContentLifecycleStatus::SCHEDULED => 'Schedule',
            ContentLifecycleStatus::PUBLISHED => 'Mark Published',
            ContentLifecycleStatus::REFRESH_NEEDED => 'Mark for Refresh',
            ContentLifecycleStatus::ARCHIVED => 'Archive',
            default => sprintf('Move to %s', $to->label()),
        };
    }

    /**
     * Check if a transition requires confirmation.
     */
    private function requiresConfirmation(ContentLifecycleStatus $target): bool
    {
        return in_array($target, [
            ContentLifecycleStatus::ARCHIVED,
            ContentLifecycleStatus::PUBLISHED,
        ], true);
    }
}
