<?php

use App\Services\ApiDocs\OpenApiGenerator;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    // Ensure clean state
    $outputPath = base_path('docs/openapi/publishlayer.yaml');
    if (File::exists($outputPath)) {
        File::delete($outputPath);
    }
});

afterEach(function () {
    // Clean up generated file
    $outputPath = base_path('docs/openapi/publishlayer.yaml');
    if (File::exists($outputPath)) {
        File::delete($outputPath);
    }
});

test('generate-openapi command runs successfully', function () {
    $this->artisan('publishlayer:generate-openapi')
        ->assertSuccessful();
});

test('generate-openapi command creates output file', function () {
    $this->artisan('publishlayer:generate-openapi')
        ->assertSuccessful();

    expect(File::exists(base_path('docs/openapi/publishlayer.yaml')))->toBeTrue();
});

test('generate-openapi command with validation passes', function () {
    $this->artisan('publishlayer:generate-openapi', ['--validate' => true])
        ->assertSuccessful();
});

test('generate-openapi command shows statistics', function () {
    $this->artisan('publishlayer:generate-openapi', ['--stats' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Total routes');
});

test('generated spec contains required openapi fields', function () {
    $generator = app(OpenApiGenerator::class);
    $spec = $generator->generate();

    expect($spec)->toHaveKeys(['openapi', 'info', 'paths', 'components']);
    expect($spec['openapi'])->toBe('3.1.0');
    expect($spec['info']['title'])->toBe('PublishLayer API');
    expect($spec['info']['version'])->toBe('1.0.0');
});

test('generated spec contains security schemes', function () {
    $generator = app(OpenApiGenerator::class);
    $spec = $generator->generate();

    expect($spec['components']['securitySchemes'])->toHaveKey('bearerAuth');
    expect($spec['components']['securitySchemes']['bearerAuth']['type'])->toBe('http');
    expect($spec['components']['securitySchemes']['bearerAuth']['scheme'])->toBe('bearer');
});

test('generated spec contains documented paths', function () {
    $generator = app(OpenApiGenerator::class);
    $spec = $generator->generate();

    expect($spec['paths'])->not->toBeEmpty();

    // Check for some expected endpoints
    expect(array_keys($spec['paths']))->toContain('/me');
    expect(array_keys($spec['paths']))->toContain('/briefs');
    expect(array_keys($spec['paths']))->toContain('/drafts');
    expect(array_keys($spec['paths']))->toContain('/destinations');
});

test('generate-openapi command supports json format', function () {
    $outputPath = base_path('docs/openapi/publishlayer.json');

    $this->artisan('publishlayer:generate-openapi', [
        '--format' => 'json',
        '--output' => 'docs/openapi/publishlayer.json',
    ])->assertSuccessful();

    expect(File::exists($outputPath))->toBeTrue();

    // Verify it's valid JSON
    $content = File::get($outputPath);
    $decoded = json_decode($content, true);
    expect($decoded)->not->toBeNull();
    expect($decoded['openapi'])->toBe('3.1.0');

    // Clean up
    File::delete($outputPath);
});

test('validation fails with missing references', function () {
    $generator = app(OpenApiGenerator::class);

    $invalidSpec = [
        'openapi' => '3.1.0',
        'info' => ['title' => 'Test', 'version' => '1.0'],
        'paths' => [
            '/test' => [
                'get' => [
                    'responses' => [
                        '200' => [
                            'content' => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/NonExistent'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'components' => ['schemas' => []],
    ];

    $errors = $generator->validate($invalidSpec);

    expect($errors)->not->toBeEmpty();
});
