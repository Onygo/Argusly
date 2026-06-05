<?php

use App\Services\ApiDocs\ApiResourceSchemaExtractor;

beforeEach(function () {
    $this->extractor = new ApiResourceSchemaExtractor();
});

test('extracts Brief schema', function () {
    $schema = $this->extractor->extract(\App\Http\Resources\Api\V1\BriefResource::class);

    expect($schema['type'])->toBe('object');
    expect($schema['properties'])->toHaveKeys(['id', 'workspace_id', 'title', 'status', 'language']);
    expect($schema['properties']['id']['type'])->toBe('string');
    expect($schema['properties']['id']['format'])->toBe('uuid');
});

test('extracts Draft schema', function () {
    $schema = $this->extractor->extract(\App\Http\Resources\Api\V1\DraftResource::class);

    expect($schema['type'])->toBe('object');
    expect($schema['properties'])->toHaveKeys(['id', 'brief_id', 'title', 'status', 'content_html', 'seo']);
    expect($schema['properties']['seo']['type'])->toBe('object');
});

test('extracts ContentDestination schema', function () {
    $schema = $this->extractor->extract(\App\Http\Resources\Api\V1\ContentDestinationResource::class);

    expect($schema['type'])->toBe('object');
    expect($schema['properties'])->toHaveKeys(['id', 'name', 'type', 'status', 'environment']);
    expect($schema['properties']['type']['enum'])->toContain('api');
});

test('extracts ApiKey schema', function () {
    $schema = $this->extractor->extract(\App\Http\Resources\Api\V1\ApiKeyResource::class);

    expect($schema['type'])->toBe('object');
    expect($schema['properties'])->toHaveKeys(['id', 'name', 'key_prefix', 'scopes']);
    expect($schema['properties']['scopes']['type'])->toBe('array');
});

test('extracts AsyncOperation schema', function () {
    $schema = $this->extractor->extract(\App\Http\Resources\Api\V1\AsyncOperationResource::class);

    expect($schema['type'])->toBe('object');
    expect($schema['properties'])->toHaveKeys(['id', 'operation_type', 'status', 'error']);
    expect($schema['properties']['error']['type'])->toBe('object');
});

test('gets schema name from resource class', function () {
    expect($this->extractor->getSchemaName(\App\Http\Resources\Api\V1\BriefResource::class))
        ->toBe('Brief');

    expect($this->extractor->getSchemaName(\App\Http\Resources\Api\V1\ContentDestinationResource::class))
        ->toBe('ContentDestination');
});

test('returns default schema for unknown resource', function () {
    $schema = $this->extractor->extract('UnknownResource');

    expect($schema['type'])->toBe('object');
    expect($schema['additionalProperties'])->toBe(true);
});

test('getAllSchemas returns all defined schemas', function () {
    $schemas = $this->extractor->getAllSchemas();

    expect($schemas)->toHaveKeys(['Brief', 'Draft', 'ContentDestination', 'ApiKey', 'ApiWebhook', 'SeoAudit', 'AsyncOperation']);
});
