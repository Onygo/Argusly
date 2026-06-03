<?php

use App\Http\Middleware\AuthenticateConnector;
use App\Http\Middleware\EnsureModuleIsActive;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\LocaleMiddleware;
use App\Http\Middleware\ResolveCurrentAccount;
use App\Http\Middleware\ResolveCurrentBrand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            if (config('argusly.api_domain')) {
                Route::middleware('api')
                    ->domain(config('argusly.api_domain'))
                    ->group(base_path('routes/api.php'));
            }
        },
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
            'platform.admin' => EnsurePlatformAdmin::class,
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
