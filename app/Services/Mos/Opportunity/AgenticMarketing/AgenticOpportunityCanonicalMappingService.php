<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Enums\AgenticMarketingOpportunityType;
use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Services\AgenticMarketing\OpportunityDetection\DetectedOpportunity;
use BackedEnum;
use Illuminate\Support\Str;

class AgenticOpportunityCanonicalMappingService
{
    private const DEDUPE_VERSION = 'agentic-detector-output:v1';

    /**
     * @return array<string,string>
     */
    public function detectorClassifications(): array
    {
        return [
            'refresh_lifecycle' => AgenticDetectorClassification::SIGNAL_ONLY->value,
            'internal_links' => AgenticDetectorClassification::SIGNAL_ONLY->value,
            'localization_gaps' => AgenticDetectorClassification::SIGNAL_ONLY->value,
            'structured_answer_gaps' => AgenticDetectorClassification::SIGNAL_ONLY->value,
            'seo_indexability' => AgenticDetectorClassification::SIGNAL_ONLY->value,
            'content_network_gaps' => AgenticDetectorClassification::SIGNAL_AND_OPPORTUNITY->value,
            'ai_visibility_gaps' => AgenticDetectorClassification::SIGNAL_ONLY->value,
            'llm_tracking_ai_visibility' => AgenticDetectorClassification::SIGNAL_ONLY->value,
            'campaign_cluster_action_materializer' => AgenticDetectorClassification::SIGNAL_AND_OPPORTUNITY->value,
        ];
    }

