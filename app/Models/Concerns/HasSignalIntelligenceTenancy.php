<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\Workspace;
use App\Models\ClientSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait HasSignalIntelligenceTenancy
{
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
        return $this->belongsTo(ClientSite::class);
    }

    public function scopeForWorkspace(Builder $query, Workspace|string $workspace): Builder
    {
        $workspaceId = $workspace instanceof Workspace ? (string) $workspace->id : (string) $workspace;

        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeForOrganization(Builder $query, int|string $organizationId): Builder
    {
        return $query->where('organization_id', (int) $organizationId);
    }

    public function belongsToUserOrganization(?int $organizationId): bool
    {
        if (! $organizationId) {
            return false;
        }

        return (int) ($this->organization_id ?? $this->workspace?->organization_id ?? 0) === $organizationId;
    }
}
