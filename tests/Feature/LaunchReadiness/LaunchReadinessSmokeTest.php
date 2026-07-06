<?php

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('registers the critical launch routes', function (): void {
    expect(Route::has('landing'))->toBeTrue()
        ->and(Route::has('pricing'))->toBeTrue()
        ->and(Route::has('public.early-access.show'))->toBeTrue()
        ->and(Route::has('public.legal.privacy'))->toBeTrue()
        ->and(Route::has('public.legal.ai-transparency'))->toBeTrue()
        ->and(Route::has('login'))->toBeTrue()
        ->and(Route::has('register'))->toBeTrue()
        ->and(Route::has('app.dashboard'))->toBeTrue()
        ->and(Route::has('api.v1.connectors.heartbeat'))->toBeTrue()
        ->and(Route::has('webhooks.mollie'))->toBeTrue();
});

it('renders public launch and compliance entry points', function (): void {
    Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'platform_250',
        'slug' => 'platform_250',
        'name' => 'Argusly Platform',
        'interval' => 'month',
        'monthly_price_cents' => 9900,
        'price_cents' => 9900,
        'currency' => 'EUR',
        'included_credits' => 250,
        'included_credits_per_interval' => 250,
        'seat_limit' => 5,
        'is_active' => true,
        'is_public' => true,
        'billing_type' => 'fixed',
        'sort_order' => 1,
    ]);

    $paths = [
        route('landing'),
        route('public.legal.privacy'),
        route('public.legal.terms'),
        route('public.legal.security'),
        route('public.legal.ai-transparency'),
        route('public.legal.cookies'),
        route('public.legal.subprocessors'),
        route('localized.nl.public.legal.privacy'),
        route('localized.nl.public.legal.terms'),
        route('localized.nl.public.legal.security'),
        route('localized.nl.public.legal.ai-transparency'),
        route('localized.nl.public.legal.cookies'),
        route('localized.nl.public.legal.subprocessors'),
        route('login'),
        route('register'),
    ];

    foreach ($paths as $path) {
        $this->get($path)->assertOk();
    }
});

it('keeps public discovery files Argusly branded and temporary launch pages absent', function (): void {
    expect(File::exists(public_path('temp.php')))->toBeFalse();

    $robots = File::get(public_path('robots.txt'));

    expect($robots)->toContain('Sitemap: https://argusly.com/sitemap.xml')
        ->and($robots)->toContain('Sitemap: https://argusly.com/llms.txt')
        ->and($robots)->not->toContain('publishlayer.com')
        ->and(config('sitemap.static_routes'))->toContain('public.legal.ai-transparency')
        ->and(collect(config('llms.pages'))->pluck('route'))->toContain('public.legal.ai-transparency');
});

it('keeps launch hardening defaults in the raw Argusly config contract', function (): void {
    $previousEnv = (string) env('APP_ENV', 'testing');

    putenv('APP_ENV=production');
    $_ENV['APP_ENV'] = 'production';
    $_SERVER['APP_ENV'] = 'production';

    try {
        $config = require base_path('config/argusly.php');

        expect(data_get($config, 'wp_connector.require_timestamp_nonce'))->toBeTrue()
            ->and(data_get($config, 'launch.public_registration_enabled'))->toBeTrue()
            ->and(data_get($config, 'launch.legacy_path_routes_enabled'))->toBeFalse();
    } finally {
        putenv('APP_ENV='.$previousEnv);
        $_ENV['APP_ENV'] = $previousEnv;
        $_SERVER['APP_ENV'] = $previousEnv;
    }
});
