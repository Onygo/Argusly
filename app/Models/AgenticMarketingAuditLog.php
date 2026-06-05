<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgenticMarketingAuditLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'objective_id',
        'opportunity_id',
        'action_id',
        'run_id',
        'actor_id',
        'event',
        'subject_type',
        'subject_id',
        'before',
        'after',
        'metadata',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'metadata' => 'array',
    ];

    public function objective(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingObjective::class, 'objective_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingOpportunity::class, 'opportunity_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingAction::class, 'action_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgenticMarketingRun::class, 'run_id');
    }
}
