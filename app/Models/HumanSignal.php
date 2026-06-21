<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\HumanSignalType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HumanSignal extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_DETECTED = 'detected';
    public const STATUS_REVIEWING = 'reviewing';
    public const STATUS_ACTIONED = 'actioned';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'site_id',
        'type',
        'title',
        'observation',
        'impact',
        'confidence_score',
        'status',
        'detected_at',
        'expires_at',
        'metadata_json',
        'dedupe_hash',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'type' => HumanSignalType::class,
        'confidence_score' => 'float',
        'detected_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'site_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(HumanSignalEvidence::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(HumanSignalInsight::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereNotIn('status', [self::STATUS_DISMISSED, self::STATUS_EXPIRED])
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
