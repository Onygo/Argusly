<?php

namespace App\Support\Diagnostics;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ForbiddenDiagnostics
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public static function log(string $reason, Request $request, array $extra = []): void
    {
        $context = array_merge([
            'reason' => $reason,
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'route_name' => $request->route()?->getName(),
            'route_uri' => $request->route()?->uri(),
            'session_account_id' => self::sessionValue($request, 'tenant.current_account_id'),
            'session_brand_id' => self::sessionValue($request, 'tenant.current_brand_id'),
            'user' => self::safeUserContext($request->user()),
        ], $extra);

        try {
            Log::warning('argusly.forbidden', $context);
        } catch (Throwable) {
            // Direct file logging below is intentionally independent from Laravel's log channel.
        }

        self::writeDirect('argusly.forbidden', $context);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function logException(string $reason, Request $request, Throwable $exception, array $extra = []): void
    {
        $context = array_merge([
            'reason' => $reason,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'route_name' => $request->route()?->getName(),
            'route_uri' => $request->route()?->uri(),
            'session_account_id' => self::sessionValue($request, 'tenant.current_account_id'),
            'session_brand_id' => self::sessionValue($request, 'tenant.current_brand_id'),
            'user' => self::safeUserContext($request->user()),
            'trace' => collect($exception->getTrace())->take(8)->map(fn (array $frame) => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
            ])->all(),
        ], $extra);

        try {
            Log::error('argusly.exception', $context);
        } catch (Throwable) {
            // Direct file logging below is intentionally independent from Laravel's log channel.
        }

        self::writeDirect('argusly.exception', $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function writeDirect(string $event, array $context): void
    {
        try {
            $line = json_encode([
                'timestamp' => now()->toIso8601String(),
                'event' => $event,
                'context' => $context,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            if ($line === false) {
                return;
            }

            @file_put_contents(storage_path('logs/argusly-diagnostics.log'), $line.PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            //
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function safeUserContext(mixed $user): ?array
    {
        try {
            return self::userContext($user);
        } catch (Throwable $exception) {
            return [
                'diagnostics_error' => $exception->getMessage(),
            ];
        }
    }

    private static function sessionValue(Request $request, string $key): mixed
    {
        try {
            return $request->hasSession() ? $request->session()->get($key) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function userContext(mixed $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => $user->id,
            'email' => $user->email,
            'roles' => self::roles($user),
            'memberships' => self::memberships($user),
            'brand_memberships' => self::brandMemberships($user),
            'active_modules' => self::activeModules($user),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function roles(User $user): array
    {
        if (! Schema::hasTable('user_roles') || ! Schema::hasTable('roles')) {
            return [];
        }

        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $user->id)
            ->orderBy('roles.priority')
            ->get([
                'roles.name',
                'roles.all_permissions',
                'user_roles.account_id',
                'user_roles.brand_id',
                'user_roles.starts_at',
                'user_roles.expires_at',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function memberships(User $user): array
    {
        if (! Schema::hasTable('memberships')) {
            return [];
        }

        return DB::table('memberships')
            ->where('user_id', $user->id)
            ->get(['account_id', 'status', 'joined_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function brandMemberships(User $user): array
    {
        if (! Schema::hasTable('brand_memberships')) {
            return [];
        }

        return DB::table('brand_memberships')
            ->where('user_id', $user->id)
            ->get(['account_id', 'brand_id', 'status', 'joined_at'])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function activeModules(User $user): array
    {
        if (! Schema::hasTable('memberships') || ! Schema::hasTable('subscription_modules') || ! Schema::hasTable('modules')) {
            return [];
        }

        return DB::table('subscription_modules')
            ->join('modules', 'modules.id', '=', 'subscription_modules.module_id')
            ->whereIn('subscription_modules.account_id', DB::table('memberships')
                ->select('account_id')
                ->where('user_id', $user->id)
                ->where('status', 'active'))
            ->where('subscription_modules.status', 'active')
            ->get([
                'subscription_modules.account_id',
                'modules.key',
                'modules.is_active',
                'subscription_modules.starts_at',
                'subscription_modules.ends_at',
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }
}
