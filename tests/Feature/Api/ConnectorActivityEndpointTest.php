<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns connector activity payload for frontend activity-check payload fields', function () {
    config()->set('argusly_connector.site_key', 'pl_site_test_key_123');

    $response = $this->postJson('/argusly/connector/activity', [
        'site_key' => 'pl_site_test_key_123',
        'site_token' => 'pl_site_test_key_123',
        'site_id' => '019cb5a7-2cce-7280-a2a5-d1ddd91bec27',
        'workspace_id' => '019cb5b7-d9e1-737d-b5df-84fa9ed1b9eb',
    ]);

    $response->assertOk()
        ->assertJson([
            'last_webhook_received_at' => null,
            'last_processed_at' => null,
            'last_heartbeat_at' => null,
            'recent_events_count_24h' => 0,
            'failed_events_count_24h' => 0,
        ]);
});

it('accepts configured api_key when site_key config is empty', function () {
    config()->set('argusly_connector.site_key', null);
    config()->set('argusly_connector.api_key', 'pl_site_api_key_123');
    config()->set('argusly_connector.connections.default.api_key', 'pl_site_api_key_123');

    $this->postJson('/argusly/connector/activity', [
        'site_key' => 'pl_site_api_key_123',
        'site_id' => '019cb5a7-2cce-7280-a2a5-d1ddd91bec27',
        'workspace_id' => '019cb5b7-d9e1-737d-b5df-84fa9ed1b9eb',
    ])->assertOk()
        ->assertJson([
            'recent_events_count_24h' => 0,
            'failed_events_count_24h' => 0,
        ]);
});

it('returns validation errors when connector activity site key is missing', function () {
    $response = $this->postJson('/argusly/connector/activity', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['site_key']);
});
