<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds comparison linkage columns to drafts', function () {
    expect(Schema::hasColumns('drafts', ['draft_comparison_id', 'draft_comparison_variant_id']))->toBeTrue();
});
