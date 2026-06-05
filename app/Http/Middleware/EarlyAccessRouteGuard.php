<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\EarlyAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to block marketing pages in early access mode.
 *
 * When early access mode is enabled, this middleware redirects visitors
 * away from full marketing pages to the early access signup page.
 *
 * Usage in routes:
 *   Route::get('/product/capabilities', ...)->middleware('early-access.guard:product.capabilities');
 */
class EarlyAccessRouteGuard
{
    /**
     * Handle an incoming request.
     *
     * @param  string|null  $pageKey  The page identifier for visibility checking
     */
    public function handle(Request $request, Closure $next, ?string $pageKey = null): Response
    {
        // If not in early access mode, allow all routes
        if (! EarlyAccess::enabled()) {
            return $next($request);
        }

        // If no page key provided, we cannot validate - allow through
        if ($pageKey === null) {
            return $next($request);
        }

        // Check if this page is allowed in early access mode
        if (EarlyAccess::allowPublicMarketingPage($pageKey)) {
            return $next($request);
        }

        // Page is blocked in early access mode - redirect to early access page
        return redirect()->to(EarlyAccess::getBlockedPageRedirectUrl());
    }
}
