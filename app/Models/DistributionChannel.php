<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\DistributionChannelType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DistributionChannel extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'content_destination_id',
        'name',
        'type',
        'provider',
        'status',
        'environment',
        'capabilities',
        'planning_rules',
        'credentials_ref',
        'metadata',
        'last_checked_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'type' => DistributionChannelType::class,
        'capabilities' => 'array',
        'planning_rules' => 'array',
        'credentials_ref' => 'array',
        'metadata' => 'array',
        'last_checked_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function contentDestination(): BelongsTo
    {
        return $this->belongsTo(ContentDestination::class);
    }

    public function distributionPlans(): HasMany
    {
        return $this->hasMany(CampaignDistributionPlan::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function isLinkedIn(): bool
    {
        return $this->type === DistributionChannelType::LINKEDIN;
    }
}
