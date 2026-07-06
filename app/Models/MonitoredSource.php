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

class MonitoredSource extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasSignalIntelligenceTenancy;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_NEW = 'new';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'source_type',
        'name',
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
        'last_discovered_at',
        'last_fetched_at',
        'last_processed_at',
        'failure_count',
        'last_error',
        'created_by',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'trust_level' => 'integer',
        'authority_score' => 'decimal:2',
        'crawl_policy_json' => 'array',
        'fetch_config_json' => 'array',
        'discovery_config_json' => 'array',
        'metadata_json' => 'array',
        'last_discovered_at' => 'datetime',
        'last_fetched_at' => 'datetime',
        'last_processed_at' => 'datetime',
        'failure_count' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(MonitoredPage::class);
    }
}
