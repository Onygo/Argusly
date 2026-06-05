<?php

namespace App\Models;

use App\Enums\ContentAutomationRunStatus;
use App\Enums\ContentAutomationTriggerType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentAutomationRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'automation_id',
        'organization_id',
        'workspace_id',
        'client_site_id',
        'status',
        'triggered_by',
        'attempt_count',
        'last_attempt_at',
        'started_at',
        'finished_at',
        'result_summary',
        'error_message',
        'generated_draft_ids',
        'generated_content_ids',
        'published_content_ids',
        'metadata',
    ];

    protected $casts = [
        'status' => ContentAutomationRunStatus::class,
        'triggered_by' => ContentAutomationTriggerType::class,
        'attempt_count' => 'integer',
        'last_attempt_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'generated_draft_ids' => 'array',
        'generated_content_ids' => 'array',
        'published_content_ids' => 'array',
        'metadata' => 'array',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(ContentAutomation::class, 'automation_id');
    }

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
        return $this->belongsTo(ClientSite::class, 'client_site_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContentAutomationRunItem::class, 'automation_run_id')->orderBy('chain_index');
    }
}
