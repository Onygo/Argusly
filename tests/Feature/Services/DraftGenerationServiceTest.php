<?php

use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use App\Models\ClientSite;
use App\Services\Credits\GenerationPricing;
use App\Services\CreditWalletService;
use App\Services\DraftGenerationService;
use App\Services\Llm\LlmManager;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->creditsMock = Mockery::mock(CreditWalletService::class);
    $this->service = new DraftGenerationService($this->creditsMock, app(LlmManager::class), app(GenerationPricing::class));
});

function createMockedDraft(array $attributes = []): Draft
{
    $defaults = [
        'id' => 'draft-123',
        'client_site_id' => 'site-456',
        'brief_id' => null,
        'title' => 'Test Article',
        'output_type' => 'kb_article',
        'status' => 'ready',
        'credit_cost' => 10,
        'meta' => [
            'language' => 'en',
            'tone' => 'professional',
            'length' => '800-1000 words',
            'primary_keyword' => 'testing',
            'secondary_keywords' => ['unit tests', 'pest'],
        ],
        'links' => [],
    ];

    $data = array_merge($defaults, $attributes);

    $draft = new Draft($data);

    // Set relation placeholders to avoid database queries
    $draft->setRelation('brief', null);
    $organization = new Organization([
        'name' => 'Test Org',
        'slug' => 'test-org',
        'status' => 'active',
    ]);
    $organization->setRelation('brandVoices', collect());

    $workspace = new Workspace([
        'name' => 'Test Workspace',
    ]);
    $workspace->setRelation('organization', $organization);
    $workspace->setRelation('companyProfile', null);
    $workspace->setRelation('brandVoices', collect());

    $clientSite = new ClientSite([
        'id' => $data['client_site_id'],
        'site_url' => 'https://example.com',
    ]);
    $clientSite->setRelation('workspace', $workspace);
    $draft->setRelation('clientSite', $clientSite);

    // Mock save to prevent database operations
    $mock = Mockery::mock($draft)->makePartial()->shouldAllowMockingProtectedMethods();
    $mock->shouldReceive('save')->andReturn(true);
    $mock->shouldReceive('performUpdate')->andReturn(true);
    $mock->shouldReceive('performInsert')->andReturn(true);

    // Preserve all attributes
    foreach ($data as $key => $value) {
        $mock->{$key} = $value;
    }

    // Set relations again on mock
    $mock->setRelation('brief', null);
    $mock->setRelation('clientSite', $clientSite);

    return $mock;
}

function validOpenAiResponse(): array
{
    return [
        'output_text' => json_encode([
            'title' => 'Test Article Title',
            'meta' => [
                'description' => 'A short meta description for SEO purposes.',
                'keywords' => ['testing', 'unit tests'],
            ],
            'sections' => [
                [
                    'heading' => 'Introduction',
                    'html' => '<p>This is the introduction paragraph with enough content to pass validation. ' .
                        str_repeat('Lorem ipsum dolor sit amet. ', 20) . '</p>',
                ],
                [
                    'heading' => 'Main Content',
                    'html' => '<p>This is the main content section with detailed information. ' .
                        str_repeat('Consectetur adipiscing elit. ', 20) . '</p>',
                ],
            ],
            'links' => [
                ['href' => 'https://example.com', 'anchor' => 'Example', 'rel' => null],
            ],
        ]),
    ];
}

