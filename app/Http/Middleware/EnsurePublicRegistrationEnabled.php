<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePublicRegistrationEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('argusly.launch.public_registration_enabled', true)) {
            return $next($request);
        }

        $mode = strtolower(trim((string) config('argusly.launch.registration_block_mode', 'redirect')));

        if (in_array($mode, ['403', 'forbidden'], true)) {
            abort(403);
        }

        if (in_array($mode, ['404', 'not_found', 'not-found'], true)) {
            abort(404);
        }

        return redirect()->route('public.early-access.show');
    }
}
