<?php

namespace App\Models;

use App\Support\MarketingMetadataRedactor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingObservationDimension extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'marketing_observation_id',
        'marketing_dimension_definition_id',
        'dimension_key',
        'dimension_value',
        'dimension_value_normalized',
        'dimension_value_hash',
        'metadata_json',
    ];

    protected $casts = [
        'metadata_json' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (MarketingObservationDimension $dimension): void {
            $dimension->dimension_value_normalized = self::normalizeValue($dimension->dimension_value_normalized ?? $dimension->dimension_value);
            $dimension->dimension_value_hash = $dimension->dimension_value_hash
                ?: hash('sha256', (string) $dimension->dimension_value_normalized);
        });
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(MarketingObservation::class, 'marketing_observation_id');
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(MarketingDimensionDefinition::class, 'marketing_dimension_definition_id');
    }

    public function setMetadataJsonAttribute(?array $value): void
    {
        $this->attributes['metadata_json'] = $value === null
            ? null
            : json_encode(MarketingMetadataRedactor::redact($value));
    }

    private static function normalizeValue(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
