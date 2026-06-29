<?php

namespace App\Services\Mos\Opportunity;

use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Models\RecommendedAction;
use App\Services\RecommendedActions\RecommendedActionMapper;
use Illuminate\Support\Collection;

class ContentOpportunityRecommendedActionDedupeService
{
    public function __construct(
        private readonly RecommendedActionMapper $mapper,
        private readonly ContentOpportunityRecommendedActionSignature $signature,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function inspect(ContentOpportunity $contentOpportunity): array
    {
        $canonical = $this->linkedCanonicalOpportunity($contentOpportunity);
        $legacyPayload = $this->mapper->map($contentOpportunity);
        $canonicalPayload = $canonical ? $this->mapper->map($canonical) : null;
        $legacyPreviousSignature = $this->signature->legacySignature(
            $contentOpportunity,
            $contentOpportunity->workspace,
            RecommendedAction::SOURCE_CONTENT_INTELLIGENCE,
        );
        $canonicalPreviousSignature = $canonical
            ? $this->signature->legacySignature($canonical, $canonical->workspace, RecommendedAction::SOURCE_OPPORTUNITY)
            : null;

        $actions = $this->actions($contentOpportunity, $canonical, array_values(array_filter([
            $legacyPayload['source_signature'],
            $canonicalPayload['source_signature'] ?? null,
            $legacyPreviousSignature,
            $canonicalPreviousSignature,
        ])));

        $sharedSignature = (string) $legacyPayload['source_signature'];
        $sharedSignatureCount = $actions
            ->where('source_signature', $sharedSignature)
            ->count();
        $sourceReferenceCount = $actions
            ->filter(fn (RecommendedAction $action): bool => $this->isLegacyAction($action, $contentOpportunity) || $this->isCanonicalAction($action, $canonical))
            ->count();
        $duplicateCount = max(0, $actions->count() - 1);

        return [
            'legacy_content_opportunity_id' => (string) $contentOpportunity->id,
            'canonical_opportunity_id' => $canonical?->id ? (string) $canonical->id : null,
            'workspace_id' => $contentOpportunity->workspace_id,
            'client_site_id' => $contentOpportunity->client_site_id,
            'linked' => $canonical !== null,
            'canonical_equivalent_signature' => $sharedSignature,
            'legacy_source_signature' => $legacyPreviousSignature,
            'canonical_source_signature' => $canonicalPreviousSignature,
            'signature_parts' => $this->signature->signatureParts(
                $canonical ?? $contentOpportunity,
                $canonical?->workspace ?? $contentOpportunity->workspace,
                'review_opportunity',
            ),
            'existing_action_count' => $actions->count(),
            'shared_signature_action_count' => $sharedSignatureCount,
            'source_reference_action_count' => $sourceReferenceCount,
            'duplicate_count' => $duplicateCount,
            'safe_candidate_count' => $this->safeCandidateCount($canonical, $actions, $sharedSignature),
            'safe_to_apply' => false,
            'skipped_reasons' => $this->skippedReasons($contentOpportunity, $canonical),
            'actions' => $actions->map(fn (RecommendedAction $action): array => [
                'id' => (string) $action->id,
                'source_type' => $action->source_type,
                'source_id' => (string) $action->source_id,
                'source_group' => $action->source_group,
                'action_type' => $action->action_type,
                'status' => $action->status,
                'source_signature' => $action->source_signature,
            ])->values()->all(),
        ];
    }

    private function linkedCanonicalOpportunity(ContentOpportunity $contentOpportunity): ?Opportunity
    {
        return Opportunity::query()
            ->where('content_opportunity_id', $contentOpportunity->id)
            ->first();
    }

    /**
     * @param  array<int,string>  $signatures
     * @return Collection<int,RecommendedAction>
     */
    private function actions(ContentOpportunity $contentOpportunity, ?Opportunity $canonical, array $signatures): Collection
    {
        return RecommendedAction::query()
            ->where(function ($query) use ($contentOpportunity, $canonical, $signatures): void {
                $query->whereIn('source_signature', $signatures)
                    ->orWhere(function ($nested) use ($contentOpportunity): void {
                        $nested->where('source_type', ContentOpportunity::class)
                            ->where('source_id', (string) $contentOpportunity->id);
                    });

                if ($canonical) {
                    $query->orWhere(function ($nested) use ($canonical): void {
                        $nested->where('source_type', Opportunity::class)
                            ->where('source_id', (string) $canonical->id);
                    });
                }
            })
            ->orderBy('created_at')
            ->get()
            ->unique('id')
            ->values();
    }

    private function safeCandidateCount(?Opportunity $canonical, Collection $actions, string $sharedSignature): int
    {
        if (! $canonical) {
            return 0;
        }

        return $actions->where('source_signature', $sharedSignature)->isEmpty() ? 1 : 0;
    }

    /**
     * @return array<int,string>
     */
    private function skippedReasons(ContentOpportunity $contentOpportunity, ?Opportunity $canonical): array
    {
        return array_values(array_filter([
            $contentOpportunity->workspace_id ? null : 'missing_workspace',
            $canonical ? null : 'missing_canonical_link',
        ]));
    }

    private function isLegacyAction(RecommendedAction $action, ContentOpportunity $contentOpportunity): bool
    {
        return $action->source_type === ContentOpportunity::class
            && (string) $action->source_id === (string) $contentOpportunity->id;
    }

    private function isCanonicalAction(RecommendedAction $action, ?Opportunity $canonical): bool
    {
        return $canonical !== null
            && $action->source_type === Opportunity::class
            && (string) $action->source_id === (string) $canonical->id;
    }
}
