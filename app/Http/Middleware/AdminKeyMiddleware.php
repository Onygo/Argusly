<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminKeyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $expected = (string) config('argusly.admin_key');
        $given = (string) $request->header('X-Admin-Key');

        if ($expected === '' || !hash_equals($expected, $given)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
