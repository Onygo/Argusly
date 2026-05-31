<?php

namespace App\Models\Concerns;

use App\Models\Account;
use App\Models\Brand;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;

trait LogActivity
{
    /**
     * @param  array<string, mixed>|null  $properties
     */
    protected function logActivity(
        string $event,
        string $description,
        ?Account $account = null,
        ?Brand $brand = null,
        ?User $user = null,
        ?array $properties = null,
    ): void {
        app(ActivityLogger::class)->log(
            event: $event,
            description: $description,
            account: $account,
            brand: $brand,
            user: $user,
            subject: $this instanceof Model ? $this : null,
            properties: $properties,
        );
    }
}
