<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    // Generate OpenAPI spec first (dependency)
    $this->artisan('argusly:generate-openapi');
});

afterEach(function () {
    // Clean up generated files
    $files = [
        base_path('docs/openapi/argusly.yaml'),
        base_path('docs/postman/argusly-collection.json'),
        base_path('docs/postman/argusly-environment.json'),
    ];

    foreach ($files as $file) {
        if (File::exists($file)) {
            File::delete($file);
        }
    }
});

test('generate-postman command runs successfully', function () {
    $this->artisan('argusly:generate-postman')
        ->assertSuccessful();
});

test('generate-postman command creates collection file', function () {
    $this->artisan('argusly:generate-postman')
        ->assertSuccessful();

    expect(File::exists(base_path('docs/postman/argusly-collection.json')))->toBeTrue();
});

test('generate-postman command creates environment file', function () {
    $this->artisan('argusly:generate-postman')
        ->assertSuccessful();

    expect(File::exists(base_path('docs/postman/argusly-environment.json')))->toBeTrue();
});

test('generated collection is valid json', function () {
    $this->artisan('argusly:generate-postman');

    $content = File::get(base_path('docs/postman/argusly-collection.json'));
    $collection = json_decode($content, true);

    expect($collection)->not->toBeNull();
    expect($collection)->toHaveKeys(['info', 'item', 'auth']);
});

test('generated collection has correct schema version', function () {
    $this->artisan('argusly:generate-postman');

    $content = File::get(base_path('docs/postman/argusly-collection.json'));
    $collection = json_decode($content, true);

    expect($collection['info']['schema'])
        ->toBe('https://schema.getpostman.com/json/collection/v2.1.0/collection.json');
});

test('generated collection has bearer auth configured', function () {
    $this->artisan('argusly:generate-postman');

    $content = File::get(base_path('docs/postman/argusly-collection.json'));
    $collection = json_decode($content, true);

    expect($collection['auth']['type'])->toBe('bearer');
    expect($collection['auth']['bearer'][0]['value'])->toBe('{{workspace_api_key}}');
});

test('generated environment contains expected variables', function () {
    $this->artisan('argusly:generate-postman');

    $content = File::get(base_path('docs/postman/argusly-environment.json'));
    $environment = json_decode($content, true);

    expect($environment)->not->toBeNull();
    expect($environment)->toHaveKeys(['id', 'name', 'values']);

    $variableKeys = collect($environment['values'])->pluck('key')->toArray();

    expect($variableKeys)->toContain('base_url');
    expect($variableKeys)->toContain('workspace_api_key');
});

test('generate-postman fails gracefully when openapi spec missing', function () {
    // Delete the OpenAPI spec
    File::delete(base_path('docs/openapi/argusly.yaml'));

    $this->artisan('argusly:generate-postman')
        ->assertFailed()
        ->expectsOutputToContain('not found');
});

test('collection items are grouped by tag', function () {
    $this->artisan('argusly:generate-postman');

    $content = File::get(base_path('docs/postman/argusly-collection.json'));
    $collection = json_decode($content, true);

    // Items should be folders (arrays with 'name' and 'item' keys)
    expect($collection['item'])->toBeArray();
    expect(count($collection['item']))->toBeGreaterThan(0);

    $firstFolder = $collection['item'][0];
    expect($firstFolder)->toHaveKeys(['name', 'item']);
});
