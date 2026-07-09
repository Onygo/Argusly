<?php

namespace App\Models;

use App\Support\MarketingMetadataRedactor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingMetricDefinition extends Model
{
    use HasFactory;
    use HasUuids;

    public const VALUE_TYPE_DECIMAL = 'decimal';
    public const VALUE_TYPE_INTEGER = 'integer';
    public const VALUE_TYPE_PERCENT = 'percent';
    public const VALUE_TYPE_CURRENCY = 'currency';

    public const AGGREGATION_SUM = 'sum';
    public const AGGREGATION_AVERAGE = 'average';
    public const AGGREGATION_LATEST = 'latest';

    protected $fillable = [
        'metric_key',
        'display_name',
        'description',
        'value_type',
        'default_unit',
        'aggregation',
        'direction',
        'is_active',
        'metadata_json',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata_json' => 'array',
    ];

    public function observations(): HasMany
    {
        return $this->hasMany(MarketingObservation::class);
    }

    public function setMetadataJsonAttribute(?array $value): void
    {
        $this->attributes['metadata_json'] = $value === null
            ? null
            : json_encode(MarketingMetadataRedactor::redact($value));
    }
}
