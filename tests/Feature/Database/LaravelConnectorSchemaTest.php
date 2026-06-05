<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates laravel connector destination sync schema', function () {
    expect(Schema::hasColumn('content_publish_targets', 'content_destination_id'))->toBeTrue();
    expect(Schema::hasTable('content_destination_sync_attempts'))->toBeTrue();
    expect(Schema::hasColumns('content_destination_sync_attempts', [
        'id',
        'workspace_id',
        'content_destination_id',
        'content_id',
        'content_publish_target_id',
        'sync_type',
        'trigger_source',
        'status',
        'attempt',
        'request_url',
        'idempotency_key',
        'response_status',
        'response_body',
        'error_message',
    ]))->toBeTrue();

    expect(Schema::hasColumns('publishlayer_articles', [
        'locale',
        'source_locale',
        'canonical_url',
        'canonical_content_id',
        'hreflang_alternates',
        'translation_group_id',
        'family_id',
        'answer_blocks',
        'structured_output',
        'schema_data',
        'ai_visibility',
        'metadata',
    ]))->toBeTrue();
});
