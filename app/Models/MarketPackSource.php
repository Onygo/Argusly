<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketPackSource extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'market_pack_id',
        'key',
        'name',
        'source_type',
        'base_url',
        'domain',
        'status',
        'trust_level',
        'authority_score',
        'polling_frequency',
        'crawl_policy_json',
        'fetch_config_json',
        'discovery_config_json',
        'metadata_json',
    ];

    protected $casts = [
        'trust_level' => 'integer',
        'authority_score' => 'decimal:2',
        'crawl_policy_json' => 'array',
        'fetch_config_json' => 'array',
        'discovery_config_json' => 'array',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function marketPack(): BelongsTo
    {
        return $this->belongsTo(MarketPack::class);
    }
}
