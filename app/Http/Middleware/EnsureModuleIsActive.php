<?php

namespace App\Http\Middleware;

use App\Contracts\CurrentAccountContract;
use App\Services\Subscriptions\ModuleAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleIsActive
{
    public function __construct(
        private readonly CurrentAccountContract $currentAccount,
        private readonly ModuleAccessService $modules,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$moduleKeys): Response
    {
        $account = $this->currentAccount->get($request->user());

        abort_unless(
            $account && $this->modules->accountHasAnyModule($account, $moduleKeys),
            403
        );

        return $next($request);
    }
}