describe('generate', function () {
    it('generates content successfully with valid response', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);
        config(['llm.providers.openai.default_model' => 'gpt-4']);

        Http::fake([
            '*/v1/responses' => Http::response(validOpenAiResponse()),
        ]);

        $draft = createMockedDraft();
        $result = $this->service->generate($draft);

        expect($result)
            ->toHaveKey('title')
            ->toHaveKey('content_html')
            ->toHaveKey('meta')
            ->toHaveKey('links');

        expect($result['title'])->toBe('Test Article Title');
        expect($result['content_html'])->not->toContain('<h2>Introduction</h2>');
        expect($result['content_html'])->toContain('<h2>Main Content</h2>');
    });

    it('throws exception when OpenAI API key is not set', function () {
        config(['llm.providers.openai.api_key' => null]);

        $draft = createMockedDraft();
        $this->service->generate($draft);
    })->throws(RuntimeException::class, 'OPENAI_API_KEY is not set.');

    it('throws exception when OpenAI returns empty response', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::response(['output_text' => '']),
        ]);

        $draft = createMockedDraft();
        $this->service->generate($draft);
    })->throws(RuntimeException::class, 'LLM returned an empty response.');

    it('throws exception when response is not valid JSON', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::response(['output_text' => 'not valid json']),
        ]);

        $draft = createMockedDraft();
        $this->service->generate($draft);
    })->throws(RuntimeException::class, 'Response was not valid JSON.');

    it('throws exception when title is missing', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'title' => '',
                    'sections' => [['heading' => 'Test', 'html' => '<p>Content</p>']],
                ]),
            ]),
        ]);

        $draft = createMockedDraft();
        $this->service->generate($draft);
    })->throws(RuntimeException::class, 'Missing title in result JSON.');

    it('throws exception when sections are missing', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'title' => 'Test Title',
                    'sections' => [],
                ]),
            ]),
        ]);

        $draft = createMockedDraft();
        $this->service->generate($draft);
    })->throws(RuntimeException::class, 'Missing sections in result JSON.');

    it('throws exception when section is missing heading', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'title' => 'Test Title',
                    'sections' => [['heading' => '', 'html' => '<p>Content</p>']],
                ]),
            ]),
        ]);

        $draft = createMockedDraft();
        $this->service->generate($draft);
    })->throws(RuntimeException::class, 'Section is missing heading or html.');

    it('throws exception when content is too short', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'title' => 'Test Title',
                    'sections' => [['heading' => 'Intro', 'html' => '<p>Short</p>']],
                ]),
            ]),
        ]);

        $draft = createMockedDraft();
        $this->service->generate($draft);
    })->throws(RuntimeException::class, 'Generated content seems too short.');

    it('sanitizes meta descriptions that exceed 155 characters', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'title' => 'Test Title',
                    'meta' => [
                        'description' => str_repeat('a', 160),
                    ],
                    'sections' => [
                        [
                            'heading' => 'Intro',
                            'html' => '<p>' . str_repeat('Content here. ', 50) . '</p>',
                        ],
                    ],
                ]),
            ]),
        ]);

        $draft = createMockedDraft();
        $result = $this->service->generate($draft);

        expect(mb_strlen((string) data_get($result, 'meta.description')))->toBeLessThanOrEqual(155);
    });

    it('handles OpenAI API failure', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::response(['error' => 'Rate limit exceeded'], 429),
        ]);

        $draft = createMockedDraft();
        $this->service->generate($draft);
    })->throws(RuntimeException::class);

    it('strips markdown code fences from response', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        $jsonContent = json_encode([
            'title' => 'Test Title',
            'meta' => ['description' => 'Description', 'keywords' => []],
            'sections' => [
                [
                    'heading' => 'Intro',
                    'html' => '<p>' . str_repeat('Content text here. ', 30) . '</p>',
                ],
            ],
            'links' => [],
        ]);

        Http::fake([
            '*/v1/responses' => Http::response([
                'output_text' => "```json\n{$jsonContent}\n```",
            ]),
        ]);

        $draft = createMockedDraft();
        $result = $this->service->generate($draft);

        expect($result['title'])->toBe('Test Title');
    });

    it('extracts text from nested output structure', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        $jsonContent = json_encode([
            'title' => 'Nested Title',
            'meta' => ['description' => 'Description', 'keywords' => []],
            'sections' => [
                [
                    'heading' => 'Section',
                    'html' => '<p>' . str_repeat('Long content here. ', 30) . '</p>',
                ],
            ],
            'links' => [],
        ]);

        Http::fake([
            '*/v1/responses' => Http::response([
                'output' => [
                    [
                        'content' => [
                            ['type' => 'output_text', 'text' => $jsonContent],
                        ],
                    ],
                ],
            ]),
        ]);

        $draft = createMockedDraft();
        $result = $this->service->generate($draft);

        expect($result['title'])->toBe('Nested Title');
    });

    it('removes duplicate leading heading from section html', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'title' => 'Dedup heading title',
                    'meta' => ['description' => 'Description', 'keywords' => []],
                    'sections' => [
                        [
                            'heading' => 'Onze AI strategie Agentic: architectuur boven technologie',
                            'html' => '<h2>Onze AI strategie Agentic: architectuur boven technologie</h2><p>' .
                                str_repeat('Content text here. ', 30) . '</p>',
                        ],
                    ],
                    'links' => [],
                ]),
            ]),
        ]);

        $draft = createMockedDraft();
        $result = $this->service->generate($draft);

        expect(substr_count($result['content_html'], '<h2>Onze AI strategie Agentic: architectuur boven technologie</h2>'))->toBe(1);
    });

    it('does not render opening as leading h2 for the first section', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'title' => 'Opening Test',
                    'meta' => ['description' => 'Description', 'keywords' => []],
                    'sections' => [
                        [
                            'heading' => 'Opening',
                            'html' => '<p>' . str_repeat('Opening paragraph content. ', 30) . '</p>',
                        ],
                        [
                            'heading' => 'Main Content',
                            'html' => '<p>' . str_repeat('Main body content. ', 30) . '</p>',
                        ],
                    ],
                    'links' => [],
                ]),
            ]),
        ]);

        $draft = createMockedDraft();
        $result = $this->service->generate($draft);

        expect($result['content_html'])->not->toContain('<h2>Opening</h2>');
        expect($result['content_html'])->toContain('<h2>Main Content</h2>');
    });

    it('uses default values when draft meta is incomplete', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::response(validOpenAiResponse()),
        ]);

        $draft = createMockedDraft(['meta' => []]);
        $result = $this->service->generate($draft);

        expect($result)->toHaveKey('title');
    });
});

