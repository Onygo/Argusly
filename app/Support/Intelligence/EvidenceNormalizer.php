<?php

namespace App\Support\Intelligence;

use App\Models\Connectors\ConnectorSyncRun;
use App\Models\MarketingObservation;
use App\Models\PageIntelligenceReport;
use App\Models\PageSnapshot;
use App\Models\ScheduledPageIntelligenceBriefing;
use App\Services\AgenticMarketing\Intelligence\MarketingEvidence;
use App\Services\AgenticMarketing\Intelligence\MarketingInsight;
use App\Services\AgenticMarketing\Intelligence\MarketingRecommendation;
use App\Services\PageIntelligence\Scoring\ScoreEvidence;
use App\Services\PerformanceIntelligence\PerformanceSignal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class EvidenceNormalizer
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function normalize(mixed $value, array $context = []): EvidenceBag
    {
        if ($value instanceof EvidenceBag) {
            return $value;
        }

        if ($value instanceof EvidenceReference) {
            return new EvidenceBag([$value]);
        }

        if ($value instanceof Evidence) {
            return $this->fromEvidence($value);
        }

        if ($value instanceof IntelligenceSignalEvidence) {
            return EvidenceBag::merge(
                $this->fromEvidence($value->evidence),
                new EvidenceBag(
                    array_map(
                        fn (IntelligenceGraphReference $reference): EvidenceReference => EvidenceReference::fromGraphReference($reference),
                        $value->graphReferences,
                    ),
                    metadata: $value->metadata,
                ),
            );
        }

        if ($value instanceof IntelligenceGraphNode) {
            return $this->normalize($value->reference, $context);
        }

        if ($value instanceof IntelligenceGraphReference) {
            return new EvidenceBag([EvidenceReference::fromGraphReference($value)]);
        }

        if ($value instanceof CanonicalEntityReference) {
            return new EvidenceBag([EvidenceReference::canonicalEntity($value)]);
        }

        if ($value instanceof ScoreEvidence) {
            return $this->fromScoreEvidence($value);
        }

        if ($value instanceof MarketingEvidence) {
            return $this->fromMarketingEvidence($value);
        }

        if ($value instanceof PerformanceSignal) {
            return $this->fromPerformanceSignal($value);
        }

        if ($value instanceof MarketingInsight) {
            return $this->fromMarketingInsight($value);
        }

        if ($value instanceof MarketingRecommendation) {
            return $this->fromMarketingRecommendation($value);
        }

        if ($value instanceof Model) {
            return $this->fromModel($value);
        }

        if (is_array($value)) {
            return $this->fromArray($value, $context);
        }

        if (is_iterable($value)) {
            return $this->normalizeMany($value, $context);
        }

        if (is_scalar($value) && trim((string) $value) !== '') {
            return new EvidenceBag([EvidenceReference::resource('resource', (string) $value)]);
        }

        return EvidenceBag::empty();
    }

    public function fromScoreEvidence(ScoreEvidence $evidence): EvidenceBag
    {
        return $this->fromEvidence($evidence->toEvidence());
    }

    public function fromMarketingEvidence(MarketingEvidence $evidence): EvidenceBag
    {
        return $this->fromEvidence($evidence->toEvidence());
    }

    public function fromEvidence(Evidence $evidence): EvidenceBag
    {
        $references = [];

        foreach ($evidence->references as $key => $value) {
            $legacyKey = str($key)->lower()->trim()->toString();

            if ($legacyKey === 'page_intelligence_input_ids') {
                foreach ((array) $value as $inputType => $ids) {
                    foreach ((array) $ids as $id) {
                        $references[] = EvidenceReference::pageIntelligenceInput((string) $inputType, $id);
                    }
                }

                continue;
            }

            if ($legacyKey === 'resource_references') {
                foreach ((array) $value as $type => $ids) {
                    foreach ((array) $ids as $id) {
                        $references[] = EvidenceReference::resource((string) $type, $id);
                    }
                }

                continue;
            }

            foreach ((array) $value as $id) {
                if (is_array($id)) {
                    continue;
                }

                $references[] = $this->referenceFromLegacyValue($legacyKey, $id);
            }
        }

        return new EvidenceBag($references, $evidence->sourceMetrics);
    }

    public function fromPerformanceSignal(PerformanceSignal $signal): EvidenceBag
    {
        return EvidenceBag::merge(
            new EvidenceBag([
                EvidenceReference::performanceSignal(
                    $signal->key,
                    $signal->subjectName ?? $signal->metricKey,
                    confidence: $signal->confidence,
                    weight: 1.0,
                    reason: $signal->explanation,
                    timeWindow: TimeWindow::between($signal->periodStart, $signal->periodEnd),
                    metadata: [
                        'type' => $signal->type,
                        'subject_type' => $signal->subjectType,
                        'subject_key' => $signal->subjectKey,
                        'metric_key' => $signal->metricKey,
                        'direction' => $signal->direction,
                    ] + $signal->metadata,
                    provenance: ['source' => 'performance_intelligence'],
                ),
            ], $signal->sourceMetrics),
            $this->fromEvidence(new Evidence([
                'marketing_observation_ids' => $signal->observationIds,
            ])),
        );
    }

    public function fromMarketingInsight(MarketingInsight $insight): EvidenceBag
    {
        return EvidenceBag::merge(
            $this->fromMarketingEvidence($insight->evidence),
            new EvidenceBag([
                EvidenceReference::marketingInsight(
                    $insight->key,
                    $insight->title,
                    confidence: $insight->confidence,
                    weight: $insight->severity / 100,
                    reason: $insight->summary,
                    metadata: [
                        'type' => $insight->type,
                        'direction' => $insight->direction,
                        'severity' => $insight->severity,
                        'affected_pages' => $insight->affectedPages,
                        'affected_topics' => $insight->affectedTopics,
                        'affected_channels' => $insight->affectedChannels,
                        'affected_competitors' => $insight->affectedCompetitors,
                    ] + $insight->metadata,
                ),
            ]),
        );
    }

    public function fromMarketingRecommendation(MarketingRecommendation $recommendation): EvidenceBag
    {
        return EvidenceBag::merge(
            $this->fromMarketingEvidence($recommendation->evidence),
            new EvidenceBag([
                EvidenceReference::marketingRecommendation(
                    $recommendation->key,
                    $recommendation->title,
                    confidence: $recommendation->confidence,
                    weight: $recommendation->priority / 100,
                    reason: $recommendation->summary,
                    metadata: [
                        'type' => $recommendation->type,
                        'priority' => $recommendation->priority,
                        'recommended_actions' => $recommendation->recommendedActions,
                        'supporting_insight_keys' => $recommendation->supportingInsightKeys,
                        'affected_pages' => $recommendation->affectedPages,
                        'affected_topics' => $recommendation->affectedTopics,
                        'affected_channels' => $recommendation->affectedChannels,
                        'affected_competitors' => $recommendation->affectedCompetitors,
                    ] + $recommendation->metadata,
                ),
            ]),
        );
    }

    public function fromModel(Model $model): EvidenceBag
    {
        return match (true) {
            $model instanceof MarketingObservation => $this->fromMarketingObservation($model),
            $model instanceof PageSnapshot => $this->fromPageSnapshot($model),
            $model instanceof PageIntelligenceReport => $this->fromReport($model),
            $model instanceof ScheduledPageIntelligenceBriefing => $this->fromBriefing($model),
            $model instanceof ConnectorSyncRun => $this->fromConnectorSyncRun($model),
            default => new EvidenceBag([
                EvidenceReference::make(
                    $model->getTable(),
                    (string) ($model->getKey() ?? spl_object_hash($model)),
                    class_basename($model),
                    model: $model::class,
                ),
            ]),
        };
    }

    /**
     * @param  iterable<int, mixed>  $values
     * @param  array<string, mixed>  $context
     */
    public function normalizeMany(iterable $values, array $context = []): EvidenceBag
    {
        $bags = [];

        foreach ($values as $value) {
            $bags[] = $this->normalize($value, $context);
        }

        return EvidenceBag::merge(...$bags);
    }

    /**
     * @param  array<mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    private function fromArray(array $payload, array $context = []): EvidenceBag
    {
        if (array_is_list($payload)) {
            return $this->normalizeMany($payload, $context);
        }

        if (isset($payload['type'], $payload['key'])) {
            return new EvidenceBag([$this->referenceFromPayload($payload)]);
        }

        if (isset($payload['resource_type'], $payload['resource_key'])) {
            return new EvidenceBag([
                EvidenceReference::resource(
                    (string) $payload['resource_type'],
                    (string) $payload['resource_key'],
                    $payload['label'] ?? null,
                    confidence: $this->floatOrNull($payload['confidence'] ?? null),
                    weight: $this->floatOrNull($payload['weight'] ?? null),
                    reason: isset($payload['reason']) ? (string) $payload['reason'] : null,
                    metadata: (array) ($payload['metadata'] ?? []),
                    provenance: (array) ($payload['provenance'] ?? []),
                ),
            ]);
        }

        if ($this->looksLikeEvidencePayload($payload)) {
            return $this->fromEvidence(Evidence::fromArray($payload));
        }

        return EvidenceBag::empty();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function referenceFromPayload(array $payload): EvidenceReference
    {
        return EvidenceReference::make(
            (string) $payload['type'],
            (string) $payload['key'],
            isset($payload['label']) ? (string) $payload['label'] : null,
            confidence: $this->floatOrNull($payload['confidence'] ?? null),
            weight: $this->floatOrNull($payload['weight'] ?? null),
            reason: isset($payload['reason']) ? (string) $payload['reason'] : null,
            metadata: (array) ($payload['metadata'] ?? []),
            provenance: (array) ($payload['provenance'] ?? []),
            stage: $this->stageOrNull($payload['intelligence_stage'] ?? $payload['stage'] ?? null),
            id: $payload['id'] ?? null,
            model: isset($payload['model']) ? (string) $payload['model'] : null,
        );
    }

    private function referenceFromLegacyValue(string $legacyKey, mixed $id): EvidenceReference
    {
        return match ($legacyKey) {
            'marketing_observation_ids' => EvidenceReference::marketingObservation($id),
            'page_snapshot_ids' => EvidenceReference::pageSnapshot($id),
            'page_score_ids' => EvidenceReference::resource(EvidenceReference::TYPE_PAGE_SCORE, $id, stage: IntelligenceStage::SIGNAL),
            'trend_ids' => EvidenceReference::resource(EvidenceReference::TYPE_TREND, $id, stage: IntelligenceStage::SIGNAL),
            'performance_signal_keys' => EvidenceReference::performanceSignal($id),
            'report_ids' => EvidenceReference::report($id),
            'scheduled_briefing_ids', 'briefing_ids' => EvidenceReference::briefing($id),
            'connector_sync_run_ids' => EvidenceReference::connectorSyncRun($id),
            'marketing_insight_keys' => EvidenceReference::marketingInsight($id),
            'marketing_recommendation_keys' => EvidenceReference::marketingRecommendation($id),
            default => EvidenceReference::resource($this->typeFromLegacyKey($legacyKey), $id),
        };
    }

    private function fromMarketingObservation(MarketingObservation $observation): EvidenceBag
    {
        $references = [
            EvidenceReference::marketingObservation(
                (string) $observation->getKey(),
                (string) $observation->metric_key,
                confidence: $this->floatOrNull($observation->confidence_score),
                weight: $this->floatOrNull($observation->quality_score),
                timeWindow: $this->windowFrom($observation->period_start, $observation->period_end, $observation->granularity),
                metadata: [
                    'metric_key' => $observation->metric_key,
                    'unit' => $observation->unit,
                    'quality_score' => $this->floatOrNull($observation->quality_score),
                    'source_metadata' => (array) $observation->source_metadata_json,
                    'quality_metadata' => (array) $observation->quality_metadata_json,
                ],
                provenance: [
                    'connector_provider_id' => $observation->connector_provider_id,
                    'connector_account_id' => $observation->connector_account_id,
                    'connector_dataset_id' => $observation->connector_dataset_id,
                    'connector_sync_run_id' => $observation->connector_sync_run_id,
                    'external_id' => $observation->external_id,
                    'fingerprint' => $observation->fingerprint,
                ],
            ),
        ];

        if ($observation->connector_sync_run_id !== null) {
            $references[] = EvidenceReference::connectorSyncRun(
                (string) $observation->connector_sync_run_id,
                $observation->dataset_key ?? null,
            );
        }

        return new EvidenceBag($references, [
            (string) $observation->metric_key => [
                'value' => $this->floatOrNull($observation->metric_value),
                'unit' => $observation->unit,
            ],
        ]);
    }

    private function fromPageSnapshot(PageSnapshot $snapshot): EvidenceBag
    {
        return new EvidenceBag([
            EvidenceReference::pageSnapshot(
                (string) $snapshot->getKey(),
                $snapshot->final_url ?: $snapshot->requested_url,
                timeWindow: $snapshot->fetched_at instanceof CarbonInterface
                    ? TimeWindow::between($snapshot->fetched_at, $snapshot->fetched_at)
                    : null,
                metadata: [
                    'requested_url' => $snapshot->requested_url,
                    'final_url' => $snapshot->final_url,
                    'canonical_url' => $snapshot->canonical_url,
                    'http_status' => $snapshot->http_status,
                    'content_changed' => $snapshot->content_changed,
                    'metadata' => (array) $snapshot->metadata_json,
                ],
                provenance: [
                    'fetcher_version' => $snapshot->fetcher_version,
                    'raw_html_hash' => $snapshot->raw_html_hash,
                    'text_hash' => $snapshot->text_hash,
                ],
            ),
        ]);
    }

    private function fromReport(PageIntelligenceReport $report): EvidenceBag
    {
        return new EvidenceBag([
            EvidenceReference::report(
                (string) $report->getKey(),
                $report->title,
                timeWindow: $this->windowFrom($report->period_start, $report->period_end),
                metadata: [
                    'report_type' => $report->report_type,
                    'status' => $report->status,
                    'market_pack_key' => $report->market_pack_key,
                    'artifact_status' => $report->artifact_status,
                ],
                provenance: (array) $report->provenance_json,
            ),
        ]);
    }

    private function fromBriefing(ScheduledPageIntelligenceBriefing $briefing): EvidenceBag
    {
        return new EvidenceBag([
            EvidenceReference::briefing(
                (string) $briefing->getKey(),
                $briefing->report_type,
                metadata: [
                    'report_type' => $briefing->report_type,
                    'frequency' => $briefing->frequency,
                    'market_pack_key' => $briefing->market_pack_key,
                    'is_active' => $briefing->is_active,
                ],
                provenance: [
                    'timezone' => $briefing->timezone,
                    'created_by' => $briefing->created_by,
                ],
            ),
        ]);
    }

    private function fromConnectorSyncRun(ConnectorSyncRun $run): EvidenceBag
    {
        return new EvidenceBag([
            EvidenceReference::connectorSyncRun(
                (string) $run->getKey(),
                trim((string) $run->provider_key.':'.(string) $run->dataset_key, ':'),
                timeWindow: $this->windowFrom($run->window_start, $run->window_end),
                metadata: [
                    'provider_key' => $run->provider_key,
                    'dataset_key' => $run->dataset_key,
                    'status' => $run->status,
                    'run_type' => $run->run_type,
                    'metrics' => (array) $run->metrics_json,
                    'rate_limit' => (array) $run->rate_limit_json,
                ],
                provenance: [
                    'idempotency_key' => $run->idempotency_key,
                    'attempts' => $run->attempts,
                ],
            ),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function looksLikeEvidencePayload(array $payload): bool
    {
        if (array_key_exists(Evidence::SOURCE_METRICS_KEY, $payload)) {
            return true;
        }

        return collect(array_keys($payload))->contains(function (mixed $key): bool {
            if (! is_string($key)) {
                return false;
            }

            return str_ends_with($key, '_ids')
                || str_ends_with($key, '_keys')
                || in_array($key, ['page_intelligence_input_ids', 'resource_references'], true);
        });
    }

    private function typeFromLegacyKey(string $legacyKey): string
    {
        return (string) str($legacyKey)
            ->replaceEnd('_ids', '')
            ->replaceEnd('_keys', '')
            ->singular()
            ->slug('_');
    }

    private function stageOrNull(mixed $stage): ?IntelligenceStage
    {
        if ($stage instanceof IntelligenceStage) {
            return $stage;
        }

        if (! is_string($stage) || trim($stage) === '') {
            return null;
        }

        return IntelligenceStage::tryFrom($stage);
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function windowFrom(mixed $start, mixed $end, ?string $granularity = null): ?TimeWindow
    {
        if (! ($start instanceof CarbonInterface || is_string($start)) || ! ($end instanceof CarbonInterface || is_string($end))) {
            return null;
        }

        return TimeWindow::between($start, $end, $this->granularity($granularity));
    }

    private function granularity(?string $granularity): string
    {
        return match ($granularity) {
            MarketingObservation::GRANULARITY_WEEKLY => TimeWindow::GRANULARITY_WEEKLY,
            MarketingObservation::GRANULARITY_MONTHLY => TimeWindow::GRANULARITY_MONTHLY,
            default => TimeWindow::GRANULARITY_DAILY,
        };
    }
}
