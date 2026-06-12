<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrowthProgramBetaEvent extends Model
{
    use HasFactory;
    use HasUuids;

    public const TYPE_FEEDBACK = 'feedback';
    public const TYPE_WORKFLOW_ABANDONED = 'workflow_abandoned';
    public const TYPE_BACK_NAVIGATION = 'back_navigation';
    public const TYPE_ACTION_FAILED = 'action_failed';
    public const TYPE_CONFLICT = 'conflict';
    public const TYPE_BLOCKED = 'blocked';
    public const TYPE_CANCEL = 'cancel';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'growth_program_id',
        'user_id',
        'event_type',
        'step',
        'clarity',
        'message',
        'metadata',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'metadata' => 'array',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(GrowthProgram::class, 'growth_program_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
