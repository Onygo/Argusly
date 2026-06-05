<?php

use App\Models\Content;
use App\Models\ContentSeo;
use App\Models\Draft;

it('exposes robots and schema fields on draft fillable and casts', function () {
    $model = new Draft();

    expect($model->getFillable())->toContain('robots_index', 'robots_follow', 'schema_type')
        ->and($model->getCasts())->toMatchArray([
            'robots_index' => 'boolean',
            'robots_follow' => 'boolean',
        ]);
});

it('exposes robots and schema fields on content fillable and casts', function () {
    $model = new Content();

    expect($model->getFillable())->toContain('robots_index', 'robots_follow', 'schema_type')
        ->and($model->getCasts())->toMatchArray([
            'robots_index' => 'boolean',
            'robots_follow' => 'boolean',
        ]);
});

it('exposes robots and schema fields on content seo fillable and casts', function () {
    $model = new ContentSeo();

    expect($model->getFillable())->toContain('robots_index', 'robots_follow', 'schema_type')
        ->and($model->getCasts())->toMatchArray([
            'robots_index' => 'boolean',
            'robots_follow' => 'boolean',
        ]);
});
