<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;
    use HasUuids;

    public const TYPE_ACTION_REQUIRED = 'action_required';
    public const TYPE_SYSTEM = 'system';
    public const TYPE_ANNOUNCEMENT = 'announcement';
    public const TARGET_SCOPE_WORKSPACE = 'workspace';
    public const TARGET_SCOPE_ADMIN = 'admin';

    public const PRIORITY_ACTION_REQUIRED = 100;
    public const PRIORITY_ANNOUNCEMENT = 70;
    public const PRIORITY_SYSTEM = 50;

    protected $fillable = [
        'workspace_id',
        'target_scope',
        'is_admin_only',
        'user_id',
        'type',
        'title',
        'body',
        'cta_label',
        'cta_url',
        'priority',
        'read_at',
        'created_by_admin_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'read_at' => 'datetime',
        'priority' => 'integer',
        'is_admin_only' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $notification): void {
            if ((int) ($notification->priority ?? 0) <= 0) {
                $notification->priority = self::defaultPriorityForType((string) $notification->type);
            }
        });
    }

    public static function allowedTypes(): array
    {
        return [
            self::TYPE_ACTION_REQUIRED,
            self::TYPE_SYSTEM,
            self::TYPE_ANNOUNCEMENT,
        ];
    }

    public static function allowedTargetScopes(): array
    {
        return [
            self::TARGET_SCOPE_WORKSPACE,
            self::TARGET_SCOPE_ADMIN,
        ];
    }

    public static function defaultPriorityForType(string $type): int
    {
        return match ($type) {
            self::TYPE_ACTION_REQUIRED => self::PRIORITY_ACTION_REQUIRED,
            self::TYPE_ANNOUNCEMENT => self::PRIORITY_ANNOUNCEMENT,
            default => self::PRIORITY_SYSTEM,
        };
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function createdByAdmin()
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function scopeForWorkspace(Builder $query, string|array $workspaceId): Builder
    {
        if (is_array($workspaceId)) {
            if ($workspaceId === []) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereIn('workspace_id', $workspaceId);
        }

        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeWorkspaceScoped(Builder $query): Builder
    {
        return $query
            ->where('target_scope', self::TARGET_SCOPE_WORKSPACE)
            ->where('is_admin_only', false)
            ->whereNotNull('workspace_id');
    }

    public function scopeAdminScoped(Builder $query): Builder
    {
        return $query
            ->where('target_scope', self::TARGET_SCOPE_ADMIN)
            ->where('is_admin_only', true);
    }

    public function scopeForUserOrWorkspaceWide(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $nested) use ($user): void {
            $nested->whereNull('user_id')
                ->orWhere('user_id', $user->id);
        });
    }

    public function scopeWorkspaceVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->is_admin || (int) ($user->organization_id ?? 0) <= 0) {
            return $query->whereRaw('1 = 0');
        }

        $workspaceIds = Workspace::query()
            ->where('organization_id', (int) $user->organization_id)
            ->pluck('id')
            ->all();

        return $query
            ->workspaceScoped()
            ->forWorkspace($workspaceIds)
            ->forUserOrWorkspaceWide($user);
    }

    public function scopeAdminVisibleTo(Builder $query, User $adminUser): Builder
    {
        if (! $adminUser->isAdminAreaUser()) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->adminScoped()
            ->where(function (Builder $nested) use ($adminUser): void {
                $nested->whereNull('user_id')
                    ->orWhere('user_id', $adminUser->id);
            });
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        $resolved = trim((string) $type);
        if ($resolved === '') {
            return $query;
        }

        return $query->where('type', $resolved);
    }

    public function scopeRecent(Builder $query, int $limit = 10): Builder
    {
        return $query->latest('created_at')->limit(max(1, $limit));
    }

    public function scopeOrderedForBell(Builder $query): Builder
    {
        return $query
            ->orderByRaw("
                case type
                    when 'action_required' then 1
                    when 'announcement' then 2
                    when 'system' then 3
                    else 4
                end asc
            ")
            ->orderByDesc('created_at');
    }
}
