<?php

namespace App\Models;

use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorProvider;
use App\Models\Connectors\ConnectorSyncRun;
use App\Support\MarketingMetadataRedactor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

class MarketingObservation extends Model
{
    use HasFactory;
    use HasUuids;

    public const GRANULARITY_HOURLY = 'hour';
    public const GRANULARITY_DAILY = 'day';
    public const GRANULARITY_WEEKLY = 'week';
    public const GRANULARITY_MONTHLY = 'month';
    public const GRANULARITY_EVENT = 'event';

    protected $fillable = [
        'workspace_id',
        'client_site_id',
        'connector_provider_id',
        'connector_account_id',
        'connector_dataset_id',
        'connector_sync_run_id',
        'marketing_metric_definition_id',
        'metric_key',
        'metric_value',
        'unit',
        'period_start',
        'period_end',
        'granularity',
        'observed_at',
        'confidence_score',
        'quality_score',
        'external_id',
        'fingerprint',
        'source_metadata_json',
        'quality_metadata_json',
        'raw_metadata_json',
        'raw_payload_ref',
    ];

    protected $casts = [
        'metric_value' => 'decimal:10',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'observed_at' => 'datetime',
        'confidence_score' => 'decimal:4',
        'quality_score' => 'decimal:4',
        'source_metadata_json' => 'array',
        'quality_metadata_json' => 'array',
        'raw_metadata_json' => 'array',
    ];

