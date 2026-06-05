<?php

use App\Models\ContentDestination;
use App\Services\Publication\Drivers\ApiPublicationDriver;
use App\Services\Publication\Drivers\LaravelPublicationDriver;
use App\Services\Publication\Drivers\WordPressPublicationDriver;
use App\Services\Publication\PublicationDestinationDriverResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves wordpress laravel and api drivers by destination type', function () {
    $resolver = app(PublicationDestinationDriverResolver::class);

    $wordpress = new ContentDestination();
    $wordpress->setRawAttributes(['type' => 'wordpress'], true);

    $laravel = new ContentDestination();
    $laravel->setRawAttributes(['type' => 'laravel'], true);

    $api = new ContentDestination();
    $api->setRawAttributes(['type' => 'api'], true);

    expect($resolver->resolveForDestination($wordpress))->toBeInstanceOf(WordPressPublicationDriver::class)
        ->and($resolver->resolveForDestination($laravel))->toBeInstanceOf(LaravelPublicationDriver::class)
        ->and($resolver->resolveForDestination($api))->toBeInstanceOf(ApiPublicationDriver::class);
});

it('fails explicitly for unknown destination types', function () {
    $resolver = app(PublicationDestinationDriverResolver::class);

    $destination = new ContentDestination();
    $destination->setRawAttributes(['type' => 'unknown_cms'], true);

    expect(fn () => $resolver->resolveForDestination($destination))
        ->toThrow(RuntimeException::class, 'Unknown destination type');
});
