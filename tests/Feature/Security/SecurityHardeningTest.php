<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

it('returns 429 after too many login attempts', function () {
    Config::set('security.rate_limits.login_per_minute', 2);

    Route::post('/_test/security/login-throttle', fn () => response('ok'))
        ->middleware('throttle:login');

    $server = [
        'REMOTE_ADDR' => '203.0.113.10',
    ];

    $this->withServerVariables($server)->post('/_test/security/login-throttle')->assertOk();
    $this->withServerVariables($server)->post('/_test/security/login-throttle')->assertOk();
    $this->withServerVariables($server)->post('/_test/security/login-throttle')->assertStatus(429)
        ->assertSeeText('Too many requests. Please try again shortly.');
});

it('blocks known suspicious paths with a compact 403 response', function () {
    Config::set('security.toggles.block_suspicious_traffic', true);
    Config::set('security.toggles.log_suspicious_traffic', true);
    Config::set('security.toggles.log_only_mode', false);

    $this->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.11',
    ])->get('/.env')
        ->assertStatus(403)
        ->assertSeeText('Forbidden.');
});

it('blocks suspicious query patterns before hitting the route', function () {
    Config::set('security.toggles.block_suspicious_traffic', true);
    Config::set('security.toggles.log_suspicious_traffic', true);
    Config::set('security.toggles.log_only_mode', false);

    $this->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.12',
    ])->get('/contact?probe=union%20select%20password%20from%20users')
        ->assertStatus(403)
        ->assertSeeText('Forbidden.');
});

it('logs suspicious paths in log-only mode without blocking the request lifecycle', function () {
    Config::set('security.toggles.block_suspicious_traffic', false);
    Config::set('security.toggles.log_suspicious_traffic', true);
    Config::set('security.toggles.log_only_mode', true);

    Log::shouldReceive('channel')->once()->with('security')->andReturnSelf();
    Log::shouldReceive('warning')
        ->once()
        ->with('suspicious_traffic', \Mockery::on(function (array $context): bool {
            return $context['reason'] === 'path'
                && $context['ip'] === '203.0.113.14'
                && $context['method'] === 'GET'
                && $context['path'] === '/.env';
        }));

    $this->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.14',
    ])->get('/.env')
        ->assertNotFound();
});

it('returns a compact json error structure for abuse responses', function () {
    Config::set('security.toggles.block_suspicious_traffic', true);
    Config::set('security.toggles.log_suspicious_traffic', true);
    Config::set('security.toggles.log_only_mode', false);

    $this->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.13',
        'HTTP_ACCEPT' => 'application/json',
    ])->get('/.git/config')
        ->assertStatus(403)
        ->assertJson([
            'message' => 'Forbidden.',
        ]);
});

it('applies the heavy limiter to expensive endpoints', function () {
    Config::set('security.rate_limits.heavy_per_minute', 1);

    Route::post('/_test/security/heavy-endpoint', fn () => response('ok'))
        ->middleware('protect.heavy:heavy');

    $server = [
        'REMOTE_ADDR' => '203.0.113.15',
        'HTTP_ACCEPT' => 'application/json',
    ];

    $this->withServerVariables($server)->post('/_test/security/heavy-endpoint')->assertOk();
    $this->withServerVariables($server)->post('/_test/security/heavy-endpoint')
        ->assertStatus(429)
        ->assertJson([
            'message' => 'Too many requests. Please try again shortly.',
        ]);
});
