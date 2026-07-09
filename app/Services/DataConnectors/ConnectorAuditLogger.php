<?php

namespace App\Services\DataConnectors;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ConnectorAuditLogger
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        Model $subject,
        string $action,
        ?array $before = null,
        ?array $after = null,
        ?User $actor = null,
        ?Request $request = null,
    ): void {
        $this->audit->log(
            actor: $actor ?? Auth::user(),
            subject: $subject,
            action: $action,
            before: $this->sanitize($before),
            after: $this->sanitize($after),
            request: $request,
        );
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>|null
     */
    private function sanitize(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $sanitized = [];

        foreach ($payload as $key => $value) {
            $keyString = (string) $key;

            if (preg_match('/(secret|token|password|authorization|api[_-]?key|client[_-]?secret)/i', $keyString) === 1) {
                $sanitized[$keyString] = '[redacted]';

                continue;
            }

            $sanitized[$keyString] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $sanitized;
    }
}
