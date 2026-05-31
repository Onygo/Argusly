<?php

namespace App\Services;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ActivityLogger
{
    public function __construct(private readonly AuthFactory $auth) {}

    /**
     * @param  array<string, mixed>|null  $properties
     */
    public function log(
        string $event,
        string $description,
        ?Account $account = null,
        ?Brand $brand = null,
        ?User $user = null,
        ?Model $subject = null,
        ?array $properties = null,
        ?Request $request = null,
    ): ?ActivityLog {
        if (! Schema::hasTable('activity_logs')) {
            return null;
        }

        $user ??= $this->auth->guard()->user();
        $user = $user instanceof User ? $user : null;
        $account ??= $brand?->account;
        $request ??= app()->bound('request') ? request() : null;

        return ActivityLog::query()->create([
            'uuid' => (string) Str::uuid(),
            'account_id' => $account?->id,
            'brand_id' => $brand?->id,
            'user_id' => $user?->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'event' => $event,
            'description' => $description,
            'properties' => $properties,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
