<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Scopes\OrganizationScope;

/**
 * Apply this trait to models that have client_site_id -> client_site.workspace_id -> workspace.organization_id.
 */
trait BelongsToOrganizationViaClientSite
{
    public static function bootBelongsToOrganizationViaClientSite(): void
    {
        static::addGlobalScope(new OrganizationScope('client_site'));
    }
}
