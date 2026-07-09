<?php

namespace App\Support\Intelligence;

use App\Support\MarketingMetadataRedactor;
use InvalidArgumentException;
use Stringable;

class EvidenceReference
{
    public const TYPE_PAGE_SNAPSHOT = 'page_snapshot';
    public const TYPE_MARKETING_OBSERVATION = 'marketing_observation';
    public const TYPE_PERFORMANCE_SIGNAL = 'performance_signal';
    public const TYPE_MARKETING_INSIGHT = 'marketing_insight';
    public const TYPE_MARKETING_RECOMMENDATION = 'marketing_recommendation';
    public const TYPE_REPORT = 'report';
    public const TYPE_BRIEFING = 'briefing';
    public const TYPE_CONNECTOR_SYNC_RUN = 'connector_sync_run';
    public const TYPE_PAGE_SCORE = 'page_score';
    public const TYPE_TREND = 'trend';
    public const TYPE_PAGE_INTELLIGENCE_INPUT = 'page_intelligence_input';
    public const TYPE_CANONICAL_ENTITY = 'canonical_entity';
    public const TYPE_GRAPH_REFERENCE = 'graph_reference';

    public readonly string $type;

    public readonly string $key;

    public readonly ?string $label;

    public readonly ?string $id;

    public readonly ?string $model;

    public readonly ?IntelligenceGraphReference $graphReference;

    public readonly ?CanonicalEntityReference $entity;

    public readonly ?IntelligenceStage $stage;

    public readonly ?float $confidence;

    public readonly ?float $weight;

    public readonly ?string $reason;

    public readonly ?TimeWindow $timeWindow;

    /**
     * @var array<string, mixed>
     */
    public readonly array $metadata;

