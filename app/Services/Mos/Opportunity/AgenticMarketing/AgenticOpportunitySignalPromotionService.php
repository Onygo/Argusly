<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Models\AgenticMarketingOpportunity;
use App\Models\OpportunitySignal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class AgenticOpportunitySignalPromotionService
{
    private const FEATURE_FLAG = 'features.mos_agentic_marketing_opportunity_signal_promotion';

    private const PROMOTION_VERSION = 'agentic-opportunity-signal-promotion:v1';

    public function __construct(
        private readonly AgenticOpportunityCanonicalMappingService $mapper,
    ) {}

    /**
     * @param  array<string,mixed>  $operatorContext
     */
    public function promote(
        AgenticMarketingOpportunity $legacy,
        bool $apply = false,
        array $operatorContext = [],
    ): AgenticOpportunitySignalPromotionResult {
        $legacy->loadMissing('objective');

        $mapping = $this->mapper->mapExisting($legacy);
        $dryRun = ! $apply;

        if ($apply && ! $this->featureEnabled()) {
            return $this->result('blocked', $mapping, null, ['feature_flag_disabled'], false, $operatorContext);
        }

        if (! $mapping->canEmitSignal) {
            $status = $mapping->missingContext !== [] ? 'missing_context' : 'blocked';
            $reasons = array_values(array_unique([
                ...$mapping->blockedReasons,
                ...array_map(static fn (string $field): string => 'missing_'.$field, $mapping->missingContext),
            ]));

            return $this->result($status, $mapping, null, $reasons, $dryRun, $operatorContext);
        }

        $preview = $mapping->signalPreview;
        if (! $preview) {
            return $this->result('blocked', $mapping, null, ['missing_signal_preview'], $dryRun, $operatorContext);
        }

        $missing = $this->missingRequiredSignalFields($preview);
        if ($missing !== []) {
            return $this->result('missing_context', $mapping, null, $missing, $dryRun, $operatorContext);
        }

        $existing = $this->existingSignal($preview);
        $payload = $this->payload($legacy, $preview, $existing, $operatorContext);

        if ($existing) {
            if ($this->payloadIsCurrent($existing, $payload)) {
                return $this->result('already_current', $mapping, $existing, [], $dryRun, $operatorContext);
            }

            if (! $apply) {
                return $this->result('would_update', $mapping, $existing, [], true, $operatorContext);
            }

            try {
                return DB::transaction(function () use ($existing, $payload, $mapping, $operatorContext): AgenticOpportunitySignalPromotionResult {
                    $existing->forceFill($payload)->save();

                    return $this->result('updated', $mapping, $existing->refresh(), [], false, $operatorContext);
                });
            } catch (Throwable $exception) {
                return $this->result('failed', $mapping, $existing, [$exception->getMessage()], false, $operatorContext);
            }
        }

        if (! $apply) {
            return $this->result('would_create', $mapping, null, [], true, $operatorContext);
        }

        try {
            return DB::transaction(function () use ($payload, $mapping, $operatorContext): AgenticOpportunitySignalPromotionResult {
                $signal = OpportunitySignal::query()->create($payload);

                return $this->result('created', $mapping, $signal->refresh(), [], false, $operatorContext);
            });
        } catch (Throwable $exception) {
            return $this->result('failed', $mapping, null, [$exception->getMessage()], false, $operatorContext);
        }
    }

    private function featureEnabled(): bool
    {
        return (bool) config(self::FEATURE_FLAG, false);
    }

    private function existingSignal(AgenticCanonicalSignalPreview $preview): ?OpportunitySignal
    {
        return OpportunitySignal::query()
            ->where('workspace_id', (string) $preview->workspaceId)
            ->where('dedupe_hash', $preview->dedupeKey)
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<int,string>
     */
    private function missingRequiredSignalFields(AgenticCanonicalSignalPreview $preview): array
    {
        return array_values(array_filter([
            blank($preview->workspaceId) ? 'missing_workspace_id' : null,
            blank($preview->dedupeKey) ? 'missing_source_scoped_dedupe_key' : null,
            blank($preview->source) ? 'missing_source' : null,
            blank($preview->category) ? 'missing_category' : null,
            blank($preview->topic) ? 'missing_topic' : null,
            $preview->evidence === [] ? 'missing_evidence' : null,
        ]));
    }

    /**
     * @param  array<string,mixed>  $operatorContext
     * @return array<string,mixed>
     */
    private function payload(
        AgenticMarketingOpportunity $legacy,
        AgenticCanonicalSignalPreview $preview,
        ?OpportunitySignal $existing,
        array $operatorContext,
    ): array {
        $observedAt = $legacy->updated_at ?? $legacy->created_at ?? now();

        return [
            'organization_id' => $preview->organizationId,
            'workspace_id' => (string) $preview->workspaceId,
            'client_site_id' => $preview->clientSiteId,
            'content_id' => $preview->contentId,
            'content_cluster_id' => null,
            'campaign_id' => null,
            'source' => OpportunitySignalSource::tryFrom($preview->source) ?? OpportunitySignalSource::SIGNAL_INTELLIGENCE,
            'category' => OpportunityCategory::tryFrom((string) $preview->category) ?? OpportunityCategory::CONTENT_GAP,
            'topic' => $preview->topic,
            'entity' => data_get($legacy->payload, 'entity') ?: data_get($legacy->payload, 'signals.entity'),
            'signal_strength' => $preview->signalStrength,
            'confidence' => $preview->confidence,
            'observed_at' => $observedAt,
            'metrics' => $preview->metrics,
            'evidence' => $this->evidence($legacy, $preview),
            'metadata' => $this->metadata($legacy, $preview, $existing, $operatorContext),
            'dedupe_hash' => $preview->dedupeKey,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidence(AgenticMarketingOpportunity $legacy, AgenticCanonicalSignalPreview $preview): array
    {
        return array_replace_recursive($preview->evidence, [
            'legacy_agentic_marketing_opportunity' => [
                'source_model' => AgenticMarketingOpportunity::class,
                'source_id' => (string) $legacy->id,
                'objective_id' => (string) $legacy->objective_id,
                'status' => (string) $legacy->status,
                'type' => (string) $legacy->type,
                'dedupe_hash' => (string) $legacy->dedupe_hash,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $operatorContext
     * @return array<string,mixed>
     */
    private function metadata(
        AgenticMarketingOpportunity $legacy,
        AgenticCanonicalSignalPreview $preview,
        ?OpportunitySignal $existing,
        array $operatorContext,
    ): array {
        $existingMetadata = (array) ($existing?->metadata ?? []);
        $existingPromotion = (array) data_get($existingMetadata, 'promotion', []);
        $promotedAt = data_get($existingPromotion, 'promoted_at')
            ?: ($legacy->updated_at ?? $legacy->created_at ?? now())->toIso8601String();
        $promotedBy = data_get($existingPromotion, 'promoted_by')
            ?: data_get($operatorContext, 'promoted_by')
            ?: data_get($operatorContext, 'actor_id')
            ?: data_get($operatorContext, 'user_id');

        return array_replace_recursive($preview->metadata, [
            'source_model' => AgenticMarketingOpportunity::class,
            'source_id' => (string) $legacy->id,
            'legacy_agentic_marketing_opportunity_id' => (string) $legacy->id,
            'objective_id' => (string) $legacy->objective_id,
            'detector_key' => $preview->detectorKey,
            'agentic_type' => (string) $legacy->type,
            'agentic_status' => (string) $legacy->status,
            'client_site_id' => $preview->clientSiteId,
            'content_id' => $preview->contentId,
            'source_scoped_dedupe_key' => $preview->dedupeKey,
            'phase_3b_signal_preview_metadata' => $preview->metadata,
            'promotion' => array_filter([
                'version' => self::PROMOTION_VERSION,
                'phase' => '3E',
                'legacy_agentic_marketing_opportunity_id' => (string) $legacy->id,
                'objective_id' => (string) $legacy->objective_id,
                'detector_key' => $preview->detectorKey,
                'source_scoped_dedupe_key' => $preview->dedupeKey,
                'promoted_at' => $promotedAt,
                'promoted_by' => $promotedBy,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function payloadIsCurrent(OpportunitySignal $signal, array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if ($this->normalize($signal->{$key}) !== $this->normalize($value)) {
                return false;
            }
        }

        return true;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof OpportunitySignalSource || $value instanceof OpportunityCategory) {
            return $value->value;
        }

        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        if (is_float($value) || is_int($value)) {
            return round((float) $value, 4);
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalize($item);
            }

            ksort($normalized);

            return $normalized;
        }

        if (is_scalar($value) || $value === null) {
            return $value === null ? null : (string) $value;
        }

        return $value;
    }

    /**
     * @param  array<int,string>  $reasons
     * @param  array<string,mixed>  $operatorContext
     */
    private function result(
        string $status,
        AgenticCanonicalMappingResult $mapping,
        ?OpportunitySignal $signal,
        array $reasons,
        bool $dryRun,
        array $operatorContext,
    ): AgenticOpportunitySignalPromotionResult {
        return new AgenticOpportunitySignalPromotionResult(
            status: $status,
            mappingResult: $mapping,
            signal: $signal,
            reasons: array_values(array_unique($reasons)),
            dryRun: $dryRun,
            operatorContext: $operatorContext,
        );
    }
}
