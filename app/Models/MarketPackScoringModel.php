<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketPackScoringModel extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'market_pack_id',
        'key',
        'name',
        'model_type',
        'model_version',
        'weights_json',
        'defaults_json',
        'metadata_json',
    ];

    protected $casts = [
        'weights_json' => 'array',
        'defaults_json' => 'array',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function marketPack(): BelongsTo
    {
        return $this->belongsTo(MarketPack::class);
    }
}
