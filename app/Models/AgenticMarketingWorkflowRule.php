<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgenticMarketingWorkflowRule extends Model
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
        'campaign_id',
        'name',
        'trigger_type',
        'status',
        'minimum_confidence_score',
        'maximum_actions_per_run',
        'generate_campaign_proposals',
        'generate_content_drafts',
        'schedule_distribution_drafts',
        'auto_queue_approved_actions',
        'requires_human_approval',
        'allowed_action_types',
        'signal_filters',
        'policy',
        'last_run_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'minimum_confidence_score' => 'integer',
        'maximum_actions_per_run' => 'integer',
        'generate_campaign_proposals' => 'boolean',
        'generate_content_drafts' => 'boolean',
        'schedule_distribution_drafts' => 'boolean',
        'auto_queue_approved_actions' => 'boolean',
        'requires_human_approval' => 'boolean',
        'allowed_action_types' => 'array',
        'signal_filters' => 'array',
        'policy' => 'array',
        'last_run_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public static function defaultFor(Workspace $workspace, string $triggerType = 'signal_monitor'): self
    {
        return new self([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => (string) $workspace->id,
            'name' => 'Default governed '.$triggerType.' workflow',
            'trigger_type' => $triggerType,
            'status' => self::STATUS_ACTIVE,
            'minimum_confidence_score' => 70,
            'maximum_actions_per_run' => 10,
            'generate_campaign_proposals' => true,
            'generate_content_drafts' => true,
            'schedule_distribution_drafts' => true,
            'auto_queue_approved_actions' => false,
            'requires_human_approval' => true,
            'allowed_action_types' => [],
            'signal_filters' => [],
            'policy' => ['never_auto_publish_by_default' => true],
        ]);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