describe('generateWithRepair', function () {
    it('returns result on first successful attempt', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::response(validOpenAiResponse()),
        ]);

        $draft = createMockedDraft();
        $result = $this->service->generateWithRepair($draft, 2);

        expect($result)->toHaveKey('title');
        Http::assertSentCount(1);
    });

    it('retries on failure and succeeds on second attempt', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::sequence()
                ->push(['output_text' => 'invalid json'])
                ->push(validOpenAiResponse()),
        ]);

        $draft = createMockedDraft();
        $result = $this->service->generateWithRepair($draft, 2);

        expect($result)->toHaveKey('title');
        Http::assertSentCount(2);
    });

    it('throws exception after max passes exceeded', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::response(['output_text' => 'invalid json']),
        ]);

        $draft = createMockedDraft();
        $this->service->generateWithRepair($draft, 2);
    })->throws(RuntimeException::class, 'Response was not valid JSON.');

    it('stores repair info in meta after failed attempt', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::sequence()
                ->push(['output_text' => 'invalid json'])
                ->push(validOpenAiResponse()),
        ]);

        $draft = createMockedDraft();
        $this->service->generateWithRepair($draft, 2);

        expect($draft->meta)->toHaveKey('repair_hint');
    });
});

describe('payload building', function () {
    it('includes correct model in payload', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);
        config(['llm.providers.openai.default_model' => 'gpt-4-turbo']);

        Http::fake([
            '*/v1/responses' => Http::response(validOpenAiResponse()),
        ]);

        $draft = createMockedDraft();
        $this->service->generate($draft);

        Http::assertSent(function ($request) {
            return $request['model'] === 'gpt-4-turbo';
        });
    });

    it('includes system and user prompts in payload', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::response(validOpenAiResponse()),
        ]);

        $draft = createMockedDraft();
        $this->service->generate($draft);

        Http::assertSent(function ($request) {
            $input = $request['input'] ?? [];
            $hasSystem = collect($input)->contains(fn ($m) => $m['role'] === 'system');
            $hasUser = collect($input)->contains(fn ($m) => $m['role'] === 'user');
            return $hasSystem && $hasUser;
        });
    });

    it('includes topic in user prompt', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::response(validOpenAiResponse()),
        ]);

        $draft = createMockedDraft(['title' => 'My Custom Topic']);
        $this->service->generate($draft);

        Http::assertSent(function ($request) {
            $userPrompt = collect($request['input'])->firstWhere('role', 'user')['content'] ?? '';
            return str_contains($userPrompt, 'My Custom Topic');
        });
    });

    it('includes primary keyword in user prompt when set', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);

        Http::fake([
            '*/v1/responses' => Http::response(validOpenAiResponse()),
        ]);

        $draft = createMockedDraft([
            'meta' => ['primary_keyword' => 'SEO optimization'],
        ]);
        $this->service->generate($draft);

        Http::assertSent(function ($request) {
            $userPrompt = collect($request['input'])->firstWhere('role', 'user')['content'] ?? '';
            return str_contains($userPrompt, 'Primary keyword: SEO optimization');
        });
    });

    it('uses standard output token budget by default', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);
        config(['llm.default_provider' => 'openai']);
        config(['credits.generation_pricing.article.baseline_output_tokens' => 8000]);

        Http::fake([
            '*/v1/responses' => Http::response(validOpenAiResponse()),
        ]);

        $draft = createMockedDraft(['meta' => ['generation_type' => 'article']]);
        $this->service->generate($draft);

        Http::assertSent(function ($request) {
            return (int) ($request['max_output_tokens'] ?? 0) === 8000;
        });
    });

    it('uses requested long output token budget when provided', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);
        config(['llm.default_provider' => 'openai']);
        config(['credits.generation_pricing.article.max_output_tokens' => 14000]);
        config(['credits.llm_output_caps.openai.default' => 12000]);

        Http::fake([
            '*/v1/responses' => Http::response(validOpenAiResponse()),
        ]);

        $draft = createMockedDraft([
            'meta' => [
                'generation_type' => 'article',
                'requested_max_output_tokens' => 12000,
            ],
        ]);
        $this->service->generate($draft);

        Http::assertSent(function ($request) {
            return (int) ($request['max_output_tokens'] ?? 0) === 12000;
        });
    });

    it('caps requested output tokens to provider limits', function () {
        config(['llm.providers.openai.api_key' => 'test-api-key']);
        config(['llm.default_provider' => 'openai']);
        config(['credits.generation_pricing.article.max_output_tokens' => 14000]);
        config(['credits.llm_output_caps.openai.default' => 12000]);

        $capturedPayloads = [];
        Http::fake(function ($request) use (&$capturedPayloads) {
            $capturedPayloads[] = $request->data();

            return Http::response(validOpenAiResponse());
        });

        $draft = createMockedDraft([
            'meta' => [
                'generation_type' => 'article',
                'requested_max_output_tokens' => 14000,
            ],
        ]);
        $this->service->generate($draft);

        expect($capturedPayloads)->not->toBeEmpty();

        $firstPayload = (array) ($capturedPayloads[0] ?? []);
        $effectiveMax = (int) ($firstPayload['max_output_tokens'] ?? $firstPayload['max_tokens'] ?? 0);
        expect($effectiveMax)->toBe(12000);
    });
});
