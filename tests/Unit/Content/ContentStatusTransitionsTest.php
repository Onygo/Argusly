<?php

use App\Models\Content;
use App\Models\Organization;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows content status updates through lifecycle states', function () {
    $org = Organization::create(['name' => 'Org', 'slug' => 'org-u', 'status' => 'active']);
    $workspace = Workspace::create(['name' => 'Ws', 'organization_id' => $org->id]);

    $content = Content::create([
        'workspace_id' => $workspace->id,
        'title' => 'Status content',
        'type' => 'article',
        'status' => 'brief_received',
        'source' => 'api',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
    ]);

    $content->update(['status' => 'draft']);
    expect($content->fresh()->status)->toBe('draft');

    $content->update(['status' => 'review']);
    expect($content->fresh()->status)->toBe('review');

    $content->update(['status' => 'approved']);
    expect($content->fresh()->status)->toBe('approved');
});
