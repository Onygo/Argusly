<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Scopes\OrganizationScope;

/**
 * Apply this trait to models that have a direct organization_id column.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope('direct'));
    }
}
