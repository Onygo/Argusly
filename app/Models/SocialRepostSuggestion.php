<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\SocialPlatform;
use App\Enums\SocialRepostSuggestionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SocialRepostSuggestion extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'social_publication_id',
        'campaign_id',
        'platform',
        'status',
        'suggested_for',
        'reason_code',
        'reason',
        'suggested_angle',
        'performance_snapshot',
        'created_variant_id',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'platform' => SocialPlatform::class,
        'status' => SocialRepostSuggestionStatus::class,
        'suggested_for' => 'datetime',
        'suggested_angle' => 'array',
        'performance_snapshot' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(SocialPublication::class, 'social_publication_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function createdVariant(): BelongsTo
    {
        return $this->belongsTo(SocialPostVariant::class, 'created_variant_id');
    }
}
