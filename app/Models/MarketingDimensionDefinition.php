<?php

namespace App\Models;

use App\Support\MarketingMetadataRedactor;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingDimensionDefinition extends Model
{
    use HasFactory;
    use HasUuids;

    public const VALUE_TYPE_STRING = 'string';
    public const VALUE_TYPE_INTEGER = 'integer';
    public const VALUE_TYPE_DATE = 'date';
    public const VALUE_TYPE_BOOLEAN = 'boolean';
    public const VALUE_TYPE_URL = 'url';

    protected $fillable = [
        'dimension_key',
        'display_name',
        'description',
        'value_type',
        'is_active',
        'metadata_json',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata_json' => 'array',
    ];

    public function observationDimensions(): HasMany
    {
        return $this->hasMany(MarketingObservationDimension::class);
    }

    public function setMetadataJsonAttribute(?array $value): void
    {
        $this->attributes['metadata_json'] = $value === null
            ? null
            : json_encode(MarketingMetadataRedactor::redact($value));
    }
}
