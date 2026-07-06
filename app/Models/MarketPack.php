<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketPack extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'key',
        'name',
        'description',
        'market_category',
        'status',
        'version',
        'locale',
        'defaults_json',
        'metadata_json',
    ];

    protected $casts = [
        'defaults_json' => 'array',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function sources(): HasMany
    {
        return $this->hasMany(MarketPackSource::class);
    }

    public function competitors(): HasMany
    {
        return $this->hasMany(MarketPackCompetitor::class);
    }

    public function themes(): HasMany
    {
        return $this->hasMany(MarketPackTheme::class);
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(MarketPackKeyword::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(MarketPackMetric::class);
    }

    public function alertTemplates(): HasMany
    {
        return $this->hasMany(MarketPackAlertTemplate::class);
    }

    public function scoringModels(): HasMany
    {
        return $this->hasMany(MarketPackScoringModel::class);
    }

    public function installations(): HasMany
    {
        return $this->hasMany(MarketPackInstallation::class);
    }
}
