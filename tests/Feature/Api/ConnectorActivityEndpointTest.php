<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

require_once dirname(__DIR__, 3) . '/packages/laravel-connector/src/ActivityState.php';
require_once dirname(__DIR__, 3) . '/packages/laravel-connector/src/InstalledVersions.php';
require_once dirname(__DIR__, 3) . '/packages/laravel-connector/src/Http/Controllers/ConnectorActivityController.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! collect(Route::getRoutes())->contains(fn ($route): bool => $route->uri() === 'argusly/connector/activity')) {
        Route::match(
            ['GET', 'POST'],
            'argusly/connector/activity',
            \Onygo\ArguslyConnector\Http\Controllers\ConnectorActivityController::class
        );
    }

    Cache::forget('argusly_connector.activity');
});

it('returns connector activity payload for frontend activity-check payload fields', function () {
    config()->set('argusly-connector.api.token', 'arg_site_test_key_123');
    config()->set('argusly-connector.api.workspace_id', '019cb5b7-d9e1-737d-b5df-84fa9ed1b9eb');

    app(\Onygo\ArguslyConnector\ActivityState::class)->record('heartbeat');

    $response = $this->postJson('/argusly/connector/activity', [
        'site_key' => 'arg_site_test_key_123',
        'site_token' => 'arg_site_test_key_123',
        'site_id' => '019cb5a7-2cce-7280-a2a5-d1ddd91bec27',
        'workspace_id' => '019cb5b7-d9e1-737d-b5df-84fa9ed1b9eb',
    ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('recent_events_count_24h', 1)
        ->assertJsonPath('failed_events_count_24h', 0)
        ->assertJsonPath('configured.api_key', true)
        ->assertJsonPath('configured.workspace_id', true);

    expect($response->json('last_heartbeat_at'))->not->toBeNull();
});

it('returns validation errors when connector activity site key is missing', function () {
    config()->set('argusly-connector.api.token', 'arg_site_test_key_123');

    $this->postJson('/argusly/connector/activity', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['site_key']);
});

it('returns validation errors when connector activity site key is invalid', function () {
    config()->set('argusly-connector.api.token', 'arg_site_test_key_123');

    $this->postJson('/argusly/connector/activity', [
        'site_key' => 'wrong_key',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['site_key'])
        ->assertJsonPath('errors.site_key.0', 'The selected site_key is invalid.');
});

it('returns validation errors when workspace id does not match', function () {
    config()->set('argusly-connector.api.token', 'arg_site_test_key_123');
    config()->set('argusly-connector.api.workspace_id', 'workspace-a');

    $this->postJson('/argusly/connector/activity', [
        'site_key' => 'arg_site_test_key_123',
        'workspace_id' => 'workspace-b',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['workspace_id'])
        ->assertJsonPath('errors.workspace_id.0', 'The workspace_id does not match this connector.');
});

it('reports missing destination key as configuration state without exposing secrets', function () {
    config()->set('argusly-connector.api.token', 'arg_site_test_key_123');
    config()->set('argusly-connector.destination.id', null);

    $this->postJson('/argusly/connector/activity', [
        'site_key' => 'arg_site_test_key_123',
    ])->assertOk()
        ->assertJsonPath('configured.destination_key', false)
        ->assertJsonMissing(['destination_key' => 'arg_site_test_key_123']);
});
