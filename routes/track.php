<?php

use App\Http\Controllers\Analytics\AnalyticsConfigController;
use App\Http\Controllers\Analytics\AnalyticsEventController;
use App\Http\Controllers\Analytics\AnalyticsScriptController;
use Illuminate\Support\Facades\Route;

Route::get('/argusly.js', AnalyticsScriptController::class)
    ->withoutMiddleware(['throttle:api'])
    ->name('analytics.script');

Route::get('/pl.js', AnalyticsScriptController::class)
    ->withoutMiddleware(['throttle:api'])
    ->name('analytics.script.legacy');

Route::prefix('api/v1')->group(function (): void {
    Route::get('/config', [AnalyticsConfigController::class, 'show'])
        ->middleware(['throttle:60,1'])
        ->name('analytics.config');

    Route::post('/events', [AnalyticsEventController::class, 'store'])
        ->middleware(['analytics.origin', 'throttle:analytics-events'])
        ->name('analytics.events');
});

Route::post('/api/tracking/events', [AnalyticsEventController::class, 'store'])
    ->middleware(['analytics.origin', 'throttle:analytics-events'])
    ->name('analytics.events.tracking');
