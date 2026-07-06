<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketPackInstallation extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'market_pack_id',
        'status',
        'installed_at',
        'customized_config_json',
        'source_overrides_json',
        'competitor_overrides_json',
        'theme_overrides_json',
        'keyword_overrides_json',
        'alert_overrides_json',
        'scoring_overrides_json',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'installed_at' => 'datetime',
        'customized_config_json' => 'array',
        'source_overrides_json' => 'array',
        'competitor_overrides_json' => 'array',
        'theme_overrides_json' => 'array',
        'keyword_overrides_json' => 'array',
        'alert_overrides_json' => 'array',
        'scoring_overrides_json' => 'array',
        'metadata_json' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function marketPack(): BelongsTo
    {
        return $this->belongsTo(MarketPack::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }
}
