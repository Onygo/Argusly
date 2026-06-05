<?php

use App\Http\Controllers\App\AppContentController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

it('resolves app content urls to app content show route instead of connector inbox public route', function () {
    config(['domains.base' => 'argusly.local']);

    $route = app('router')->getRoutes()->match(
        Request::create('http://app.argusly.local/content/019cb8e4-0425-7261-908c-e0a466c7664f', 'GET')
    );

    expect($route->getName())->toBe('app.content.show')
        ->and($route->getActionName())->toContain(AppContentController::class . '@show');
});

