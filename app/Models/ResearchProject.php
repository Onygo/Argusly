<?php

namespace App\Models;

use App\Concerns\BelongsToOrganizationViaWorkspace;
use App\Enums\ResearchProjectStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResearchProject extends Model
{
    use BelongsToOrganizationViaWorkspace;
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'created_by',
        'brief_id',
        'client_site_id',
        'name',
        'status',
        'target_keywords',
        'config',
        'summary',
        'human_summary',
        'started_at',
        'completed_at',
        'failed_at',
        'failure_reason',
    ];

    protected $casts = [
        'status' => ResearchProjectStatus::class,
        'target_keywords' => 'array',
        'config' => 'array',
        'summary' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ResearchSource::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(ResearchFinding::class);
    }

    public function isTerminal(): bool
    {
        return in_array((string) ($this->status?->value ?? $this->status), [
            ResearchProjectStatus::COMPLETED->value,
            ResearchProjectStatus::FAILED->value,
        ], true);
    }
}
