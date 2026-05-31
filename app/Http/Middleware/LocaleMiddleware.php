<?php

namespace App\Http\Middleware;

use App\Contracts\CurrentAccountContract;
use App\Models\User;
use App\Services\LanguageService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class LocaleMiddleware
{
    public function __construct(
        private readonly LanguageService $languages,
        private readonly CurrentAccountContract $currentAccount,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $account = $user instanceof User ? $this->currentAccount->get($user) : null;

        App::setLocale($this->languages->resolveUiLocale(
            $user instanceof User ? $user : null,
            $account,
        ));

        return $next($request);
    }
}
