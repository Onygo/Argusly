<?php

use App\Support\SocialImageResolver;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

it('returns explicit social images before mapped fallbacks', function () {
    $resolver = new SocialImageResolver();
    $request = Request::create('/en/blog', 'GET');
    $request->setRouteResolver(fn () => tap(new Route('GET', '/en/blog', []), fn (Route $route) => $route->name('public.blog.index')));

    expect($resolver->resolve('/images/social/custom.jpg', url('/en/blog'), null, $request))
        ->toBe(asset('images/social/custom.jpg'));
});

it('maps known route types before hash fallback', function () {
    $resolver = new SocialImageResolver();
    $request = Request::create('/en/markets/saas', 'GET');
    $request->setRouteResolver(fn () => tap(new Route('GET', '/en/markets/saas', []), fn (Route $route) => $route->name('public.markets.it-services-saas')));

    expect($resolver->resolve(null, url('/en/markets/saas'), null, $request))
        ->toBe(asset('images/social/argusly-og-growth-intelligence.jpg'));
});

it('keeps deterministic fallback selection stable for the same canonical url', function () {
    config()->set('argusly_social.route_type_mapping', []);

    $resolver = new SocialImageResolver();
    $request = Request::create('/en/resources/one', 'GET');

    $first = $resolver->resolve(null, url('/en/resources/one'), null, $request);
    $second = $resolver->resolve(null, url('/en/resources/one'), null, $request);

    expect($first)->toBe($second)
        ->and($first)->toStartWith(url('/images/social/'));
});

it('can spread different urls across fallback variants', function () {
    config()->set('argusly_social.route_type_mapping', []);

    $resolver = new SocialImageResolver();
    $request = Request::create('/en/resources', 'GET');

    $images = collect(range(1, 20))
        ->map(fn (int $index): string => $resolver->resolve(null, url('/en/resources/page-' . $index), null, $request))
        ->unique()
        ->values();

    expect($images->count())->toBeGreaterThan(1);
});
