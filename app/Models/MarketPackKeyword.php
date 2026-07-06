<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketPackKeyword extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'market_pack_id',
        'market_pack_theme_id',
        'keyword',
        'keyword_type',
        'intent',
        'weight',
        'metadata_json',
    ];

    protected $casts = [
        'weight' => 'decimal:4',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function marketPack(): BelongsTo
    {
        return $this->belongsTo(MarketPack::class);
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(MarketPackTheme::class, 'market_pack_theme_id');
    }
}
