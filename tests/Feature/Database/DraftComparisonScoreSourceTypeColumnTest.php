<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds source_type column to draft comparison scores', function () {
    expect(Schema::hasColumns('draft_comparison_scores', ['source_type']))->toBeTrue();
});
