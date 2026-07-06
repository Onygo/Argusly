<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketPackAlertTemplate extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'market_pack_id',
        'key',
        'name',
        'trigger',
        'conditions_json',
        'cooldown_minutes',
        'severity',
        'is_active',
        'metadata_json',
    ];

    protected $casts = [
        'conditions_json' => 'array',
        'cooldown_minutes' => 'integer',
        'is_active' => 'boolean',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function marketPack(): BelongsTo
    {
        return $this->belongsTo(MarketPack::class);
    }
}
