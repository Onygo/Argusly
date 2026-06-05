<?php

namespace App\Domain\AccessOverrides;

use App\Enums\AccessOverrideStatus;
use App\Enums\AccessOverrideType;
use App\Models\AccessOverride;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccessOverrideManager
{
    public function __construct(
        private readonly AccessOverrideResolver $resolver,
        private readonly AuditLogService $auditLogs,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createForUser(
        User $targetUser,
        array $payload,
        ?User $actor = null,
        ?Request $request = null,
    ): AccessOverride {
        return DB::transaction(function () use ($targetUser, $payload, $actor, $request): AccessOverride {
            $this->expireDueOverridesForUser($targetUser);

            $existing = AccessOverride::query()
                ->forUser($targetUser)
                ->open()
                ->lockForUpdate()
                ->latest('created_at')
                ->first();

            if ($existing) {
                $this->throwValidationError('An open access override already exists for this user. Stop or extend it first.');
            }

            $override = AccessOverride::query()->create($this->buildPayload($payload, $targetUser, $actor));

            $this->auditLogs->log(
                actor: $actor,
                subject: $override,
                action: 'access_override.created',
                before: null,
                after: $this->auditPayload($override),
                request: $request
            );

            return $override;
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function extendForUser(
        User $targetUser,
        AccessOverride $currentOverride,
        array $payload,
        ?User $actor = null,
        ?Request $request = null,
    ): AccessOverride {
        return DB::transaction(function () use ($targetUser, $currentOverride, $payload, $actor, $request): AccessOverride {
            $this->expireDueOverridesForUser($targetUser);

            $locked = AccessOverride::query()
                ->forUser($targetUser)
                ->whereKey($currentOverride->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->effectiveStatus()->isOpen()) {
                $this->throwValidationError('Only active or scheduled overrides can be extended.');
            }

            $replacement = AccessOverride::query()->create($this->buildPayload(
                payload: array_merge($payload, [
                    'metadata' => array_filter([
                        'extended_from_access_override_id' => (string) $locked->id,
                    ]),
                ]),
                targetUser: $targetUser,
                actor: $actor
            ));

            $before = $this->auditPayload($locked);
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $metadata['superseded_by_access_override_id'] = (string) $replacement->id;

            $locked->status = AccessOverrideStatus::CANCELLED;
            $locked->ended_at = now();
            $locked->ended_by_user_id = $actor?->id;
            $locked->metadata = $metadata;
            $locked->save();

            $this->auditLogs->log(
                actor: $actor,
                subject: $locked,
                action: 'access_override.extended',
                before: $before,
                after: array_merge($this->auditPayload($locked), [
                    'replacement_id' => (string) $replacement->id,
                ]),
                request: $request
            );

            $this->auditLogs->log(
                actor: $actor,
                subject: $replacement,
                action: 'access_override.created_from_extension',
                before: null,
                after: $this->auditPayload($replacement),
                request: $request
            );

            return $replacement;
        });
    }

    public function cancel(
        AccessOverride $override,
        ?User $actor = null,
        ?Request $request = null,
    ): AccessOverride {
        return DB::transaction(function () use ($override, $actor, $request): AccessOverride {
            $locked = AccessOverride::query()
                ->whereKey($override->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->effectiveStatus()->isOpen()) {
                $this->throwValidationError('Only active or scheduled overrides can be cancelled.');
            }

            $before = $this->auditPayload($locked);
            $locked->status = AccessOverrideStatus::CANCELLED;
            $locked->ended_at = now();
            $locked->ended_by_user_id = $actor?->id;
            $locked->save();

            $this->auditLogs->log(
                actor: $actor,
                subject: $locked,
                action: 'access_override.cancelled',
                before: $before,
                after: $this->auditPayload($locked),
                request: $request
            );

            return $locked;
        });
    }

    private function expireDueOverridesForUser(User $user): void
    {
        AccessOverride::query()
            ->forUser($user)
            ->open()
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->lockForUpdate()
            ->get()
            ->each(function (AccessOverride $override): void {
                $override->status = AccessOverrideStatus::EXPIRED;
                $override->ended_at = $override->ended_at ?? $override->ends_at ?? now();
                $override->save();
            });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildPayload(array $payload, User $targetUser, ?User $actor): array
    {
        $startsAt = ! empty($payload['starts_at']) ? Carbon::parse((string) $payload['starts_at']) : now();
        $endsAt = ! empty($payload['ends_at']) ? Carbon::parse((string) $payload['ends_at']) : null;
        $status = $startsAt->isFuture()
            ? AccessOverrideStatus::SCHEDULED
            : AccessOverrideStatus::ACTIVE;

        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        return [
            'id' => (string) Str::uuid(),
            'user_id' => $targetUser->id,
            'workspace_id' => null,
            'type' => AccessOverrideType::from((string) $payload['type']),
            'status' => $status,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'reason' => $this->nullableText($payload['reason'] ?? null),
            'notes' => $this->nullableText($payload['notes'] ?? null),
            'created_by_user_id' => $actor?->id,
            'ended_by_user_id' => null,
            'ended_at' => null,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(AccessOverride $override): array
    {
        return [
            'type' => $override->type?->value,
            'status' => $override->status?->value,
            'effective_status' => $override->effectiveStatus()->value,
            'user_id' => $override->user_id,
            'workspace_id' => (string) ($override->workspace_id ?? ''),
            'starts_at' => $override->starts_at?->toIso8601String(),
            'ends_at' => $override->ends_at?->toIso8601String(),
            'ended_at' => $override->ended_at?->toIso8601String(),
            'created_by_user_id' => $override->created_by_user_id,
            'ended_by_user_id' => $override->ended_by_user_id,
            'reason' => (string) ($override->reason ?? ''),
            'notes' => (string) ($override->notes ?? ''),
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        $clean = trim((string) $value);

        return $clean !== '' ? $clean : null;
    }

    private function throwValidationError(string $message): never
    {
        throw ValidationException::withMessages([
            'access_override' => $message,
        ]);
    }
}
