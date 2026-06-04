<?php

namespace App\Http\Middleware;

use App\Contracts\CurrentAccountContract;
use App\Services\PermissionService;
use App\Services\Subscriptions\ModuleAccessService;
use App\Support\Diagnostics\ForbiddenDiagnostics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleIsActive
{
    public function __construct(
        private readonly CurrentAccountContract $currentAccount,
        private readonly ModuleAccessService $modules,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$moduleKeys): Response
    {
        $user = $request->user();

        if ($user && $this->permissions->userHasGlobalAllPermissionsRole($user)) {
            return $next($request);
        }

        $account = $this->currentAccount->get($request->user());

        if (! $account || ! $this->modules->accountHasAnyModule($account, $moduleKeys)) {
            ForbiddenDiagnostics::log('module_inactive_or_missing_account', $request, [
                'requested_modules' => $moduleKeys,
                'resolved_account_id' => $account?->id,
            ]);

            abort(403);
        }

        return $next($request);
    }
}
