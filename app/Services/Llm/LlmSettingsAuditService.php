<?php

namespace App\Services\Llm;

use App\Models\LlmSettingsAuditLog;
use Illuminate\Support\Str;

class LlmSettingsAuditService
{
    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public function log(
        ?int $actorUserId,
        string $scopeType,
        ?string $scopeId,
        string $action,
        ?array $before,
        ?array $after
    ): void {
        LlmSettingsAuditLog::query()->create([
            'id' => (string) Str::uuid(),
            'actor_user_id' => $actorUserId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
