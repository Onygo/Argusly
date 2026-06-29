<?php

namespace App\Services\Mos\Opportunity;

use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Models\RecommendedAction;
use Illuminate\Support\Collection;

class ContentOpportunityRecommendedActionRepairService
{
    public function __construct(
        private readonly ContentOpportunityRecommendedActionDedupeService $dedupe,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function propose(ContentOpportunity $contentOpportunity): array
    {
        return $this->repair($contentOpportunity, false);
    }

    /**
     * @return array<string,mixed>
     */
    public function annotate(ContentOpportunity $contentOpportunity, ?string $actor = null): array
    {
        return $this->repair($contentOpportunity, true, $actor);
    }

    /**
     * @return array<string,mixed>
     */
    private function repair(ContentOpportunity $contentOpportunity, bool $apply, ?string $actor = null): array
    {
        $inspection = $this->dedupe->inspect($contentOpportunity);
        $actions = $this->actions($inspection);
        $skippedReasons = $this->repairSkippedReasons($inspection, $actions);
        $primary = $skippedReasons === [] ? $this->primaryAction($actions) : null;
        $groupId = $this->groupId((string) $inspection['canonical_equivalent_signature']);
        $annotatedCount = 0;

        if ($apply && $primary !== null) {
            $annotatedCount = $actions
                ->map(fn (RecommendedAction $action): bool => $this->annotateAction(
                    action: $action,
                    inspection: $inspection,
                    groupId: $groupId,
                    primary: $primary,
                    actor: $actor,
                ))
                ->filter()
                ->count();
        }

        return array_merge($inspection, [
            'duplicate_group_id' => $groupId,
            'primary_action_id' => $primary?->id ? (string) $primary->id : null,
            'duplicate_action_ids' => $primary
                ? $actions->reject(fn (RecommendedAction $action): bool => $action->is($primary))->pluck('id')->map(fn (string $id): string => (string) $id)->values()->all()
                : [],
            'repair_status' => $apply ? 'annotated' : 'suggested',
            'repair_apply' => $apply,
            'would_annotate' => $primary !== null && $skippedReasons === [],
            'annotated_count' => $annotatedCount,
            'repair_skipped_reasons' => $skippedReasons,
            'primary_selection_rule' => 'open status first, then oldest created_at, then legacy ContentOpportunity source, then id',
        ]);
    }

    /**
     * @param  array<string,mixed>  $inspection
     * @return Collection<int,RecommendedAction>
     */
    private function actions(array $inspection): Collection
    {
        $ids = collect($inspection['actions'] ?? [])
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return RecommendedAction::query()
            ->whereIn('id', $ids)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->values();
    }

    /**
     * @param  array<string,mixed>  $inspection
     * @param  Collection<int,RecommendedAction>  $actions
     * @return array<int,string>
     */
    private function repairSkippedReasons(array $inspection, Collection $actions): array
    {
        $reasons = $inspection['skipped_reasons'] ?? [];

        if ((int) $inspection['duplicate_count'] < 1 || $actions->count() < 2) {
            $reasons[] = 'no_duplicate_actions';
        }

        if (! $this->hasLegacyAction($inspection, $actions)) {
            $reasons[] = 'missing_legacy_action_reference';
        }

        if (! $this->hasCanonicalAction($inspection, $actions)) {
            $reasons[] = 'missing_canonical_action_reference';
        }

        return array_values(array_unique(array_filter($reasons)));
    }

    /**
     * @param  Collection<int,RecommendedAction>  $actions
     */
    private function primaryAction(Collection $actions): ?RecommendedAction
    {
        return $actions
            ->sortBy(fn (RecommendedAction $action): array => [
                $action->status === RecommendedAction::STATUS_OPEN ? 0 : 1,
                $action->created_at?->getTimestamp() ?? PHP_INT_MAX,
                $action->source_type === ContentOpportunity::class ? 0 : 1,
                (string) $action->id,
            ])
            ->first();
    }

    /**
     * @param  array<string,mixed>  $inspection
     */
    private function annotateAction(
        RecommendedAction $action,
        array $inspection,
        string $groupId,
        RecommendedAction $primary,
        ?string $actor,
    ): bool {
        $metadata = $action->metadata ?? [];
        $metadata['canonical_equivalence'] = [
            'canonical_equivalent_signature' => $inspection['canonical_equivalent_signature'],
            'legacy_content_opportunity_id' => $inspection['legacy_content_opportunity_id'],
            'canonical_opportunity_id' => $inspection['canonical_opportunity_id'],
            'duplicate_group_id' => $groupId,
            'duplicate_role' => $action->is($primary) ? 'primary' : 'duplicate',
            'repair_status' => 'annotated',
            'repaired_at' => now()->toIso8601String(),
            'repair_actor' => $actor,
            'reason' => $action->is($primary)
                ? 'Deterministic primary for linked legacy/canonical recommended action duplicate group.'
                : 'Non-destructive duplicate annotation for linked legacy/canonical recommended action group.',
        ];

        return $action->forceFill(['metadata' => $metadata])->save();
    }

    /**
     * @param  array<string,mixed>  $inspection
     * @param  Collection<int,RecommendedAction>  $actions
     */
    private function hasLegacyAction(array $inspection, Collection $actions): bool
    {
        return $actions->contains(fn (RecommendedAction $action): bool => $action->source_type === ContentOpportunity::class
            && (string) $action->source_id === (string) $inspection['legacy_content_opportunity_id']);
    }

    /**
     * @param  array<string,mixed>  $inspection
     * @param  Collection<int,RecommendedAction>  $actions
     */
    private function hasCanonicalAction(array $inspection, Collection $actions): bool
    {
        return $actions->contains(fn (RecommendedAction $action): bool => $action->source_type === Opportunity::class
            && (string) $action->source_id === (string) $inspection['canonical_opportunity_id']);
    }

    private function groupId(string $canonicalEquivalentSignature): string
    {
        return hash('sha256', 'recommended_action_canonical_equivalence|'.$canonicalEquivalentSignature);
    }
}
