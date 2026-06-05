<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Scopes\OrganizationScope;

/**
 * Apply this trait to models that have workspace_id -> workspace.organization_id.
 */
trait BelongsToOrganizationViaWorkspace
{
    public static function bootBelongsToOrganizationViaWorkspace(): void
    {
        static::addGlobalScope(new OrganizationScope('workspace'));
    }
}
