<?php

namespace App\Http\Middleware;

use App\Services\PublicSiteContextResolver;
use App\Support\PublicSiteContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPublicSiteContext
{
    public function __construct(
        private readonly PublicSiteContextResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->resolver->resolve($request);

        $request->attributes->set(PublicSiteContext::class, $context);
        app()->instance(PublicSiteContext::class, $context);
        view()->share('publicSiteContext', $context);

        return $next($request);
    }
}
