<?php

namespace App\Services\OpportunityIntelligence;

use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Models\CompetitorContentOpportunity;
use App\Models\OpportunitySignal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CompetitorContentOpportunitySignalPromotionService
{
    public const SOURCE_TYPE = 'competitor_content_opportunity';

    public function promote(
        CompetitorContentOpportunity $opportunity,
        bool $dryRun = true
    ): CompetitorContentOpportunitySignalPromotionResult {
        $opportunity->loadMissing(['workspace', 'site', 'competitor', 'run']);

        $dedupeHash = $this->dedupeHash($opportunity);
        $missing = $this->missingRequiredContext($opportunity);

        if ($missing !== []) {
            return new CompetitorContentOpportunitySignalPromotionResult(
                status: 'skipped',
                dedupeHash: $dedupeHash,
                reasons: $missing,
            );
        }

        $existing = $this->existingSignal($opportunity, $dedupeHash);

        if ($dryRun) {
            return new CompetitorContentOpportunitySignalPromotionResult(
                status: $existing ? 'duplicate' : 'would_create',
                dedupeHash: $dedupeHash,
                signal: $existing,
            );
        }

        $payload = $this->payload($opportunity);
        $signal = OpportunitySignal::query()->updateOrCreate(
            [
                'workspace_id' => (string) $opportunity->workspace_id,
                'dedupe_hash' => $dedupeHash,
            ],
            $payload,
        );

        return new CompetitorContentOpportunitySignalPromotionResult(
            status: $signal->wasRecentlyCreated ? 'created' : 'updated',
            dedupeHash: $dedupeHash,
            signal: $signal->refresh(),
        );
    }

    public function dedupeHash(CompetitorContentOpportunity $opportunity): string
    {
        return hash('sha256', implode('|', [
            (string) $opportunity->workspace_id,
            self::SOURCE_TYPE,
            CompetitorContentOpportunity::class,
            (string) $opportunity->getKey(),
        ]));
    }

    /**
     * @return array<int,string>
     */
    public function missingRequiredContext(CompetitorContentOpportunity $opportunity): array
    {
        $missing = [];

        if (! $opportunity->workspace_id || ! $opportunity->workspace) {
            $missing[] = 'workspace_id';
        }

        if (! $opportunity->client_site_id || ! $opportunity->site) {
            $missing[] = 'client_site_id';
        }

        if (! $opportunity->site_competitor_id || ! $opportunity->competitor) {
            $missing[] = 'site_competitor_id';
        }

        if ($this->topic($opportunity) === null) {
            $missing[] = 'topic';
        }

        return $missing;
    }

    private function existingSignal(CompetitorContentOpportunity $opportunity, string $dedupeHash): ?OpportunitySignal
    {
        return OpportunitySignal::query()
            ->where('workspace_id', (string) $opportunity->workspace_id)
            ->where('dedupe_hash', $dedupeHash)
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(CompetitorContentOpportunity $opportunity): array
    {
        $workspace = $opportunity->workspace;
        $competitor = $opportunity->competitor;
        $observedAt = $opportunity->last_seen_at ?? $opportunity->updated_at ?? $opportunity->created_at ?? now();

        return [
            'organization_id' => $opportunity->organization_id ?: $workspace?->organization_id,
            'client_site_id' => $opportunity->client_site_id,
            'content_id' => null,
            'content_cluster_id' => null,
            'campaign_id' => null,
            'source' => OpportunitySignalSource::COMPETITOR_INTELLIGENCE->value,
            'category' => $this->category($opportunity)->value,
            'topic' => $this->topic($opportunity),
            'entity' => $competitor?->name,
            'signal_strength' => $this->score($opportunity->priority_score, $opportunity->impact_score, 50),
            'confidence' => $this->score($opportunity->confidence_score, null, 75),
            'observed_at' => $observedAt,
            'metrics' => $this->metrics($opportunity),
            'evidence' => $this->evidence($opportunity),
            'metadata' => $this->metadata($opportunity, Carbon::parse($observedAt)),
        ];
    }

    private function category(CompetitorContentOpportunity $opportunity): OpportunityCategory
    {
        $type = Str::lower((string) $opportunity->type);

        return str_contains($type, 'movement')
            ? OpportunityCategory::COMPETITOR_MOVEMENT
            : OpportunityCategory::CONTENT_GAP;
    }

    private function topic(CompetitorContentOpportunity $opportunity): ?string
    {
        $topic = trim((string) ($opportunity->topic ?: $opportunity->title));

        return $topic !== '' ? $topic : null;
    }

    private function score(mixed $primary, mixed $secondary, float $fallback): float
    {
        foreach ([$primary, $secondary] as $score) {
            if (is_numeric($score)) {
                return max(0, min(100, (float) $score));
            }
        }

        return $fallback;
    }

    /**
     * @return array<string,mixed>
     */
    private function metrics(CompetitorContentOpportunity $opportunity): array
    {
        return [
            'priority_score' => $opportunity->priority_score,
            'impact_score' => $opportunity->impact_score,
            'confidence_score' => $opportunity->confidence_score,
            'effort_score' => $opportunity->effort_score,
            'legacy_dedupe_hash' => $opportunity->dedupe_hash,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function evidence(CompetitorContentOpportunity $opportunity): array
    {
        return [[
            'type' => self::SOURCE_TYPE,
            'source_model' => CompetitorContentOpportunity::class,
            'source_id' => (string) $opportunity->getKey(),
            'title' => $opportunity->title,
            'topic' => $opportunity->topic,
            'reason' => $opportunity->reason,
            'attackable_angle' => $opportunity->attackable_angle,
            'query_intent' => $opportunity->query_intent,
            'funnel_stage' => $opportunity->funnel_stage,
            'recommended_format' => $opportunity->recommended_format,
            'competitor' => [
                'id' => (string) $opportunity->site_competitor_id,
                'name' => $opportunity->competitor?->name,
                'domain' => $opportunity->competitor?->domain,
            ],
            'competitor_evidence' => $opportunity->competitor_evidence ?? [],
            'argusly_coverage' => $opportunity->argusly_coverage ?? [],
            'normalized_payload' => $opportunity->normalized_payload ?? [],
        ]];
    }

    /**
     * @return array<string,mixed>
     */
    private function metadata(CompetitorContentOpportunity $opportunity, Carbon $observedAt): array
    {
        return [
            'source_type' => self::SOURCE_TYPE,
            'source_model' => CompetitorContentOpportunity::class,
            'source_id' => (string) $opportunity->getKey(),
            'source_status' => $opportunity->status,
            'source_dedupe_hash' => $opportunity->dedupe_hash,
            'competitor_content_opportunity_id' => (string) $opportunity->getKey(),
            'site_competitor_id' => (string) $opportunity->site_competitor_id,
            'competitor_intelligence_run_id' => $opportunity->competitor_intelligence_run_id ? (string) $opportunity->competitor_intelligence_run_id : null,
            'competitor' => [
                'id' => (string) $opportunity->site_competitor_id,
                'name' => $opportunity->competitor?->name,
                'domain' => $opportunity->competitor?->domain,
            ],
            'opportunity_type' => $opportunity->type,
            'recommended_actions' => array_values(array_filter([
                $opportunity->recommended_format,
                $opportunity->attackable_angle,
            ])),
            'promoted_from_legacy' => true,
            'promotion_observed_at' => $observedAt->toIso8601String(),
        ];
    }
}
