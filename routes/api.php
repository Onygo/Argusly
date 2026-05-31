<?php

use App\Http\Controllers\Api\V1\ConnectorApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware('throttle:connector-api')
    ->group(function (): void {
        Route::get('/connector/manifest', [ConnectorApiController::class, 'manifest'])
            ->middleware('auth.connector:connector:read');
        Route::post('/connector/register', [ConnectorApiController::class, 'register'])
            ->middleware('auth.connector:connector:write');
        Route::post('/connector/health', [ConnectorApiController::class, 'health'])
            ->middleware('auth.connector:health:write');
        Route::get('/connector/capabilities', [ConnectorApiController::class, 'capabilities'])
            ->middleware('auth.connector:connector:read');
        Route::post('/connector/events', [ConnectorApiController::class, 'events'])
            ->middleware('auth.connector:events:write');
        Route::get('/content/pending', [ConnectorApiController::class, 'pendingContent'])
            ->middleware('auth.connector:content:read');
        Route::post('/content/{content}/published', [ConnectorApiController::class, 'published'])
            ->middleware('auth.connector:content:publish');
        Route::post('/content/{content}/failed', [ConnectorApiController::class, 'failed'])
            ->middleware('auth.connector:content:publish');
    });
