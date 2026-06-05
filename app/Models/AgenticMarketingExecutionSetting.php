<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgenticMarketingExecutionSetting extends Model
{
    use HasUuids;

    public const MODE_GUIDED = 'guided';
    public const MODE_AUTONOMOUS = 'autonomous';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'brand_voice_id',
        'agentic_execution_mode',
        'autonomous_publication_enabled',
        'autonomous_refresh_enabled',
        'autonomous_internal_linking_enabled',
        'autonomous_brief_generation_enabled',
        'autonomous_chained_plans_enabled',
        'max_autonomous_actions_per_day',
        'max_autonomous_credits_per_month',
        'require_approval_above_priority_score',
        'require_approval_for_new_pages',
        'require_approval_for_external_publication',
        'allowed_site_ids',
        'allowed_publishing_destination_ids',
        'notification_email_enabled',
        'last_autonomous_action_at',
        'updated_by',
    ];

    protected $attributes = [
        'agentic_execution_mode' => self::MODE_GUIDED,
        'autonomous_publication_enabled' => false,
        'autonomous_refresh_enabled' => false,
        'autonomous_internal_linking_enabled' => false,
        'autonomous_brief_generation_enabled' => false,
        'autonomous_chained_plans_enabled' => false,
        'max_autonomous_actions_per_day' => 3,
        'max_autonomous_credits_per_month' => 100,
        'require_approval_above_priority_score' => 80,
        'require_approval_for_new_pages' => true,
        'require_approval_for_external_publication' => true,
        'notification_email_enabled' => true,
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'autonomous_publication_enabled' => 'boolean',
        'autonomous_refresh_enabled' => 'boolean',
        'autonomous_internal_linking_enabled' => 'boolean',
        'autonomous_brief_generation_enabled' => 'boolean',
        'autonomous_chained_plans_enabled' => 'boolean',
        'max_autonomous_actions_per_day' => 'integer',
        'max_autonomous_credits_per_month' => 'integer',
        'require_approval_above_priority_score' => 'integer',
        'require_approval_for_new_pages' => 'boolean',
        'require_approval_for_external_publication' => 'boolean',
        'allowed_site_ids' => 'array',
        'allowed_publishing_destination_ids' => 'array',
        'notification_email_enabled' => 'boolean',
        'last_autonomous_action_at' => 'datetime',
        'updated_by' => 'integer',
    ];

    public static function modes(): array
    {
        return [self::MODE_GUIDED, self::MODE_AUTONOMOUS];
    }

    public static function defaultsFor(Workspace $workspace, ?BrandVoice $brandVoice = null): self
    {
        return new self([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => (string) $workspace->id,
            'brand_voice_id' => $brandVoice?->id,
        ]);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function brandVoice(): BelongsTo
    {
        return $this->belongsTo(BrandVoice::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isGuided(): bool
    {
        return $this->agentic_execution_mode !== self::MODE_AUTONOMOUS;
    }

    public function isAutonomous(): bool
    {
        return $this->agentic_execution_mode === self::MODE_AUTONOMOUS;
    }
}
