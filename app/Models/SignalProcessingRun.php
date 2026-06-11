<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\SignalStatus;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SignalProcessingRun extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasFactory;
    use HasSignalIntelligenceTenancy;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'client_site_id',
        'signal_source_id',
        'run_type',
        'status',
        'input',
        'result',
        'items_seen',
        'items_created',
        'signals_created',
        'detections_created',
        'started_at',
        'finished_at',
        'failure_reason',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'status' => SignalStatus::class,
        'input' => 'array',
        'result' => 'array',
        'items_seen' => 'integer',
        'items_created' => 'integer',
        'signals_created' => 'integer',
        'detections_created' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function signalSource(): BelongsTo
    {
        return $this->belongsTo(SignalSource::class);
    }

    public function hasFinished(): bool
    {
        return $this->finished_at !== null || $this->status?->isTerminal();
    }
}
