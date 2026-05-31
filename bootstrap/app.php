<?php

use App\Http\Middleware\AuthenticateConnector;
use App\Http\Middleware\EnsureModuleIsActive;
use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\LocaleMiddleware;
use App\Http\Middleware\ResolveCurrentAccount;
use App\Http\Middleware\ResolveCurrentBrand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('web', [
            LocaleMiddleware::class,
        ]);

        $middleware->alias([
            'current.account' => ResolveCurrentAccount::class,
            'current.brand' => ResolveCurrentBrand::class,
            'ui.locale' => LocaleMiddleware::class,
            'auth.connector' => AuthenticateConnector::class,
            'connector.auth' => AuthenticateConnector::class,
            'module.active' => EnsureModuleIsActive::class,
            'permission' => EnsureUserHasPermission::class,
            'role' => EnsureUserHasRole::class,
            'tenant.account' => ResolveCurrentAccount::class,
            'tenant.brand' => ResolveCurrentBrand::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
