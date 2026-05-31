<?php

namespace App\Http\Middleware;

use App\Contracts\CurrentAccountContract;
use App\Contracts\CurrentBrandContract;
use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
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
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless(
            $user && $this->permissions->userHasRole($user, $roles, $this->contextFromRequest($request)),
            403
        );

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
