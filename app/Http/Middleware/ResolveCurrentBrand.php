<?php

namespace App\Http\Middleware;

use App\Contracts\CurrentBrandContract;
use App\Models\Brand;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentBrand
{
    public function __construct(private readonly CurrentBrandContract $currentBrand) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $brand = $this->brandFromRequest($request);

        if ($brand) {
            $this->currentBrand->set($brand, $user);
        } else {
            $this->currentBrand->get($user);
        }

        return $next($request);
    }

    private function brandFromRequest(Request $request): Brand|int|null
    {
        $brand = $request->route('brand');

        if ($brand instanceof Brand) {
            return $brand;
        }

        if ($brand !== null) {
            return (int) $brand;
        }

        $brandId = $request->route('brand_id') ?? $request->input('brand_id');

        return $brandId === null ? null : (int) $brandId;
    }
}
