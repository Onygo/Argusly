<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class NotificationService
{
    public function notifyWorkspace(
        string $workspaceId,
        string $type,
        string $title,
        ?string $body = null,
        array $options = []
    ): Notification {
        $workspace = Workspace::query()->findOrFail($workspaceId);
        $this->assertTypeIsAllowed($type);

        $attributes = [
            'workspace_id' => (string) $workspace->id,
            'target_scope' => Notification::TARGET_SCOPE_WORKSPACE,
            'is_admin_only' => false,
            'user_id' => null,
            'type' => $type,
            'title' => trim($title),
            'body' => $body !== null ? trim($body) : null,
            'cta_label' => $this->nullableTrim($options['cta_label'] ?? null),
            'cta_url' => $this->nullableTrim($options['cta_url'] ?? null),
            'priority' => isset($options['priority']) ? (int) $options['priority'] : Notification::defaultPriorityForType($type),
            'created_by_admin_id' => isset($options['created_by_admin_id']) ? (int) $options['created_by_admin_id'] : null,
            'dedupe_key' => $this->resolveDedupeKey($options),
            'meta' => $this->resolveMeta($options),
        ];
        $attributes['dedupe_scope'] = $this->resolveDedupeScope($attributes);

        if ($existing = $this->resolveDeduped($attributes, $options)) {
            return $existing;
        }

        return $this->createNotification($attributes, $options);
    }

    public function notifyUser(
        int $userId,
        string $workspaceId,
        string $type,
        string $title,
        ?string $body = null,
        array $options = []
    ): Notification {
        $workspace = Workspace::query()->findOrFail($workspaceId);
        $user = User::query()->findOrFail($userId);
        $this->assertTypeIsAllowed($type);

        if ((int) ($user->organization_id ?? 0) !== (int) ($workspace->organization_id ?? 0)) {
            throw new InvalidArgumentException('User does not belong to workspace organization.');
        }

        $attributes = [
            'workspace_id' => (string) $workspace->id,
            'target_scope' => Notification::TARGET_SCOPE_WORKSPACE,
            'is_admin_only' => false,
            'user_id' => (int) $user->id,
            'type' => $type,
            'title' => trim($title),
            'body' => $body !== null ? trim($body) : null,
            'cta_label' => $this->nullableTrim($options['cta_label'] ?? null),
            'cta_url' => $this->nullableTrim($options['cta_url'] ?? null),
            'priority' => isset($options['priority']) ? (int) $options['priority'] : Notification::defaultPriorityForType($type),
            'created_by_admin_id' => isset($options['created_by_admin_id']) ? (int) $options['created_by_admin_id'] : null,
            'dedupe_key' => $this->resolveDedupeKey($options),
            'meta' => $this->resolveMeta($options),
        ];
        $attributes['dedupe_scope'] = $this->resolveDedupeScope($attributes);

        if ($existing = $this->resolveDeduped($attributes, $options)) {
            return $existing;
        }

        return $this->createNotification($attributes, $options);
    }

    public function notifyAdmin(
        string $type,
        string $title,
        ?string $body = null,
        array $options = []
    ): Notification {
        $this->assertTypeIsAllowed($type);

        if (! in_array($type, [Notification::TYPE_ACTION_REQUIRED, Notification::TYPE_SYSTEM], true)) {
            throw new InvalidArgumentException('Admin notifications only support action_required and system.');
        }

        $workspaceId = $this->nullableTrim($options['workspace_id'] ?? null);
        if ($workspaceId !== null) {
            Workspace::query()->findOrFail($workspaceId);
        }

        $userId = isset($options['user_id']) ? (int) $options['user_id'] : null;
        if ($userId !== null) {
            $adminTarget = User::query()->findOrFail($userId);
            if (! $adminTarget->isAdminAreaUser()) {
                throw new InvalidArgumentException('Target user is not an admin user.');
            }
        }

        $attributes = [
            'workspace_id' => $workspaceId,
            'target_scope' => Notification::TARGET_SCOPE_ADMIN,
            'is_admin_only' => true,
            'user_id' => $userId,
            'type' => $type,
            'title' => trim($title),
            'body' => $body !== null ? trim($body) : null,
            'cta_label' => $this->nullableTrim($options['cta_label'] ?? null),
            'cta_url' => $this->nullableTrim($options['cta_url'] ?? null),
            'priority' => isset($options['priority']) ? (int) $options['priority'] : Notification::defaultPriorityForType($type),
            'created_by_admin_id' => isset($options['created_by_admin_id']) ? (int) $options['created_by_admin_id'] : null,
            'dedupe_key' => $this->resolveDedupeKey($options),
            'meta' => $this->resolveMeta($options),
        ];
        $attributes['dedupe_scope'] = $this->resolveDedupeScope($attributes);

        if ($existing = $this->resolveDeduped($attributes, $options)) {
            return $existing;
        }

        return $this->createNotification($attributes, $options);
    }

    public function markRead(string $notificationId, User $actor): Notification
    {
        $notification = Notification::query()->with('workspace')->findOrFail($notificationId);
        Gate::forUser($actor)->authorize('update', $notification);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification;
    }

    public function markAllRead(string $workspaceId, User $actor): int
    {
        $this->assertActorCanAccessWorkspace($actor, $workspaceId);

        return $this->visibleQueryForUser($actor, $workspaceId)
            ->unread()
            ->update(['read_at' => now()]);
    }

    public function unreadCount(string $workspaceId, User $actor): int
    {
        $this->assertActorCanAccessWorkspace($actor, $workspaceId);

        return (int) $this->visibleQueryForUser($actor, $workspaceId)
            ->unread()
            ->count();
    }

    public function markAdminRead(string $notificationId, User $actor): Notification
    {
        $notification = Notification::query()->findOrFail($notificationId);

        if (! $actor->isAdminAreaUser() || $notification->target_scope !== Notification::TARGET_SCOPE_ADMIN || ! $notification->is_admin_only) {
            throw new AuthorizationException('Admin notification access denied.');
        }

        if ($notification->user_id !== null && (int) $notification->user_id !== (int) $actor->id) {
            throw new AuthorizationException('Admin notification access denied.');
        }

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification;
    }

    public function markAllAdminRead(User $actor): int
    {
        if (! $actor->isAdminAreaUser()) {
            throw new AuthorizationException('Admin notification access denied.');
        }

        return $this->adminVisibleQueryForUser($actor)
            ->unread()
            ->update(['read_at' => now()]);
    }

    public function unreadAdminCount(User $actor): int
    {
        if (! $actor->isAdminAreaUser()) {
            throw new AuthorizationException('Admin notification access denied.');
        }

        return (int) $this->adminVisibleQueryForUser($actor)
            ->unread()
            ->count();
    }

    /**
     * @return array{workspace_id:?string, unread_count:int, recent:Collection<int, Notification>}
     */
    public function appBellDataForUser(User $actor, ?string $workspaceId = null, int $limit = 10): array
    {
        $notificationBell = [
            'workspace_id' => null,
            'unread_count' => 0,
            'recent' => collect(),
        ];

        if ($actor->is_admin) {
            return $notificationBell;
        }

        $workspaceIds = $this->resolveWorkspaceIdsForActor($actor, $workspaceId);
        if ($workspaceIds === []) {
            return $notificationBell;
        }

        $activeWorkspaceId = (string) $workspaceIds[0];
        $unreadQuery = $this->visibleQueryForUser($actor, $activeWorkspaceId)->unread();

        $notificationBell['workspace_id'] = $activeWorkspaceId;
        $notificationBell['unread_count'] = (int) (clone $unreadQuery)->count();
        $notificationBell['recent'] = (clone $unreadQuery)
            ->select($this->bellSelectColumns())
            ->orderedForBell()
            ->limit(max(1, $limit))
            ->get();

        return $notificationBell;
    }

    /**
     * @return array{unread_count:int, recent:Collection<int, Notification>}
     */
    public function adminBellDataForUser(User $actor, int $limit = 10): array
    {
        if (! $actor->isAdminAreaUser()) {
            throw new AuthorizationException('Admin notification access denied.');
        }

        $notificationBell = [
            'unread_count' => 0,
            'recent' => collect(),
        ];

        $unreadQuery = $this->adminVisibleQueryForUser($actor)->unread();

        $notificationBell['unread_count'] = (int) (clone $unreadQuery)->count();
        $notificationBell['recent'] = (clone $unreadQuery)
            ->select([
                'id',
                'workspace_id',
                'type',
                'title',
                'body',
                'cta_label',
                'cta_url',
                'read_at',
                'created_at',
                'meta',
            ])
            ->orderedForBell()
            ->limit(max(1, $limit))
            ->get();

        return $notificationBell;
    }

    public function visibleQueryForUser(User $actor, ?string $workspaceId = null): Builder
    {
        if ($actor->is_admin) {
            return Notification::query()->whereRaw('1 = 0');
        }

        $workspaceIds = $this->resolveWorkspaceIdsForActor($actor, $workspaceId);

        return Notification::query()
            ->workspaceVisibleTo($actor)
            ->forWorkspace($workspaceIds);
    }

    public function adminVisibleQueryForUser(User $actor): Builder
    {
        return Notification::query()->adminVisibleTo($actor);
    }

    /**
     * @return array<int,string>
     */
    public function resolveWorkspaceIdsForActor(User $actor, ?string $workspaceId = null): array
    {
        if ($actor->is_admin) {
            return [];
        }

        if ($workspaceId !== null && trim($workspaceId) !== '') {
            $resolvedWorkspaceId = trim($workspaceId);
            $this->assertActorCanAccessWorkspace($actor, $resolvedWorkspaceId);

            return [$resolvedWorkspaceId];
        }

        return Workspace::query()
            ->where('organization_id', (int) $actor->organization_id)
            ->orderBy('created_at')
            ->pluck('id')
            ->all();
    }

    private function assertActorCanAccessWorkspace(User $actor, string $workspaceId): void
    {
        $workspace = Workspace::query()
            ->whereKey($workspaceId)
            ->where('organization_id', (int) $actor->organization_id)
            ->first();

        if (! $workspace) {
            throw new AuthorizationException('Workspace access denied.');
        }
    }

    private function assertTypeIsAllowed(string $type): void
    {
        if (! in_array($type, Notification::allowedTypes(), true)) {
            throw new InvalidArgumentException('Unsupported notification type.');
        }
    }

    /**
     * @return array<int, string>
     */
    private function bellSelectColumns(): array
    {
        return [
            'id',
            'workspace_id',
            'user_id',
            'type',
            'title',
            'body',
            'cta_label',
            'cta_url',
            'priority',
            'read_at',
            'created_at',
        ];
    }

    /**
     * @param array<string,mixed> $options
     */
    private function resolveMeta(array $options): ?array
    {
        $meta = is_array($options['meta'] ?? null) ? $options['meta'] : [];

        $dedupeKey = (string) ($this->resolveDedupeKey($options) ?? '');
        if ($dedupeKey !== '') {
            $meta['dedupe_key'] = $dedupeKey;
        }

        return $meta !== [] ? $meta : null;
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $options
     */
    private function resolveDeduped(array $attributes, array $options): ?Notification
    {
        $dedupeKey = $this->resolveDedupeKey($options);
        if ($dedupeKey === null) {
            return null;
        }

        $query = Notification::query()
            ->where('target_scope', $attributes['target_scope'] ?? Notification::TARGET_SCOPE_WORKSPACE)
            ->where('is_admin_only', (bool) ($attributes['is_admin_only'] ?? false))
            ->where('type', $attributes['type'])
            ->where(function (Builder $nested) use ($dedupeKey): void {
                $nested->where('dedupe_key', $dedupeKey)
                    ->orWhere('meta->dedupe_key', $dedupeKey);
            });

        if (($attributes['workspace_id'] ?? null) === null) {
            $query->whereNull('workspace_id');
        } else {
            $query->where('workspace_id', $attributes['workspace_id']);
        }

        if ($attributes['user_id'] === null) {
            $query->whereNull('user_id');
        } else {
            $query->where('user_id', $attributes['user_id']);
        }

        return $query->first();
    }

    /**
     * @param array<string,mixed> $attributes
     * @param array<string,mixed> $options
     */
    private function createNotification(array $attributes, array $options): Notification
    {
        try {
            return Notification::query()->create($attributes);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = $this->resolveDeduped($attributes, $options);
            if ($existing instanceof Notification) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * @param array<string,mixed> $options
     */
    private function resolveDedupeKey(array $options): ?string
    {
        $dedupeKey = trim((string) ($options['dedupe_key'] ?? ''));

        return $dedupeKey !== '' ? mb_substr($dedupeKey, 0, 255) : null;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    private function resolveDedupeScope(array $attributes): ?string
    {
        if (blank($attributes['dedupe_key'] ?? null)) {
            return null;
        }

        return hash('sha256', implode('|', [
            (string) ($attributes['target_scope'] ?? Notification::TARGET_SCOPE_WORKSPACE),
            (bool) ($attributes['is_admin_only'] ?? false) ? 'admin_only' : 'visible',
            (string) ($attributes['workspace_id'] ?? 'global'),
            (string) ($attributes['user_id'] ?? 'workspace'),
            (string) ($attributes['type'] ?? 'system'),
        ]));
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['19', '1062'], true);
    }

    private function nullableTrim(mixed $value): ?string
    {
        $resolved = trim((string) $value);

        return $resolved !== '' ? $resolved : null;
    }
}
