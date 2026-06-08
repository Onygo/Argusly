<?php

use App\Http\Controllers\Analytics\AnalyticsConfigController;
use App\Http\Controllers\Analytics\AnalyticsEventController;
use App\Http\Controllers\Analytics\AnalyticsScriptController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Track Subdomain Routes (track.argusly.com)
|--------------------------------------------------------------------------
|
| These routes handle the public analytics tracking system:
| - Serving the argusly.js tracking script
| - Receiving analytics events from customer websites
| - Providing configuration for tracking behavior
|
*/

// Tracking script - publicly cacheable
Route::get('/argusly.js', AnalyticsScriptController::class)
    ->withoutMiddleware(['throttle:api'])
    ->name('analytics.script');

// API routes for analytics
Route::prefix('api/v1')->group(function () {
    // Config endpoint - public, rate limited
    Route::get('/config', [AnalyticsConfigController::class, 'show'])
        ->middleware(['throttle:60,1'])
        ->name('analytics.config');

    // Event ingestion - public, rate limited per site
    Route::post('/events', [AnalyticsEventController::class, 'store'])
        ->middleware(['analytics.origin', 'throttle:analytics-events'])
        ->name('analytics.events');
});

Route::post('/api/tracking/events', [AnalyticsEventController::class, 'store'])
    ->middleware(['analytics.origin', 'throttle:analytics-events'])
    ->name('analytics.events.v2');
