<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureIntegrationScope
{
    public function handle(Request $request, Closure $next, string $scope)
    {
        $apiKey = $request->attributes->get('apiKey');
        if ($apiKey) {
            if (! $apiKey->hasScope($scope)) {
                return response()->json([
                    'message' => 'Forbidden: missing scope',
                    'code' => 'AUTH_SCOPE_MISSING',
                    'errors' => [
                        'scope' => [$scope],
                    ],
                ], 403);
            }

            return $next($request);
        }

        $siteToken = $request->attributes->get('siteToken');
        if ($siteToken && $siteToken->hasScope($scope)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Forbidden',
            'code' => 'AUTH_FORBIDDEN',
        ], 403);
    }
}
