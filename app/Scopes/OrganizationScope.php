<?php

declare(strict_types=1);

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Global scope that enforces tenant isolation by filtering queries to the
 * authenticated user's organization.
 *
 * Supports multiple relationship paths to organization:
 * - Direct: model has organization_id column
 * - Via workspace: model has workspace_id -> workspace.organization_id
 * - Via client_site: model has client_site_id -> client_site.workspace_id -> workspace.organization_id
 */
class OrganizationScope implements Scope
{
    /**
     * The relationship path to reach organization_id.
     *
     * Supported values:
     * - 'direct': model.organization_id
     * - 'workspace': model.workspace_id -> workspaces.organization_id
     * - 'client_site': model.client_site_id -> client_sites.workspace_id -> workspaces.organization_id
     */
    public function __construct(
        private readonly string $via = 'direct'
    ) {}

    public function apply(Builder $builder, Model $model): void
    {
        // Skip scope if no authenticated user
        $user = Auth::user();
        if (! $user) {
            return;
        }

        // Skip scope for admin users (they need full access)
        if ($user->is_admin) {
            return;
        }

        $organizationId = $user->organization_id;
        if (! $organizationId) {
            // User without organization should see nothing
            $builder->whereRaw('1 = 0');

            return;
        }

        match ($this->via) {
            'direct' => $this->applyDirectScope($builder, $organizationId),
            'workspace' => $this->applyWorkspaceScope($builder, $organizationId),
            'client_site' => $this->applyClientSiteScope($builder, $organizationId),
            default => $this->applyDirectScope($builder, $organizationId),
        };
    }

    private function applyDirectScope(Builder $builder, int $organizationId): void
    {
        $builder->where($builder->getModel()->getTable() . '.organization_id', $organizationId);
    }

    private function applyWorkspaceScope(Builder $builder, int $organizationId): void
    {
        $builder->whereHas('workspace', function (Builder $query) use ($organizationId): void {
            $query->where('workspaces.organization_id', $organizationId);
        });
    }

    private function applyClientSiteScope(Builder $builder, int $organizationId): void
    {
        $builder->whereHas('clientSite.workspace', function (Builder $query) use ($organizationId): void {
            $query->where('workspaces.organization_id', $organizationId);
        });
    }
}
