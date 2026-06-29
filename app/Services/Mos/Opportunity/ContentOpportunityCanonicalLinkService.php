<?php

namespace App\Services\Mos\Opportunity;

use App\Enums\OpportunityCategory;
use App\Enums\OpportunityStatus;
use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Services\Mos\Opportunity\Providers\ContentOpportunityProvider;
use Illuminate\Support\Facades\DB;
use Throwable;

class ContentOpportunityCanonicalLinkService
{
    public function __construct(
        private readonly ContentOpportunityProvider $provider,
    ) {}

    public function link(ContentOpportunity $contentOpportunity, bool $apply = false): ContentOpportunityCanonicalLinkResult
    {
        $candidate = $this->provider->toCanonicalOpportunity($contentOpportunity);
        $missing = $this->missingRequiredContext($contentOpportunity, $candidate);

        if (! $candidate->canPersistCanonically() || $missing !== []) {
            return new ContentOpportunityCanonicalLinkResult(
                status: 'skipped',
                candidate: $candidate,
                reasons: array_values(array_unique(array_merge(
                    $candidate->missingFields,
                    $candidate->unsupportedReasons,
                    $missing,
                ))),
                dryRun: ! $apply,
            );
        }

        $existingByBridge = Opportunity::query()
            ->where('content_opportunity_id', $contentOpportunity->id)
            ->first();

        if ($existingByBridge) {
            return new ContentOpportunityCanonicalLinkResult(
                status: 'linked',
                opportunity: $existingByBridge,
                candidate: $candidate,
                dryRun: ! $apply,
            );
        }

        $dedupeHash = $this->dedupeHash($candidate);
        $existingByDedupe = Opportunity::query()
            ->where('workspace_id', $contentOpportunity->workspace_id)
            ->where('dedupe_hash', $dedupeHash)
            ->first();

        if ($existingByDedupe && filled($existingByDedupe->content_opportunity_id)
            && (string) $existingByDedupe->content_opportunity_id !== (string) $contentOpportunity->id) {
            return new ContentOpportunityCanonicalLinkResult(
                status: 'duplicate',
                opportunity: $existingByDedupe,
                candidate: $candidate,
                reasons: ['dedupe_hash_linked_to_another_content_opportunity'],
                dryRun: ! $apply,
            );
        }

        if (! $apply) {
            return new ContentOpportunityCanonicalLinkResult(
                status: $existingByDedupe ? 'would_link' : 'would_create',
                opportunity: $existingByDedupe,
                candidate: $candidate,
                dryRun: true,
            );
        }

        try {
            return DB::transaction(function () use ($contentOpportunity, $candidate, $existingByDedupe, $dedupeHash): ContentOpportunityCanonicalLinkResult {
                if ($existingByDedupe) {
                    $existingByDedupe->forceFill([
                        'content_opportunity_id' => (string) $contentOpportunity->id,
                        'metadata' => $this->mergedMetadata($existingByDedupe->metadata ?? [], $contentOpportunity, $candidate),
                    ])->save();

                    return new ContentOpportunityCanonicalLinkResult(
                        status: 'linked',
                        opportunity: $existingByDedupe->refresh(),
                        candidate: $candidate,
                        dryRun: false,
                    );
                }

                $opportunity = Opportunity::query()->create($this->payload($contentOpportunity, $candidate, $dedupeHash));

                return new ContentOpportunityCanonicalLinkResult(
                    status: 'created',
                    opportunity: $opportunity,
                    candidate: $candidate,
                    dryRun: false,
                );
            });
        } catch (Throwable $exception) {
            return new ContentOpportunityCanonicalLinkResult(
                status: 'failed',
                candidate: $candidate,
                reasons: [$exception->getMessage()],
                dryRun: false,
            );
        }
    }

