<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketPackMetric extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'market_pack_id',
        'key',
        'name',
        'metric_type',
        'default_value',
        'unit',
        'direction',
        'weight',
        'metadata_json',
    ];

    protected $casts = [
        'default_value' => 'decimal:4',
        'weight' => 'decimal:4',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function marketPack(): BelongsTo
    {
        return $this->belongsTo(MarketPack::class);
    }
}
