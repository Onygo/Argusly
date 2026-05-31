<?php

namespace App\Http\Middleware;

use App\Contracts\CurrentAccountContract;
use App\Models\Account;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentAccount
{
    public function __construct(private readonly CurrentAccountContract $currentAccount) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $account = $this->accountFromRequest($request);

        if ($account) {
            $this->currentAccount->set($account, $user);
        } else {
            $this->currentAccount->get($user);
        }

        return $next($request);
    }

    private function accountFromRequest(Request $request): Account|int|null
    {
        $account = $request->route('account');

        if ($account instanceof Account) {
            return $account;
        }

        if ($account !== null) {
            return (int) $account;
        }

        $accountId = $request->route('account_id') ?? $request->input('account_id');

        return $accountId === null ? null : (int) $accountId;
    }
}
