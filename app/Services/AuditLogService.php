<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogService
{
    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public function log(
        ?User $actor,
        Model $subject,
        string $action,
        ?array $before = null,
        ?array $after = null,
        ?Request $request = null
    ): void {
        AuditLog::query()->create([
            'id' => (string) Str::uuid(),
            'actor_type' => $actor ? $actor::class : null,
            'actor_id' => $actor ? (string) $actor->getKey() : null,
            'subject_type' => $subject::class,
            'subject_id' => (string) $subject->getKey(),
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }
}

