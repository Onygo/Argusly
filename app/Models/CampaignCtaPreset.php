<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CampaignCtaPreset extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'name',
        'intent',
        'label',
        'destination_url',
        'description',
        'rules',
        'metadata',
        'is_default',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'rules' => 'array',
        'metadata' => 'array',
        'is_default' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'cta_preset_id');
    }
}
