<?php

namespace App\Http\Middleware;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Services\PermissionService;
use App\Support\Diagnostics\ForbiddenDiagnostics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasPermission
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly CurrentAccountContract $currentAccount,
        private readonly CurrentBrandContract $currentBrand,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user && $this->permissions->userHasGlobalAllPermissionsRole($user)) {
            return $next($request);
        }

        $context = $this->contextFromRequest($request);

        if (! $user || ! $this->permissions->userCan($user, $permission, $context)) {
            ForbiddenDiagnostics::log('permission_denied', $request, [
                'permission' => $permission,
                'context' => $context,
            ]);

            abort(403);
        }

        return $next($request);
    }

    /**
     * @return array{account_id?: int|null, brand_id?: int|null}
     */
    private function contextFromRequest(Request $request): array
    {
        return [
            'account_id' => $this->routeIdentifier($request, 'account', 'account_id') ?? $this->currentAccount->id($request->user()),
            'brand_id' => $this->routeIdentifier($request, 'brand', 'brand_id') ?? $this->currentBrand->id($request->user()),
        ];
    }

    private function routeIdentifier(Request $request, string $modelKey, string $scalarKey): ?int
    {
        $model = $request->route($modelKey);

        if (is_object($model) && isset($model->id)) {
            return (int) $model->id;
        }

        $value = $request->route($scalarKey);

        return $value === null ? null : (int) $value;
    }
}
