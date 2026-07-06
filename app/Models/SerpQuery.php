<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SerpQuery extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasSignalIntelligenceTenancy;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'serp_query_set_id',
        'query',
        'query_hash',
        'locale',
        'country',
        'device',
        'search_engine',
        'keyword_intent',
        'search_volume',
        'priority',
        'status',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'search_volume' => 'integer',
        'priority' => 'integer',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (SerpQuery $query): void {
            $query->query = trim($query->query);
            $query->query_hash = hash('sha256', mb_strtolower($query->query));
            $query->country = $query->country ? strtoupper((string) $query->country) : null;
            $query->device = strtolower(trim((string) ($query->device ?: 'desktop')));
            $query->search_engine = strtolower(trim((string) ($query->search_engine ?: 'google')));
        });
    }

    public function querySet(): BelongsTo
    {
        return $this->belongsTo(SerpQuerySet::class, 'serp_query_set_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(PageSerpObservation::class);
    }
}