    /**
     * @var array<string, mixed>
     */
    public readonly array $provenance;

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        string $type,
        string|int|Stringable $key,
        ?string $label = null,
        string|int|null $id = null,
        ?string $model = null,
        ?IntelligenceGraphReference $graphReference = null,
        ?CanonicalEntityReference $entity = null,
        ?IntelligenceStage $stage = null,
        ?float $confidence = null,
        ?float $weight = null,
        ?string $reason = null,
        ?TimeWindow $timeWindow = null,
        array $metadata = [],
        array $provenance = [],
    ) {
        $this->type = self::normalizePart($type);
        $this->key = self::normalizeKey($key);
        $this->label = $label !== null && trim($label) !== '' ? trim($label) : null;
        $this->id = $id !== null && trim((string) $id) !== '' ? trim((string) $id) : null;
        $this->model = $model !== null && trim($model) !== '' ? trim($model) : null;
        $this->graphReference = $graphReference;
        $this->entity = $entity;
        $this->stage = $stage;
        $this->confidence = self::normalizeConfidence($confidence);
        $this->weight = self::normalizeWeight($weight);
        $this->reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        $this->timeWindow = $timeWindow;
        $this->metadata = MarketingMetadataRedactor::redact($metadata);
        $this->provenance = MarketingMetadataRedactor::redact($provenance);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public static function make(
        string $type,
        string|int|Stringable $key,
        ?string $label = null,
        ?float $confidence = null,
        ?float $weight = null,
        ?string $reason = null,
        ?TimeWindow $timeWindow = null,
        array $metadata = [],
        array $provenance = [],
        ?IntelligenceStage $stage = null,
        string|int|null $id = null,
        ?string $model = null,
        ?IntelligenceGraphReference $graphReference = null,
        ?CanonicalEntityReference $entity = null,
    ): self {
        return new self(
            $type,
            $key,
            $label,
            $id,
            $model,
            $graphReference,
            $entity,
            $stage,
            $confidence,
            $weight,
            $reason,
            $timeWindow,
            $metadata,
            $provenance,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public static function pageSnapshot(
        string|int|Stringable $key,
        ?string $label = null,
        ?float $confidence = null,
        ?float $weight = null,
        ?string $reason = null,
        ?TimeWindow $timeWindow = null,
        array $metadata = [],
        array $provenance = [],
    ): self {
        return self::make(
            self::TYPE_PAGE_SNAPSHOT,
            $key,
            $label,
            $confidence,
            $weight,
            $reason,
            $timeWindow,
            $metadata,
            $provenance,
            IntelligenceStage::RAW_OBSERVATION,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public static function marketingObservation(
        string|int|Stringable $key,
        ?string $label = null,
        ?float $confidence = null,
        ?float $weight = null,
        ?string $reason = null,
        ?TimeWindow $timeWindow = null,
        array $metadata = [],
        array $provenance = [],
    ): self {
        return self::make(
            self::TYPE_MARKETING_OBSERVATION,
            $key,
            $label,
            $confidence,
            $weight,
            $reason,
            $timeWindow,
            $metadata,
            $provenance,
            IntelligenceStage::RAW_OBSERVATION,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public static function performanceSignal(
        string|int|Stringable $key,
        ?string $label = null,
        ?float $confidence = null,
        ?float $weight = null,
        ?string $reason = null,
        ?TimeWindow $timeWindow = null,
        array $metadata = [],
        array $provenance = [],
    ): self {
        return self::make(
            self::TYPE_PERFORMANCE_SIGNAL,
            $key,
            $label,
            $confidence,
            $weight,
            $reason,
            $timeWindow,
            $metadata,
            $provenance,
            IntelligenceStage::SIGNAL,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public static function marketingInsight(
        string|int|Stringable $key,
        ?string $label = null,
        ?float $confidence = null,
        ?float $weight = null,
        ?string $reason = null,
        ?TimeWindow $timeWindow = null,
        array $metadata = [],
        array $provenance = [],
    ): self {
        return self::make(
            self::TYPE_MARKETING_INSIGHT,
            $key,
            $label,
            $confidence,
            $weight,
            $reason,
            $timeWindow,
            $metadata,
            $provenance,
            IntelligenceStage::INSIGHT,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public static function marketingRecommendation(
        string|int|Stringable $key,
        ?string $label = null,
        ?float $confidence = null,
        ?float $weight = null,
        ?string $reason = null,
        ?TimeWindow $timeWindow = null,
        array $metadata = [],
        array $provenance = [],
    ): self {
        return self::make(
            self::TYPE_MARKETING_RECOMMENDATION,
            $key,
            $label,
            $confidence,
            $weight,
            $reason,
            $timeWindow,
            $metadata,
            $provenance,
            IntelligenceStage::RECOMMENDATION,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public static function report(
        string|int|Stringable $key,
        ?string $label = null,
        ?float $confidence = null,
        ?float $weight = null,
        ?string $reason = null,
        ?TimeWindow $timeWindow = null,
        array $metadata = [],
        array $provenance = [],
    ): self {
        return self::make(
            self::TYPE_REPORT,
            $key,
            $label,
            $confidence,
            $weight,
            $reason,
            $timeWindow,
            $metadata,
            $provenance,
            IntelligenceStage::INSIGHT,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public static function briefing(
        string|int|Stringable $key,
        ?string $label = null,
        ?float $confidence = null,
        ?float $weight = null,
        ?string $reason = null,
        ?TimeWindow $timeWindow = null,
        array $metadata = [],
        array $provenance = [],
    ): self {
        return self::make(
            self::TYPE_BRIEFING,
            $key,
            $label,
            $confidence,
            $weight,
            $reason,
            $timeWindow,
            $metadata,
            $provenance,
            IntelligenceStage::RECOMMENDATION,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public static function connectorSyncRun(
        string|int|Stringable $key,
        ?string $label = null,
        ?float $confidence = null,
        ?float $weight = null,
        ?string $reason = null,
        ?TimeWindow $timeWindow = null,
        array $metadata = [],
        array $provenance = [],
    ): self {
        return self::make(
            self::TYPE_CONNECTOR_SYNC_RUN,
            $key,
            $label,
            $confidence,
            $weight,
            $reason,
            $timeWindow,
            $metadata,
            $provenance,
            IntelligenceStage::RAW_OBSERVATION,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public static function pageIntelligenceInput(
        string $inputType,
        string|int|Stringable $key,
        ?string $label = null,
        ?float $confidence = null,
        ?float $weight = null,
        ?string $reason = null,
        ?TimeWindow $timeWindow = null,
        array $metadata = [],
        array $provenance = [],
    ): self {
        return self::make(
            self::TYPE_PAGE_INTELLIGENCE_INPUT,
            $key,
            $label,
            $confidence,
            $weight,
            $reason,
            $timeWindow,
            ['input_type' => self::normalizePart($inputType)] + $metadata,
            $provenance,
            IntelligenceStage::RAW_OBSERVATION,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public static function resource(
        string $type,
        string|int|Stringable $key,
        ?string $label = null,
        ?float $confidence = null,
        ?float $weight = null,
        ?string $reason = null,
        ?TimeWindow $timeWindow = null,
        array $metadata = [],
        array $provenance = [],
        ?IntelligenceStage $stage = null,
    ): self {
        return self::make(
            $type,
            $key,
            $label,
            $confidence,
            $weight,
            $reason,
            $timeWindow,
            $metadata,
            $provenance,
            $stage,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $provenance
     */
    public static function canonicalEntity(
        CanonicalEntityReference $entity,
        ?float $confidence = null,
        ?float $weight = null,
        ?string $reason = null,
        ?TimeWindow $timeWindow = null,
        array $metadata = [],
        array $provenance = [],
        ?IntelligenceStage $stage = null,
    ): self {
        return self::make(
            self::TYPE_CANONICAL_ENTITY,
            $entity->type.':'.$entity->key,
            $entity->name,
            $confidence,
            $weight,
            $reason,
            $timeWindow,
            $metadata,
            $provenance,
            $stage,
            entity: $entity,
        );
    }

    public static function fromGraphReference(IntelligenceGraphReference $reference): self
    {
        $type = match ($reference->type) {
            IntelligenceGraphReference::TYPE_OBSERVATION => self::TYPE_MARKETING_OBSERVATION,
            IntelligenceGraphReference::TYPE_SIGNAL => self::TYPE_PERFORMANCE_SIGNAL,
            IntelligenceGraphReference::TYPE_INSIGHT => self::TYPE_MARKETING_INSIGHT,
            IntelligenceGraphReference::TYPE_RECOMMENDATION => self::TYPE_MARKETING_RECOMMENDATION,
            IntelligenceGraphReference::TYPE_REPORT => self::TYPE_REPORT,
            IntelligenceGraphReference::TYPE_BRIEFING => self::TYPE_BRIEFING,
            IntelligenceGraphReference::TYPE_ENTITY => self::TYPE_CANONICAL_ENTITY,
            default => self::TYPE_GRAPH_REFERENCE,
        };

        return self::make(
            $type,
            $type === self::TYPE_GRAPH_REFERENCE ? $reference->graphKey() : $reference->key,
            $reference->label,
            metadata: $reference->metadata,
            stage: null,
            id: $reference->id,
            model: $reference->model,
            graphReference: $reference,
            entity: $reference->entity,
        );
    }

    public function identityKey(): string
    {
        return $this->type.':'.$this->key;
    }

    public function legacyKey(): ?string
    {
        return match ($this->type) {
            self::TYPE_MARKETING_OBSERVATION => 'marketing_observation_ids',
            self::TYPE_PAGE_SNAPSHOT => 'page_snapshot_ids',
            self::TYPE_PAGE_SCORE => 'page_score_ids',
            self::TYPE_TREND => 'trend_ids',
            self::TYPE_PERFORMANCE_SIGNAL => 'performance_signal_keys',
            self::TYPE_REPORT => 'report_ids',
            self::TYPE_BRIEFING => 'scheduled_briefing_ids',
            self::TYPE_CONNECTOR_SYNC_RUN => 'connector_sync_run_ids',
            self::TYPE_MARKETING_INSIGHT => 'marketing_insight_keys',
            self::TYPE_MARKETING_RECOMMENDATION => 'marketing_recommendation_keys',
            default => null,
        };
    }

    public function toGraphReference(): IntelligenceGraphReference
    {
        if ($this->graphReference instanceof IntelligenceGraphReference) {
            return $this->graphReference;
        }

        if ($this->entity instanceof CanonicalEntityReference) {
            return IntelligenceGraphReference::entity($this->entity);
        }

        return match ($this->type) {
            self::TYPE_MARKETING_OBSERVATION => IntelligenceGraphReference::observation($this->key, $this->label, $this->metadata),
            self::TYPE_PERFORMANCE_SIGNAL => IntelligenceGraphReference::signal($this->key, $this->label, $this->metadata),
            self::TYPE_MARKETING_INSIGHT => IntelligenceGraphReference::insight($this->key, $this->label, $this->metadata),
            self::TYPE_MARKETING_RECOMMENDATION => IntelligenceGraphReference::recommendation($this->key, $this->label, $this->metadata),
            self::TYPE_REPORT => IntelligenceGraphReference::report($this->key, $this->label, $this->metadata),
            self::TYPE_BRIEFING => IntelligenceGraphReference::briefing($this->key, $this->label, $this->metadata),
            default => IntelligenceGraphReference::make($this->type, $this->key, $this->label, $this->id, $this->model, $this->metadata),
        };
    }

    public function merge(self $reference): self
    {
        if ($this->identityKey() !== $reference->identityKey()) {
            throw new InvalidArgumentException('Cannot merge different evidence references.');
        }

        return new self(
            $this->type,
            $this->key,
            $reference->label ?? $this->label,
            $reference->id ?? $this->id,
            $reference->model ?? $this->model,
            $reference->graphReference ?? $this->graphReference,
            $reference->entity ?? $this->entity,
            self::latestStage($this->stage, $reference->stage),
            self::maxNullable($this->confidence, $reference->confidence),
            self::sumNullable($this->weight, $reference->weight),
            self::mergeReason($this->reason, $reference->reason),
            self::mergeTimeWindow($this->timeWindow, $reference->timeWindow),
            array_replace_recursive($this->metadata, $reference->metadata),
            array_replace_recursive($this->provenance, $reference->provenance),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'key' => $this->key,
            'id' => $this->id,
            'label' => $this->label,
            'model' => $this->model,
            'graph_reference' => $this->toGraphReference()->toArray(),
            'entity' => $this->entity?->toArray(),
            'intelligence_stage' => $this->stage?->value,
            'confidence' => $this->confidence,
            'weight' => $this->weight,
            'reason' => $this->reason,
            'time_window' => $this->timeWindow?->toArray(),
            'metadata' => $this->metadata,
            'provenance' => $this->provenance,
        ];
    }

    private static function normalizePart(string $value): string
    {
        $normalized = str($value)->lower()->trim()->slug('_')->toString();

        return $normalized !== '' ? $normalized : 'resource';
    }

    private static function normalizeKey(string|int|Stringable $key): string
    {
        $normalized = trim((string) $key);

        return $normalized !== '' ? $normalized : hash('sha256', (string) $key);
    }

    private static function normalizeConfidence(?float $confidence): ?float
    {
        if ($confidence === null) {
            return null;
        }

        $confidence = $confidence > 1.0 && $confidence <= 100.0
            ? $confidence / 100.0
            : $confidence;

        return round(max(0.0, min(1.0, $confidence)), 4);
    }

    private static function normalizeWeight(?float $weight): ?float
    {
        return $weight === null ? null : round(max(0.0, $weight), 4);
    }

    private static function maxNullable(?float $left, ?float $right): ?float
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return max($left, $right);
    }

    private static function sumNullable(?float $left, ?float $right): ?float
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return round($left + $right, 4);
    }

    private static function mergeReason(?string $left, ?string $right): ?string
    {
        $reasons = collect([$left, $right])
            ->filter(fn (?string $reason): bool => $reason !== null && trim($reason) !== '')
            ->unique()
            ->values();

        return $reasons->isEmpty() ? null : $reasons->implode(' | ');
    }

    private static function mergeTimeWindow(?TimeWindow $left, ?TimeWindow $right): ?TimeWindow
    {
        if (! $left instanceof TimeWindow) {
            return $right;
        }

        if (! $right instanceof TimeWindow) {
            return $left;
        }

        return new TimeWindow(
            $left->start->lessThanOrEqualTo($right->start) ? $left->start : $right->start,
            $left->end->greaterThanOrEqualTo($right->end) ? $left->end : $right->end,
            $left->granularity,
        );
    }

    private static function latestStage(?IntelligenceStage $left, ?IntelligenceStage $right): ?IntelligenceStage
    {
        if (! $left instanceof IntelligenceStage) {
            return $right;
        }

        if (! $right instanceof IntelligenceStage) {
            return $left;
        }

        return $left->precedes($right) ? $right : $left;
    }
}
