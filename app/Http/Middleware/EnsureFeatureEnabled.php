<?php

namespace App\Http\Middleware;

use App\Support\FeatureFlags;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureEnabled
{
    public function __construct(private readonly FeatureFlags $features)
    {
    }

    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        if (! $this->features->isEnabled($featureKey)) {
            abort(404);
        }

        return $next($request);
    }
}
