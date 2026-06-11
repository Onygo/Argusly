<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\SignalSourceType;
use App\Enums\SignalStatus;
use App\Models\Concerns\HasSignalIntelligenceTenancy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SignalSource extends Model
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
        'type',
        'name',
        'status',
        'config',
        'last_seen_at',
        'last_processed_at',
        'failure_count',
        'last_error',
        'created_by',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'type' => SignalSourceType::class,
        'status' => SignalStatus::class,
        'config' => 'array',
        'last_seen_at' => 'datetime',
        'last_processed_at' => 'datetime',
        'failure_count' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function feedItems(): HasMany
    {
        return $this->hasMany(SignalFeedItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SignalEvent::class);
    }

    public function processingRuns(): HasMany
    {
        return $this->hasMany(SignalProcessingRun::class);
    }

    public function isActive(): bool
    {
        return ! $this->status?->isTerminal();
    }
}
