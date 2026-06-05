<?php

namespace App\Http\Middleware;

use App\Support\SecurityResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ProtectHeavyEndpoints
{
    public function __construct(private readonly ThrottleRequests $throttleRequests)
    {
    }

    public function handle(Request $request, Closure $next, string $profile = 'heavy'): Response
    {
        if (! config('security.toggles.protect_heavy_endpoints', true)) {
            return $next($request);
        }

        $settings = (array) config("security.heavy_routes.{$profile}", []);

        if ($profile === 'search') {
            $query = trim((string) $request->query('q', $request->input('q', '')));
            $maxLength = (int) ($settings['max_query_length'] ?? 120);

            if ($query !== '' && mb_strlen($query) > $maxLength) {
                return SecurityResponse::invalid($request, 'Search query too long.', 422);
            }
        }

        $limiter = (string) ($settings['limiter'] ?? 'heavy');

        return $this->throttleRequests->handle($request, $next, $limiter);
    }
}