    public function dedupeHash(CanonicalOpportunityCandidate $candidate): string
    {
        return hash('sha256', implode('|', [
            $candidate->context['workspace_id'] ?? '',
            $candidate->source,
            $candidate->sourceModel ?? '',
            $candidate->dedupeKey ?? '',
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function missingRequiredContext(ContentOpportunity $opportunity, CanonicalOpportunityCandidate $candidate): array
    {
        $reasons = [];

        if (blank($opportunity->workspace_id) || blank($candidate->context['workspace_id'] ?? null)) {
            $reasons[] = 'workspace_id';
        }

        if (blank($candidate->title)) {
            $reasons[] = 'title';
        }

        if (blank($candidate->type)) {
            $reasons[] = 'type';
        }

        if (blank($candidate->dedupeKey)) {
            $reasons[] = 'dedupe_key';
        }

        if ($candidate->evidence === [] && blank($opportunity->reasoning) && blank($opportunity->why_this_matters)) {
            $reasons[] = 'evidence_or_reasoning';
        }

        return $reasons;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ContentOpportunity $opportunity, CanonicalOpportunityCandidate $candidate, string $dedupeHash): array
    {
        return [
            'organization_id' => $candidate->context['organization_id'] ?? $opportunity->organization_id,
            'workspace_id' => (string) $candidate->context['workspace_id'],
            'client_site_id' => $candidate->context['client_site_id'] ?? null,
            'content_opportunity_id' => (string) $opportunity->id,
            'category' => $this->category($candidate->type),
            'status' => $this->status($candidate->lifecycleStatus),
            'title' => (string) $candidate->title,
            'topic' => $this->topic($opportunity, $candidate),
            'summary' => $candidate->description,
            'priority_score' => $candidate->priority ?? 0,
            'confidence_score' => $candidate->confidence ?? 0,
            'impact_score' => $this->impactScore($opportunity, $candidate),
            'urgency_score' => (float) ($opportunity->urgency_score ?? 0),
            'effort_score' => $candidate->effort ?? 0,
            'score_breakdown' => [
                'priority' => $candidate->priority,
                'confidence' => $candidate->confidence,
                'impact' => $candidate->impact,
                'urgency' => $opportunity->urgency_score,
                'business_value' => $candidate->businessValue,
                'legacy_expected_impact' => $opportunity->expected_impact,
            ],
            'recommended_actions' => $candidate->recommendedActions,
            'evidence' => $this->evidence($opportunity, $candidate),
            'source_signal_summary' => [
                'source' => $candidate->source,
                'source_model' => $candidate->sourceModel,
                'source_id' => (string) $candidate->sourceId,
                'content_opportunity_id' => (string) $opportunity->id,
                'source_signals' => $opportunity->source_signals ?? [],
            ],
            'metadata' => $this->mergedMetadata([], $opportunity, $candidate),
            'dedupe_hash' => $dedupeHash,
            'first_seen_at' => $opportunity->first_seen_at,
            'last_seen_at' => $opportunity->last_seen_at,
            'planned_at' => $opportunity->status === ContentOpportunity::STATUS_PLANNED ? now() : null,
        ];
    }

    private function category(?string $type): OpportunityCategory
    {
        return match ($type) {
            OpportunityCategory::REFRESH_OPPORTUNITY->value, 'refresh_opportunity' => OpportunityCategory::REFRESH_OPPORTUNITY,
            OpportunityCategory::AI_VISIBILITY_OPPORTUNITY->value, 'answer_block_opportunity', 'faq_opportunity' => OpportunityCategory::AI_VISIBILITY_OPPORTUNITY,
            default => OpportunityCategory::CONTENT_GAP,
        };
    }

    private function status(?string $status): OpportunityStatus
    {
        return match ($status) {
            ContentOpportunity::STATUS_PLANNED => OpportunityStatus::PLANNED,
            ContentOpportunity::STATUS_DISMISSED => OpportunityStatus::DISMISSED,
            ContentOpportunity::STATUS_ARCHIVED => OpportunityStatus::ARCHIVED,
            default => OpportunityStatus::OPEN,
        };
    }

    private function impactScore(ContentOpportunity $opportunity, CanonicalOpportunityCandidate $candidate): float
    {
        if ($candidate->impact !== null) {
            return $candidate->impact;
        }

        return match ((string) $opportunity->expected_impact) {
            'strategic' => 90.0,
            'high' => 80.0,
            'medium' => 60.0,
            'low' => 30.0,
            default => is_numeric($opportunity->expected_impact) ? (float) $opportunity->expected_impact : 0.0,
        };
    }

    private function topic(ContentOpportunity $opportunity, CanonicalOpportunityCandidate $candidate): ?string
    {
        return trim((string) data_get($opportunity->normalized_payload, 'candidate.topic'))
            ?: trim((string) $candidate->title)
            ?: null;
    }

    /**
     * @return array<int, mixed>
     */
    private function evidence(ContentOpportunity $opportunity, CanonicalOpportunityCandidate $candidate): array
    {
        return array_values(array_filter(array_merge($candidate->evidence, [[
            'type' => 'legacy_content_opportunity',
            'source_model' => ContentOpportunity::class,
            'source_id' => (string) $opportunity->id,
            'reasoning' => $opportunity->reasoning,
            'why_this_matters' => $opportunity->why_this_matters,
            'why_now' => $opportunity->why_now,
            'competitor_pressure' => $opportunity->competitor_pressure,
            'ai_visibility_opportunity' => $opportunity->ai_visibility_opportunity,
            'query_intent_payload' => $opportunity->query_intent_payload,
            'normalized_payload' => $opportunity->normalized_payload,
            'related_references' => $candidate->relatedReferences,
        ]])));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function mergedMetadata(array $metadata, ContentOpportunity $opportunity, CanonicalOpportunityCandidate $candidate): array
    {
        return array_replace_recursive($metadata, [
            'canonical_link_phase' => '2C',
            'source' => $candidate->source,
            'source_model' => $candidate->sourceModel,
            'source_id' => (string) $candidate->sourceId,
            'content_opportunity_id' => (string) $opportunity->id,
            'legacy_status' => $opportunity->status,
            'legacy_type' => $opportunity->type,
            'legacy_expected_impact' => $opportunity->expected_impact,
            'dedupe_key' => $candidate->dedupeKey,
            'context' => $candidate->context,
        ]);
    }
}
