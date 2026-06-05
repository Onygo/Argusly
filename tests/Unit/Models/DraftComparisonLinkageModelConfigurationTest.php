<?php

use App\Models\Draft;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

it('exposes draft comparison linkage fields on draft fillable', function () {
    $model = new Draft();

    expect($model->getFillable())->toContain('draft_comparison_id', 'draft_comparison_variant_id');
});

it('defines belongs-to relations for draft comparison linkage', function () {
    $model = new Draft();

    expect($model->draftComparison())->toBeInstanceOf(BelongsTo::class)
        ->and($model->draftComparisonVariantLink())->toBeInstanceOf(BelongsTo::class);
});
