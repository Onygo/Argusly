<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingTheme extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'name',
        'description',
        'status',
        'priority',
        'market_pack_key',
        'metadata_json',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'metadata_json' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function objectives(): HasMany
    {
        return $this->hasMany(MarketingObjective::class);
    }

    public function initiatives(): HasMany
    {
        return $this->hasMany(MarketingInitiative::class);
    }
}
