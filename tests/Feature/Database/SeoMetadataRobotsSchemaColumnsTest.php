<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds robots and schema type columns to seo metadata tables', function () {
    expect(Schema::hasColumns('drafts', ['robots_index', 'robots_follow', 'schema_type']))->toBeTrue()
        ->and(Schema::hasColumns('contents', ['robots_index', 'robots_follow', 'schema_type']))->toBeTrue()
        ->and(Schema::hasColumns('content_seo', ['robots_index', 'robots_follow', 'schema_type']))->toBeTrue();
});
