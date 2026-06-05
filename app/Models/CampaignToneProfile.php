<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CampaignToneProfile extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'brand_voice_id',
        'name',
        'locale',
        'summary',
        'voice_attributes',
        'rules',
        'examples',
        'is_default',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'voice_attributes' => 'array',
        'rules' => 'array',
        'examples' => 'array',
        'is_default' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function brandVoice(): BelongsTo
    {
        return $this->belongsTo(BrandVoice::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'tone_profile_id');
    }
}
