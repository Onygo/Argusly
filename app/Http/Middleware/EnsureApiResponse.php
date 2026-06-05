<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiResponse
{
    /**
     * Ensure requests on the API subdomain get JSON responses.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Force Accept: application/json for API subdomain
        if (! $request->wantsJson()) {
            $request->headers->set('Accept', 'application/json');
        }

        $response = $next($request);

        // Ensure response has JSON content type if it doesn't have one
        if (! $response->headers->has('Content-Type')) {
            $response->headers->set('Content-Type', 'application/json');
        }

        return $response;
    }
}
