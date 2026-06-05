<?php

use App\Models\Content;
use App\Models\ContentCreditLog;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('stores credit log entries per content run', function () {
    $org = Organization::create(['name' => 'Org', 'slug' => 'org-c', 'status' => 'active']);
    $workspace = Workspace::create(['name' => 'Ws', 'organization_id' => $org->id]);

    $content = Content::create([
        'workspace_id' => $workspace->id,
        'title' => 'Credit content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'delivery_status' => 'pending',
        'generation_mode' => 'quality',
    ]);

    ContentCreditLog::create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'event' => 'initial_generation',
        'credits_used' => 10,
        'mode_multiplier' => 1.5,
    ]);

    $log = ContentCreditLog::query()->where('content_id', $content->id)->first();

    expect($log)->not->toBeNull();
    expect($log->credits_used)->toBe(10);
    expect((float) $log->mode_multiplier)->toBe(1.5);
});
