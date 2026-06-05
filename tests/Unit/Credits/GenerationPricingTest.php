<?php

use App\Services\Credits\GenerationPricing;

it('prices standard article generation at baseline credits', function () {
    $pricing = app(GenerationPricing::class);

    expect($pricing->requiredCredits('article', 8000))->toBe(10);
});

it('adds one step for 10000 tokens', function () {
    $pricing = app(GenerationPricing::class);

    expect($pricing->requiredCredits('article', 10000))->toBe(12);
});

it('adds two steps for 12000 tokens', function () {
    $pricing = app(GenerationPricing::class);

    expect($pricing->requiredCredits('article', 12000))->toBe(14);
});

it('caps article pricing at 16 credits', function () {
    $pricing = app(GenerationPricing::class);

    expect($pricing->requiredCredits('article', 50000))->toBe(16);
});

it('normalizes requested tokens to configured max output', function () {
    $pricing = app(GenerationPricing::class);

    expect($pricing->normalizeRequestedMaxOutputTokens('article', 50000))->toBe(14000);
});
