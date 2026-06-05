<?php

namespace App\Http\Middleware;

use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBillingOnboardingCompleted
{
    /**
     * @var array<int,string>
     */
    private const ALLOWED_ROUTE_NAMES = [
        'app.onboarding.show',
        'app.onboarding.intent',
        'app.onboarding.company-profile',
        'app.onboarding.connect-site',
        'app.onboarding.company.show',
        'app.onboarding.company.update',
        'app.onboarding.scan.store',
        'app.onboarding.scan.show',
        'app.onboarding.scan.confirm',
        'app.onboarding.scan.latest',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_admin) {
            return $next($request);
        }

        $organization = $user->organization;
        if (! $organization) {
            return $next($request);
        }

        if (app(SubscriptionService::class)->allowsBillingBypassForUser($user)) {
            return $next($request);
        }

        $routeName = (string) optional($request->route())->getName();
        $isBillingRoute = str_starts_with($routeName, 'app.billing.');
        if ($isBillingRoute || in_array($routeName, self::ALLOWED_ROUTE_NAMES, true)) {
            return $next($request);
        }

        if (! $organization->hasCompleteBillingDetails()) {
            $message = 'Vul je bedrijfsgegevens in om te kunnen starten met PublishLayer en om facturen correct te maken.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'redirect' => route('app.onboarding.company.show'),
                ], 423);
            }

            if ($request->isMethod('GET')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect()
                ->route('app.onboarding.company.show')
                ->with('status', $message);
        }

        if (app(SubscriptionService::class)->hasBillingAccessForUser($user)) {
            return $next($request);
        }

        $message = 'Complete billing onboarding by starting your subscription before using the app.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'redirect' => route('app.billing.index'),
            ], 423);
        }

        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()
            ->route('app.billing.index')
            ->with('status', $message);
    }
}