    /**
     * @return array<int,string>
     */
    public function knownDetectorKeys(): array
    {
        return array_keys($this->detectorClassifications());
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function map(
        DetectedOpportunity $detected,
        AgenticMarketingObjective $objective,
        ?string $detectorKey = null,
        ?AgenticMarketingOpportunity $existingOpportunity = null,
        array $context = [],
    ): AgenticCanonicalMappingResult {
        $payload = $detected->payload;
        $detectorKey = $this->normalizeDetectorKey($detectorKey ?: data_get($payload, 'detector', 'unknown'));
        $classification = $this->classificationFor($detectorKey);
        $opportunityType = $detected->type->value;
        $workspaceId = $this->stringValue($context['workspace_id'] ?? data_get($payload, 'workspace_id') ?? $objective->workspace_id);
        $clientSiteId = $this->stringValue($context['client_site_id'] ?? data_get($payload, 'client_site_id') ?? $objective->client_site_id);
        $contentId = $this->stringValue($context['content_id'] ?? $detected->contentId ?? data_get($payload, 'content_id') ?? $existingOpportunity?->content_id);
        $objectiveId = $this->stringValue($objective->id);
        $organizationId = $objective->organization_id !== null ? (int) $objective->organization_id : null;
        $topic = $this->topic($detected);
        $category = $this->categoryFor($detected->type);
        $dedupeKey = $this->dedupeKey($detected, $objective, $detectorKey, $workspaceId, $clientSiteId, $contentId);
        $missingContext = $this->missingContext(
            $classification,
            $workspaceId,
            $objectiveId,
            $detectorKey,
            $opportunityType,
            $topic,
            $payload,
            $dedupeKey,
        );
        $blockedReasons = $this->blockedReasons($classification, $detectorKey, $missingContext, $payload);
        $requiredContextPresent = $missingContext === [] && $blockedReasons === [];
        $signalPreview = null;
        $opportunityPreview = null;

        if ($classification->canEmitSignal()) {
            $signalPreview = $this->signalPreview(
                $detected,
                $objective,
                $detectorKey,
                $workspaceId,
                $clientSiteId,
                $contentId,
                $objectiveId,
                $organizationId,
                $topic,
                $category,
                $dedupeKey,
                $existingOpportunity,
            );
        }

        if ($classification->canEmitOpportunity()) {
            $opportunityPreview = $this->opportunityPreview(
                $detected,
                $objective,
                $detectorKey,
                $workspaceId,
                $clientSiteId,
                $contentId,
                $objectiveId,
                $organizationId,
                $topic,
                $category,
                $dedupeKey,
                $existingOpportunity,
            );
        }

        return new AgenticCanonicalMappingResult(
            detectorKey: $detectorKey,
            classification: $classification,
            canEmitSignal: $classification->canEmitSignal() && $requiredContextPresent,
            canEmitCanonicalOpportunityCandidate: $classification->canEmitOpportunity() && $requiredContextPresent,
            executionOnly: $classification->isExecutionOnly(),
            requiredContextPresent: $requiredContextPresent,
            missingContext: $missingContext,
            signalPreview: $signalPreview,
            opportunityPreview: $opportunityPreview,
            dedupeKey: $dedupeKey,
            blockedReasons: $blockedReasons,
            riskLevel: $this->riskLevel($classification, $blockedReasons),
        );
    }

    public function mapExisting(AgenticMarketingOpportunity $opportunity, ?string $detectorKey = null): AgenticCanonicalMappingResult
    {
        $opportunity->loadMissing('objective');
        $type = AgenticMarketingOpportunityType::tryFrom((string) $opportunity->type);

        if (! $opportunity->objective || ! $type) {
            $detectorKey = $this->normalizeDetectorKey($detectorKey ?: data_get($opportunity->payload, 'detector', 'unknown'));
            $dedupeKey = $this->fallbackDedupeKey($opportunity, $detectorKey);

            return new AgenticCanonicalMappingResult(
                detectorKey: $detectorKey,
                classification: AgenticDetectorClassification::BLOCKED,
                canEmitSignal: false,
                canEmitCanonicalOpportunityCandidate: false,
                executionOnly: false,
                requiredContextPresent: false,
                missingContext: array_values(array_filter([
                    ! $opportunity->objective ? 'objective' : null,
                    ! $type ? 'opportunity_type' : null,
                ])),
                signalPreview: null,
                opportunityPreview: null,
                dedupeKey: $dedupeKey,
                blockedReasons: ['existing_agentic_opportunity_missing_required_mapping_context'],
                riskLevel: 'high',
            );
        }

        $detected = new DetectedOpportunity(
            title: (string) $opportunity->title,
            type: $type,
            priorityScore: (int) $opportunity->priority_score,
            payload: (array) ($opportunity->payload ?? []),
            contentId: $opportunity->content_id ? (string) $opportunity->content_id : null,
        );

        return $this->map(
            detected: $detected,
            objective: $opportunity->objective,
            detectorKey: $detectorKey,
            existingOpportunity: $opportunity,
        );
    }

    private function classificationFor(string $detectorKey): AgenticDetectorClassification
    {
        return AgenticDetectorClassification::tryFrom($this->detectorClassifications()[$detectorKey] ?? '')
            ?? AgenticDetectorClassification::BLOCKED;
    }

    private function normalizeDetectorKey(string $detectorKey): string
    {
        $detectorKey = class_basename($detectorKey);
        $detectorKey = Str::of($detectorKey)
            ->replace('OpportunityDetector', '')
            ->snake()
            ->trim('_')
            ->toString();

        return $detectorKey !== '' ? $detectorKey : 'unknown';
    }

    private function signalPreview(
        DetectedOpportunity $detected,
        AgenticMarketingObjective $objective,
        string $detectorKey,
        ?string $workspaceId,
        ?string $clientSiteId,
        ?string $contentId,
        ?string $objectiveId,
        ?int $organizationId,
        ?string $topic,
        string $category,
        string $dedupeKey,
        ?AgenticMarketingOpportunity $existingOpportunity,
    ): AgenticCanonicalSignalPreview {
        $scores = $this->scores($detected);

        return new AgenticCanonicalSignalPreview(
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            clientSiteId: $clientSiteId,
            contentId: $contentId,
            objectiveId: $objectiveId,
            source: $this->signalSourceFor($detectorKey),
            detectorKey: $detectorKey,
            opportunityType: $detected->type->value,
            topic: $topic,
            category: $category,
            signalStrength: $scores['signal_strength'],
            confidence: $scores['confidence'],
            priority: $scores['priority'],
            metrics: $this->metrics($detected, $detectorKey),
            evidence: $this->evidence($detected, $detectorKey, $existingOpportunity),
            metadata: $this->metadata($detected, $objective, $detectorKey, $existingOpportunity),
            sourceModel: $existingOpportunity ? AgenticMarketingOpportunity::class : null,
            sourceId: $existingOpportunity?->id ? (string) $existingOpportunity->id : null,
            dedupeKey: $dedupeKey,
        );
    }

    private function opportunityPreview(
        DetectedOpportunity $detected,
        AgenticMarketingObjective $objective,
        string $detectorKey,
        ?string $workspaceId,
        ?string $clientSiteId,
        ?string $contentId,
        ?string $objectiveId,
        ?int $organizationId,
        ?string $topic,
        string $category,
        string $dedupeKey,
        ?AgenticMarketingOpportunity $existingOpportunity,
    ): AgenticCanonicalOpportunityPreview {
        $scores = $this->scores($detected);

        return new AgenticCanonicalOpportunityPreview(
            title: $detected->title,
            summary: $this->summary($detected),
            category: $category,
            type: $detected->type->value,
            organizationId: $organizationId,
            workspaceId: $workspaceId,
            clientSiteId: $clientSiteId,
            contentId: $contentId,
            objectiveId: $objectiveId,
            priority: $scores['priority'],
            confidence: $scores['confidence'],
            impact: $scores['impact'],
            effort: $scores['effort'],
            businessValue: $scores['business_value'],
            recommendedActions: $this->recommendedActions($detected),
            evidence: $this->evidence($detected, $detectorKey, $existingOpportunity),
            sourceSignalSummary: [
                'source' => 'agentic_marketing_detector',
                'detector_key' => $detectorKey,
                'objective_id' => $objectiveId,
                'agentic_marketing_opportunity_id' => $existingOpportunity?->id ? (string) $existingOpportunity->id : null,
                'opportunity_type' => $detected->type->value,
                'topic' => $topic,
            ],
            metadata: $this->metadata($detected, $objective, $detectorKey, $existingOpportunity),
            dedupeKey: $dedupeKey,
        );
    }

    /**
     * @return array{priority:float,confidence:float,impact:float,effort:float,business_value:?float,signal_strength:float}
     */
    private function scores(DetectedOpportunity $detected): array
    {
        $explanation = (array) data_get($detected->payload, 'score_explanation', []);
        $priority = (float) max(1, min(100, $detected->priorityScore));
        $confidence = $this->numeric(data_get($explanation, 'confidence_score') ?? data_get($detected->payload, 'confidence_score'), 50.0);
        $impact = $this->numeric(data_get($explanation, 'impact_score') ?? data_get($detected->payload, 'impact_score'), $priority);
        $effort = $this->numeric(data_get($explanation, 'effort_score') ?? data_get($detected->payload, 'effort_score'), 50.0);
        $businessValue = $this->nullableNumeric(data_get($detected->payload, 'business_value_score'));

        return [
            'priority' => round($priority, 2),
            'confidence' => round(max(0, min(100, $confidence)), 2),
            'impact' => round(max(0, min(100, $impact)), 2),
            'effort' => round(max(0, min(100, $effort)), 2),
            'business_value' => $businessValue !== null ? round(max(0, min(100, $businessValue)), 2) : null,
            'signal_strength' => round(max(0, min(100, $impact)), 2),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function metrics(DetectedOpportunity $detected, string $detectorKey): array
    {
        $scores = $this->scores($detected);

        return array_filter([
            'priority_score' => $scores['priority'],
            'confidence_score' => $scores['confidence'],
            'impact_score' => $scores['impact'],
            'effort_score' => $scores['effort'],
            'business_value_score' => $scores['business_value'],
            'risk_score' => $this->nullableNumeric(data_get($detected->payload, 'score_explanation.risk_score')),
            'detector_key' => $detectorKey,
            'stable_payload_fingerprint' => $this->stablePayloadFingerprint($detected->payload),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string,mixed>
     */
    private function evidence(DetectedOpportunity $detected, string $detectorKey, ?AgenticMarketingOpportunity $existingOpportunity): array
    {
        return array_filter([
            'source' => 'agentic_marketing_detector',
            'detector_key' => $detectorKey,
            'title' => $detected->title,
            'opportunity_type' => $detected->type->value,
            'signals' => (array) data_get($detected->payload, 'signals', []),
            'references' => (array) data_get($detected->payload, 'references', []),
            'reasoning' => data_get($detected->payload, 'reasoning') ?: data_get($detected->payload, 'reason'),
            'score_explanation' => (array) data_get($detected->payload, 'score_explanation', []),
            'legacy_agentic_marketing_opportunity' => $existingOpportunity ? [
                'source_model' => AgenticMarketingOpportunity::class,
                'source_id' => (string) $existingOpportunity->id,
                'status' => (string) $existingOpportunity->status,
                'dedupe_hash' => (string) $existingOpportunity->dedupe_hash,
            ] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<string,mixed>
     */
    private function metadata(
        DetectedOpportunity $detected,
        AgenticMarketingObjective $objective,
        string $detectorKey,
        ?AgenticMarketingOpportunity $existingOpportunity,
    ): array {
        return array_filter([
            'mapping_phase' => '3B',
            'read_only' => true,
            'source_type' => 'agentic_marketing_detector_output',
            'detector_key' => $detectorKey,
            'objective_id' => $this->stringValue($objective->id),
            'objective_name' => $objective->name,
            'objective_status' => $objective->status,
            'agentic_marketing_opportunity_id' => $existingOpportunity?->id ? (string) $existingOpportunity->id : null,
            'payload_detector' => data_get($detected->payload, 'detector'),
            'payload_signal_type' => data_get($detected->payload, 'signal_type'),
            'payload_dedupe_key' => data_get($detected->payload, 'dedupe_key'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<int,string>
     */
    private function recommendedActions(DetectedOpportunity $detected): array
    {
        return collect([
            ...(array) data_get($detected->payload, 'recommended_actions', []),
            data_get($detected->payload, 'recommendation'),
            data_get($detected->payload, 'suggested_cta'),
            data_get($detected->payload, 'suggested_schema'),
        ])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function summary(DetectedOpportunity $detected): ?string
    {
        return $this->stringValue(
            data_get($detected->payload, 'summary')
            ?: data_get($detected->payload, 'score_explanation.summary')
            ?: data_get($detected->payload, 'reasoning')
            ?: data_get($detected->payload, 'reason')
        );
    }

    private function topic(DetectedOpportunity $detected): ?string
    {
        return $this->stringValue(
            data_get($detected->payload, 'topic')
            ?: data_get($detected->payload, 'cluster_topic')
            ?: data_get($detected->payload, 'primary_keyword')
            ?: data_get($detected->payload, 'signals.topic_keyword')
            ?: data_get($detected->payload, 'signals.query_text')
            ?: data_get($detected->payload, 'signals.cluster_name')
            ?: data_get($detected->payload, 'signals.query_set_name')
            ?: $detected->title
        );
    }

    private function categoryFor(AgenticMarketingOpportunityType $type): string
    {
        return match ($type) {
            AgenticMarketingOpportunityType::Refresh => OpportunityCategory::REFRESH_OPPORTUNITY->value,
            AgenticMarketingOpportunityType::AiVisibility,
            AgenticMarketingOpportunityType::AnswerCoverage => OpportunityCategory::AI_VISIBILITY_OPPORTUNITY->value,
            AgenticMarketingOpportunityType::InternalLinks,
            AgenticMarketingOpportunityType::LocaleExpansion,
            AgenticMarketingOpportunityType::SeoIndexability,
            AgenticMarketingOpportunityType::NewArticle,
            AgenticMarketingOpportunityType::ContentNetwork,
            AgenticMarketingOpportunityType::Metadata,
            AgenticMarketingOpportunityType::Schema => OpportunityCategory::CONTENT_GAP->value,
        };
    }

    private function signalSourceFor(string $detectorKey): string
    {
        return match ($detectorKey) {
            'refresh_lifecycle' => OpportunitySignalSource::CONTENT_DECAY->value,
            'internal_links' => OpportunitySignalSource::INTERNAL_ANALYTICS->value,
            'content_network_gaps',
            'campaign_cluster_action_materializer' => OpportunitySignalSource::CONTENT_CLUSTER->value,
            'ai_visibility_gaps',
            'llm_tracking_ai_visibility' => OpportunitySignalSource::AI_CITATION_TRACKING->value,
            default => OpportunitySignalSource::SIGNAL_INTELLIGENCE->value,
        };
    }

    /**
     * @return array<int,string>
     */
    private function missingContext(
        AgenticDetectorClassification $classification,
        ?string $workspaceId,
        ?string $objectiveId,
        string $detectorKey,
        ?string $opportunityType,
        ?string $topic,
        array $payload,
        string $dedupeKey,
    ): array {
        $missing = array_values(array_filter([
            $workspaceId ? null : 'workspace_id',
            $objectiveId ? null : 'objective_id',
            $detectorKey !== 'unknown' ? null : 'detector_key',
            $opportunityType ? null : 'opportunity_type',
            $topic ? null : 'topic_or_title',
            $dedupeKey ? null : 'dedupe_key',
        ]));

        if ($classification === AgenticDetectorClassification::SIGNAL_AND_OPPORTUNITY) {
            if ($detectorKey === 'campaign_cluster_action_materializer' && ! data_get($payload, 'signals.campaign_cluster_id')) {
                $missing[] = 'campaign_cluster_id';
            }

            if ($detectorKey === 'content_network_gaps' && ! data_get($payload, 'signals.cluster_id')) {
                $missing[] = 'content_cluster_id';
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * @param  array<int,string>  $missingContext
     * @return array<int,string>
     */
    private function blockedReasons(
        AgenticDetectorClassification $classification,
        string $detectorKey,
        array $missingContext,
        array $payload,
    ): array {
        $blocked = [];

        if ($classification === AgenticDetectorClassification::BLOCKED) {
            $blocked[] = 'detector_classification_missing_or_blocked';
        }

        foreach ($missingContext as $field) {
            $blocked[] = 'missing_'.$field;
        }

        if ($detectorKey === 'campaign_cluster_action_materializer' && ! data_get($payload, 'dedupe_key')) {
            $blocked[] = 'campaign_cluster_materialization_requires_stable_payload_dedupe_key';
        }

        return array_values(array_unique($blocked));
    }

    private function riskLevel(AgenticDetectorClassification $classification, array $blockedReasons): string
    {
        if ($blockedReasons !== [] || $classification === AgenticDetectorClassification::BLOCKED) {
            return 'high';
        }

        return $classification === AgenticDetectorClassification::SIGNAL_AND_OPPORTUNITY ? 'high' : 'medium';
    }

    private function dedupeKey(
        DetectedOpportunity $detected,
        AgenticMarketingObjective $objective,
        string $detectorKey,
        ?string $workspaceId,
        ?string $clientSiteId,
        ?string $contentId,
    ): string {
        $payload = $detected->payload;
        $parts = [
            'version' => self::DEDUPE_VERSION,
            'workspace_id' => $workspaceId,
            'objective_id' => $this->stringValue($objective->id),
            'detector_key' => $detectorKey,
            'opportunity_type' => $detected->type->value,
            'content_id' => $contentId,
            'client_site_id' => $clientSiteId,
            'locale' => $this->stringValue(data_get($payload, 'locale') ?: data_get($payload, 'signals.locale') ?: data_get($payload, 'signals.source_locale') ?: $objective->locale),
            'topic' => $this->normalizeDedupeText($this->topic($detected) ?: $detected->title),
            'payload_fingerprint' => $this->stablePayloadFingerprint($payload),
        ];

        return hash('sha256', $this->stableJson($parts));
    }

    private function fallbackDedupeKey(AgenticMarketingOpportunity $opportunity, string $detectorKey): string
    {
        return hash('sha256', $this->stableJson([
            'version' => self::DEDUPE_VERSION,
            'legacy_agentic_marketing_opportunity_id' => $opportunity->id,
            'detector_key' => $detectorKey,
            'dedupe_hash' => $opportunity->dedupe_hash,
        ]));
    }

    private function stablePayloadFingerprint(array $payload): string
    {
        $stable = [
            'detector' => data_get($payload, 'detector'),
            'signal_type' => data_get($payload, 'signal_type'),
            'dedupe_key' => data_get($payload, 'dedupe_key'),
            'content_id' => data_get($payload, 'content_id'),
            'references' => data_get($payload, 'references', []),
            'signals' => $this->stableSignalIdentity((array) data_get($payload, 'signals', [])),
        ];

        return hash('sha256', $this->stableJson($this->stripVolatileKeys($stable)));
    }

    /**
     * @return array<string,mixed>
     */
    private function stableSignalIdentity(array $signals): array
    {
        return collect($signals)
            ->filter(function (mixed $value, string $key): bool {
                return str_ends_with($key, '_id')
                    || str_ends_with($key, '_ids')
                    || in_array($key, [
                        'family_key',
                        'gap_type',
                        'locale',
                        'source_locale',
                        'missing_locales',
                        'existing_locales',
                        'llm_tracking_signal',
                        'query_text',
                        'topic_keyword',
                        'cluster_name',
                        'asset_kind',
                        'materialized_action_type',
                    ], true);
            })
            ->sortKeys()
            ->all();
    }

    private function stripVolatileKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value instanceof BackedEnum ? $value->value : $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            $keyString = is_string($key) ? $key : (string) $key;
            if (
                str_ends_with($keyString, '_at')
                || in_array($keyString, ['created_at', 'updated_at', 'captured_at', 'generated_at', 'materialized_at', 'detected_at'], true)
            ) {
                continue;
            }

            $result[$key] = $this->stripVolatileKeys($item);
        }

        ksort($result);

        return $result;
    }

    private function stableJson(array $value): string
    {
        $normalized = $this->stripVolatileKeys($value);

        return (string) json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function normalizeDedupeText(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/i', ' ')
            ->squish()
            ->toString();
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function numeric(mixed $value, float $fallback): float
    {
        return is_numeric($value) ? (float) $value : $fallback;
    }

    private function nullableNumeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
