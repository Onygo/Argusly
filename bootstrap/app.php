<?php

use App\Http\Middleware\AdminKeyMiddleware;
use App\Http\Middleware\BlockSuspiciousTraffic;
use App\Http\Middleware\DenyWriteActionsInSupportMode;
use App\Http\Middleware\EarlyAccessRouteGuard;
use App\Http\Middleware\EnsureAdminAreaAccess;
use App\Http\Middleware\EnsureAnalyticsOriginAllowed;
use App\Http\Middleware\EnsureApiResponse;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Http\Middleware\EnsureEmailCodeVerified;
use App\Http\Middleware\EnsureFeature;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureIntegrationScope;
use App\Http\Middleware\EnsurePublicRegistrationEnabled;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureSupportModeContext;
use App\Http\Middleware\EnsureUserApproved;
use App\Http\Middleware\EnsureUserHasOrganization;
use App\Http\Middleware\IntegrationTokenMiddleware;
use App\Http\Middleware\LogIntegrationApiRequest;
use App\Http\Middleware\ProtectHeavyEndpoints;
use App\Http\Middleware\SetAppLocale;
use App\Http\Middleware\SetAdminLocale;
use App\Http\Middleware\SetPublicLocale;
use App\Http\Middleware\SetPublicSiteContext;
use App\Http\Middleware\SiteTokenMiddleware;
use App\Http\Middleware\VerifyClientDomainMiddleware;
use App\Http\Middleware\VerifyPluginRequestSignature;
use App\Support\SecurityResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        using: function () {
            $baseDomain = config('domains.base', 'publishlayer.local');

            // Canonicalize www marketing traffic to the apex domain.
            Route::domain("www.{$baseDomain}")
                ->middleware(['web', 'throttle:web'])
                ->any('/{path?}', function (Request $request) use ($baseDomain) {
                    $target = $request->getScheme() . '://' . $baseDomain . $request->getPathInfo();
                    $query = $request->getQueryString();
                    $status = in_array($request->getMethod(), ['GET', 'HEAD'], true) ? 301 : 308;

                    return redirect($query ? "{$target}?{$query}" : $target, $status);
                })
                ->where('path', '.*');

            // Marketing subdomain (apex domain)
            Route::domain($baseDomain)
                ->middleware(['web', 'throttle:web'])
                ->group(base_path('routes/marketing.php'));

            // App subdomain
            Route::domain("app.{$baseDomain}")
                ->middleware(['web', 'throttle:web'])
                ->group(base_path('routes/app.php'));

            // Admin subdomain
            Route::domain("admin.{$baseDomain}")
                ->middleware(['web', 'throttle:web'])
                ->group(base_path('routes/admin.php'));

            // API subdomain (primary)
            Route::domain("api.{$baseDomain}")
                ->middleware(['api', 'throttle:api'])
                ->group(base_path('routes/api.php'));

            // Track subdomain (analytics)
            Route::domain("track.{$baseDomain}")
                ->middleware('api')
                ->group(base_path('routes/track.php'));

            // API routes also available with /api prefix on any domain (backwards compat)
            // Note: Named routes should use the subdomain version (api.{domain}); this prefix
            // version uses a 'compat.' name prefix to avoid duplicate route name conflicts.
            Route::middleware(['api', 'throttle:api'])
                ->prefix('api')
                ->name('compat.')
                ->group(base_path('routes/api.php'));

            // Legacy app routes with /app prefix (backwards compat for existing tests/bookmarks)
            // These will eventually be removed once all tests are migrated to subdomain format
            Route::middleware(['web', 'throttle:web'])
                ->prefix('app')
                ->group(base_path('routes/app-legacy.php'));

            // Legacy admin routes with /admin prefix (backwards compat)
            Route::middleware(['web', 'throttle:web'])
                ->prefix('admin')
                ->group(base_path('routes/admin-legacy.php'));

            if (app()->environment(['local', 'testing'])) {
                // Marketing routes also available without domain binding for local link audits and tests.
                // Keep this out of production so app/admin/api/track subdomains cannot serve marketing pages.
                Route::middleware(['web', 'throttle:web'])
                    ->name('test.')
                    ->group(base_path('routes/marketing.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(BlockSuspiciousTraffic::class);

        // Route middleware (vervanger van $routeMiddleware)
        $middleware->alias([
            'admin.key' => AdminKeyMiddleware::class,
            'admin.area' => EnsureAdminAreaAccess::class,
            'site.token' => SiteTokenMiddleware::class,
            'integration.token' => IntegrationTokenMiddleware::class,
            'integration.scope' => EnsureIntegrationScope::class,
            'integration.log' => LogIntegrationApiRequest::class,
            'client.domain' => VerifyClientDomainMiddleware::class,
            'platform.admin' => EnsurePlatformAdmin::class,
            'support.context' => EnsureSupportModeContext::class,
            'support.readonly' => DenyWriteActionsInSupportMode::class,
            'onboarding.billing' => EnsureBillingOnboardingCompleted::class,
            'ensure.feature' => EnsureFeature::class,
            'ensure.feature.enabled' => EnsureFeatureEnabled::class,
            'registration.enabled' => EnsurePublicRegistrationEnabled::class,
            'early-access.guard' => EarlyAccessRouteGuard::class,
            'analytics.origin' => EnsureAnalyticsOriginAllowed::class,
            'email.code.verified' => EnsureEmailCodeVerified::class,
            'user.approved' => EnsureUserApproved::class,
            'user.org' => EnsureUserHasOrganization::class,
            'plugin.signature' => VerifyPluginRequestSignature::class,
            'public.locale' => SetPublicLocale::class,
            'public.context' => SetPublicSiteContext::class,
            'admin.locale' => SetAdminLocale::class,
            'app.locale' => SetAppLocale::class,
            'api.response' => EnsureApiResponse::class,
            'protect.heavy' => ProtectHeavyEndpoints::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthorizationException $exception, $request) {
            return SecurityResponse::forbidden($request);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, $request) {
            if ($exception->getStatusCode() !== 403) {
                return null;
            }

            return SecurityResponse::forbidden($request);
        });

        $exceptions->render(function (ThrottleRequestsException $exception, $request) {
            $retryAfter = $exception->getHeaders()['Retry-After'] ?? null;

            return SecurityResponse::tooManyRequests(
                $request,
                $retryAfter !== null ? (int) $retryAfter : null
            );
        });

        Integration::handles($exceptions);
    })->create();
