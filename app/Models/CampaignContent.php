<?php

namespace App\Models;

use App\Enums\CampaignApprovalStatus;
use App\Enums\CampaignContentAssetType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CampaignContent extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'campaign_id',
        'content_id',
        'source_content_id',
        'tone_profile_id',
        'cta_preset_id',
        'asset_type',
        'status',
        'approval_status',
        'sequence_order',
        'working_title',
        'target_locale',
        'scheduled_for',
        'submitted_for_approval_at',
        'approved_at',
        'approved_by',
        'brief',
        'channel_requirements',
        'ai_generation_context',
        'optimization_notes',
        'internal_linking_targets',
        'metadata',
    ];

    protected $casts = [
        'asset_type' => CampaignContentAssetType::class,
        'approval_status' => CampaignApprovalStatus::class,
        'sequence_order' => 'integer',
        'scheduled_for' => 'datetime',
        'submitted_for_approval_at' => 'datetime',
        'approved_at' => 'datetime',
        'brief' => 'array',
        'channel_requirements' => 'array',
        'ai_generation_context' => 'array',
        'optimization_notes' => 'array',
        'internal_linking_targets' => 'array',
        'metadata' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function sourceContent(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'source_content_id');
    }

    public function toneProfile(): BelongsTo
    {
        return $this->belongsTo(CampaignToneProfile::class, 'tone_profile_id');
    }

    public function ctaPreset(): BelongsTo
    {
        return $this->belongsTo(CampaignCtaPreset::class, 'cta_preset_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function distributionPlans(): HasMany
    {
        return $this->hasMany(CampaignDistributionPlan::class);
    }

    public function socialPostVariants(): HasMany
    {
        return $this->hasMany(SocialPostVariant::class);
    }

    public function emailCampaignExports(): HasMany
    {
        return $this->hasMany(EmailCampaignExport::class);
    }
}
