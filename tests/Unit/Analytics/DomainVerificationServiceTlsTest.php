<?php

use App\Services\Analytics\DomainVerificationService;

it('returns true for local env, enabled flag, and local host', function () {
    config()->set('app.env', 'local');
    config()->set('publishlayer.http_insecure_local', true);

    $service = app(DomainVerificationService::class);
    $method = new ReflectionMethod($service, 'shouldDisableTlsVerifyFor');
    $method->setAccessible(true);

    $result = $method->invoke($service, 'https://argusly.local/');

    expect($result)->toBeTrue();
});

it('returns false when local flag is disabled', function () {
    config()->set('app.env', 'local');
    config()->set('publishlayer.http_insecure_local', false);

    $service = app(DomainVerificationService::class);
    $method = new ReflectionMethod($service, 'shouldDisableTlsVerifyFor');
    $method->setAccessible(true);

    $result = $method->invoke($service, 'https://argusly.local/');

    expect($result)->toBeFalse();
});

it('throws in production when insecure local flag is enabled', function () {
    config()->set('app.env', 'production');
    config()->set('publishlayer.http_insecure_local', true);

    $service = app(DomainVerificationService::class);
    $method = new ReflectionMethod($service, 'shouldDisableTlsVerifyFor');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($service, 'https://argusly.local/'))
        ->toThrow(RuntimeException::class, 'PUBLISHLAYER_HTTP_INSECURE_LOCAL must never be enabled in production.');
});

it('returns false for non local hosts even when local flag is enabled', function () {
    config()->set('app.env', 'local');
    config()->set('publishlayer.http_insecure_local', true);

    $service = app(DomainVerificationService::class);
    $method = new ReflectionMethod($service, 'shouldDisableTlsVerifyFor');
    $method->setAccessible(true);

    $result = $method->invoke($service, 'https://api.publishlayer.com/');

    expect($result)->toBeFalse();
});
