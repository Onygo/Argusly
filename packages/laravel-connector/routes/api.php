<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Onygo\ArguslyConnector\Http\Controllers\ConnectorActivityController;
use Onygo\ArguslyConnector\Http\Controllers\ConnectorSyncController;

$syncPath = trim((string) config('argusly-connector.webhooks.sync_path', 'argusly/sync'), '/');

if ($syncPath !== '') {
    Route::post($syncPath, ConnectorSyncController::class)
        ->name('argusly.connector.sync');
}

Route::match(['GET', 'POST'], 'argusly/connector/activity', ConnectorActivityController::class)
    ->name('argusly.connector.activity');

Route::match(['GET', 'POST'], 'argusly/activity', ConnectorActivityController::class)
    ->name('argusly.activity');