    /**
     * @param  array<string,mixed>  $attributes
     * @param  array<mixed>  $dimensions
     * @param  array<int,array<string,mixed>>  $attributions
     */
    public static function upsertByFingerprint(array $attributes, array $dimensions = [], array $attributions = []): self
    {
        $attributes['fingerprint'] ??= self::fingerprintFor($attributes, $dimensions);

        $observation = self::query()->updateOrCreate(
            ['fingerprint' => $attributes['fingerprint']],
            $attributes
        );

        $observation->replaceDimensions($dimensions);
        $observation->replaceAttributions($attributions);

        return $observation->fresh(['dimensions', 'attributions']) ?? $observation;
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @param  array<mixed>  $dimensions
     */
    public static function fingerprintFor(array $attributes, array $dimensions = []): string
    {
        $dimensionPayload = collect(self::normalizeDimensions($dimensions))
            ->map(fn (array $dimension): array => [
                'key' => $dimension['dimension_key'],
                'value' => $dimension['dimension_value_normalized'],
            ])
            ->sortBy(fn (array $dimension): string => $dimension['key'].'='.$dimension['value'])
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'workspace_id' => (string) Arr::get($attributes, 'workspace_id'),
            'connector_provider_id' => (string) Arr::get($attributes, 'connector_provider_id'),
            'connector_account_id' => (string) Arr::get($attributes, 'connector_account_id'),
            'connector_dataset_id' => (string) Arr::get($attributes, 'connector_dataset_id'),
            'connector_sync_run_id' => (string) Arr::get($attributes, 'connector_sync_run_id'),
            'metric_key' => (string) Arr::get($attributes, 'metric_key'),
            'unit' => (string) Arr::get($attributes, 'unit'),
            'period_start' => (string) Arr::get($attributes, 'period_start'),
            'period_end' => (string) Arr::get($attributes, 'period_end'),
            'granularity' => (string) Arr::get($attributes, 'granularity'),
            'external_id' => (string) Arr::get($attributes, 'external_id'),
            'dimensions' => $dimensionPayload,
        ], JSON_THROW_ON_ERROR));
    }

    public function scopeForWorkspace(Builder $query, Workspace|string $workspace): Builder
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeForClientSite(Builder $query, ClientSite|string $clientSite): Builder
    {
        $clientSiteId = $clientSite instanceof ClientSite ? $clientSite->id : $clientSite;

        return $query->where('client_site_id', $clientSiteId);
    }

    public function scopeForMetric(Builder $query, MarketingMetricDefinition|string $metric): Builder
    {
        $metricKey = $metric instanceof MarketingMetricDefinition ? $metric->metric_key : $metric;

        return $query->where('metric_key', $metricKey);
    }

    public function scopeForDataset(Builder $query, ConnectorDataset|string $dataset): Builder
    {
        $datasetId = $dataset instanceof ConnectorDataset ? $dataset->id : $dataset;

        return $query->where('connector_dataset_id', $datasetId);
    }

    public function scopeBetweenPeriods(Builder $query, mixed $start, mixed $end): Builder
    {
        return $query
            ->where('period_start', '>=', $start)
            ->where('period_end', '<=', $end);
    }

    public function scopeGranularity(Builder $query, string $granularity): Builder
    {
        return $query->where('granularity', $granularity);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function connectorProvider(): BelongsTo
    {
        return $this->belongsTo(ConnectorProvider::class, 'connector_provider_id');
    }

    public function connectorAccount(): BelongsTo
    {
        return $this->belongsTo(ConnectorAccount::class, 'connector_account_id');
    }

    public function connectorDataset(): BelongsTo
    {
        return $this->belongsTo(ConnectorDataset::class, 'connector_dataset_id');
    }

    public function connectorSyncRun(): BelongsTo
    {
        return $this->belongsTo(ConnectorSyncRun::class, 'connector_sync_run_id');
    }

    public function metricDefinition(): BelongsTo
    {
        return $this->belongsTo(MarketingMetricDefinition::class, 'marketing_metric_definition_id');
    }

    public function dimensions(): HasMany
    {
        return $this->hasMany(MarketingObservationDimension::class);
    }

    public function attributions(): HasMany
    {
        return $this->hasMany(MarketingAttribution::class);
    }

    public function setSourceMetadataJsonAttribute(?array $value): void
    {
        $this->attributes['source_metadata_json'] = $this->encodedRedactedMetadata($value);
    }

    public function setQualityMetadataJsonAttribute(?array $value): void
    {
        $this->attributes['quality_metadata_json'] = $this->encodedRedactedMetadata($value);
    }

    public function setRawMetadataJsonAttribute(?array $value): void
    {
        $this->attributes['raw_metadata_json'] = $this->encodedRedactedMetadata($value);
    }

    /**
     * @param  array<mixed>  $dimensions
     */
    public function replaceDimensions(array $dimensions): void
    {
        $normalized = self::normalizeDimensions($dimensions);
        $seen = [];

        foreach ($normalized as $dimension) {
            $seen[] = $dimension['dimension_key'].'|'.$dimension['dimension_value_hash'];

            $this->dimensions()->updateOrCreate(
                [
                    'dimension_key' => $dimension['dimension_key'],
                    'dimension_value_hash' => $dimension['dimension_value_hash'],
                ],
                $dimension
            );
        }

        $this->dimensions()
            ->get()
            ->each(function (MarketingObservationDimension $dimension) use ($seen): void {
                $identity = $dimension->dimension_key.'|'.$dimension->dimension_value_hash;

                if (! in_array($identity, $seen, true)) {
                    $dimension->delete();
                }
            });
    }

    /**
     * @param  array<int,array<string,mixed>>  $attributions
     */
    public function replaceAttributions(array $attributions): void
    {
        if ($attributions === []) {
            return;
        }

        $this->attributions()->delete();

        foreach ($attributions as $attribution) {
            $this->attributions()->create(array_merge([
                'workspace_id' => $this->workspace_id,
                'client_site_id' => $this->client_site_id,
            ], $attribution));
        }
    }

    /**
     * @param  array<mixed>  $dimensions
     * @return array<int,array<string,mixed>>
     */
    private static function normalizeDimensions(array $dimensions): array
    {
        if ($dimensions === []) {
            return [];
        }

        $definitions = MarketingDimensionDefinition::query()
            ->whereIn('dimension_key', collect($dimensions)
                ->map(fn (mixed $dimension, mixed $key): ?string => is_array($dimension)
                    ? ($dimension['dimension_key'] ?? $dimension['key'] ?? null)
                    : (is_string($key) ? $key : null))
                ->filter()
                ->values()
                ->all())
            ->get()
            ->keyBy('dimension_key');

        $normalized = [];

        foreach ($dimensions as $key => $dimension) {
            $payload = is_array($dimension)
                ? $dimension
                : ['dimension_key' => $key, 'dimension_value' => $dimension];

            $dimensionKey = (string) ($payload['dimension_key'] ?? $payload['key'] ?? $key);
            $dimensionValue = $payload['dimension_value'] ?? $payload['value'] ?? null;
            $normalizedValue = self::normalizeDimensionValue($dimensionValue);
            $definition = $definitions->get($dimensionKey);

            $normalized[] = [
                'marketing_dimension_definition_id' => $payload['marketing_dimension_definition_id']
                    ?? $definition?->id,
                'dimension_key' => $dimensionKey,
                'dimension_value' => $dimensionValue === null ? null : (string) $dimensionValue,
                'dimension_value_normalized' => $normalizedValue,
                'dimension_value_hash' => hash('sha256', $normalizedValue),
                'metadata_json' => $payload['metadata_json'] ?? $payload['metadata'] ?? [],
            ];
        }

        return $normalized;
    }

    private static function normalizeDimensionValue(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function encodedRedactedMetadata(?array $value): ?string
    {
        return $value === null
            ? null
            : json_encode(MarketingMetadataRedactor::redact($value));
    }
}
