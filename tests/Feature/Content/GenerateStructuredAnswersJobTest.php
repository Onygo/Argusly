<?php

use App\Jobs\GenerateStructuredAnswersJob;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\ContentVersion;
use App\Models\Organization;
use App\Models\StructuredAnswerBlock;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\Data\LlmUsage;
use App\Services\Llm\LlmManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates answer blocks for normal content', function () {
    [, , $content] = makeStructuredAnswersContext();

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(structuredAnswersResponse([
        [
            'question' => 'What is answer engine optimization?',
            'answer' => 'Answer engine optimization structures content so AI systems can extract direct answers.',
            'entities' => ['Argusly', 'AI systems'],
            'platforms' => ['Google', 'ChatGPT'],
        ],
        [
            'question' => 'Why are answer blocks useful?',
            'answer' => 'Answer blocks make key responses explicit, which improves extraction for search and AI assistants.',
            'entities' => ['search', 'AI assistants'],
        ],
    ]));
    app()->instance(LlmManager::class, $llm);

    (new GenerateStructuredAnswersJob((string) $content->id))->handle(app(LlmManager::class));

    $content->refresh();

    expect($content->answerBlocks()->count())->toBe(2)
        ->and((string) $content->answer_block_generation_status)->toBe(Content::ANSWER_BLOCK_STATUS_COMPLETED)
        ->and((int) $content->answer_block_generation_persisted_count)->toBe(2)
        ->and((array) ($content->answerBlocks()->orderBy('order')->first()->platforms ?? []))->toBe(['Google', 'ChatGPT'])
        ->and((string) $content->answerBlocks()->orderBy('order')->first()->question)->toBe('What is answer engine optimization?');
});

it('can be instantiated safely and defaults to the generation queue', function () {
    $job = new GenerateStructuredAnswersJob((string) Str::uuid());

    expect($job->queue)->toBe('generation');
});

it('creates answer blocks on the translated target content instead of the source content', function () {
    [, , $source] = makeStructuredAnswersContext(locale: 'nl', sourceLocale: null, body: '<p>Nederlandse broninhoud.</p>');
    [, $user, $translated] = makeStructuredAnswersContext(
        locale: 'en',
        sourceLocale: 'nl',
        body: '<p>English localized content about workflow automation.</p>',
        translationSourceContentId: (string) $source->id,
        userEmailPrefix: 'structured-answers-en'
    );

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(structuredAnswersResponse([
        [
            'question' => 'What does workflow automation improve?',
            'answer' => 'Workflow automation reduces manual handoffs and gives teams faster publishing cycles.',
            'entities' => ['workflow automation', 'publishing cycles'],
        ],
    ]));
    app()->instance(LlmManager::class, $llm);

    $this->actingAs($user);
    (new GenerateStructuredAnswersJob((string) $translated->id))->handle(app(LlmManager::class));

    expect($translated->fresh()->answerBlocks()->count())->toBe(1)
        ->and($source->fresh()->answerBlocks()->count())->toBe(0)
        ->and((string) $translated->fresh()->answerBlocks()->first()->content_id)->toBe((string) $translated->id);
});

it('falls back to safe blocks when the ai response is empty', function () {
    $this->withoutMiddleware();

    [$workspace, $user, $content] = makeStructuredAnswersContext();

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: '[]',
        json: [],
        usage: new LlmUsage(120, 40, 160),
        modelUsed: 'gpt-4.1-mini',
        providerName: 'openai',
        requestId: 'req-structured-answers-empty',
    ));
    app()->instance(LlmManager::class, $llm);

    (new GenerateStructuredAnswersJob((string) $content->id))->handle(app(LlmManager::class));

    $content->refresh();

    expect($content->answerBlocks()->count())->toBe(3)
        ->and((string) $content->answer_block_generation_status)->toBe(Content::ANSWER_BLOCK_STATUS_COMPLETED_WITH_WARNING)
        ->and((string) $content->answer_block_generation_last_warning)->toContain('AI response was empty')
        ->and((string) data_get($content->answer_block_generation_meta, 'failure_reason'))->toBe('ai_response_empty');

    $this->actingAs($user)
        ->getJson(route('app.content.answers', $content))
        ->assertOk()
        ->assertJsonPath('generation.status', Content::ANSWER_BLOCK_STATUS_COMPLETED_WITH_WARNING)
        ->assertJsonPath('generation.last_warning', 'AI response was empty. Fallback answer blocks were created from the draft content.');
});

it('returns newly created answer blocks through the content answers endpoint', function () {
    $this->withoutMiddleware();

    [, $user, $content] = makeStructuredAnswersContext();

    StructuredAnswerBlock::query()->create([
        'content_id' => (string) $content->id,
        'question' => 'What are answer blocks?',
        'answer' => 'Answer blocks are short question-and-answer sections saved on the content item.',
        'entities' => ['answer blocks'],
        'platforms' => ['Google', 'ChatGPT', 'Perplexity'],
        'order' => 0,
    ]);

    $content->forceFill([
        'answer_block_generation_status' => Content::ANSWER_BLOCK_STATUS_COMPLETED,
        'answer_block_generation_persisted_count' => 1,
        'answer_block_generation_completed_at' => now(),
    ])->saveQuietly();

    $this->actingAs($user)
        ->getJson(route('app.content.answers', $content))
        ->assertOk()
        ->assertJsonPath('answers.0.question', 'What are answer blocks?')
        ->assertJsonPath('answers.0.platforms.0', 'Google')
        ->assertJsonPath('generation.status', Content::ANSWER_BLOCK_STATUS_COMPLETED)
        ->assertJsonPath('generation.persisted_blocks_count', 1);
});

it('queues answer block generation from the content detail action without a server error', function () {
    $this->withoutMiddleware();

    Queue::fake();

    [, $user, $content] = makeStructuredAnswersContext();

    $response = $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $content, 'tab' => 'answers']))
        ->post(route('app.content.answer-blocks.generate', $content));

    $response->assertRedirect(route('app.content.show', ['content' => $content, 'tab' => 'answers']))
        ->assertSessionHas('status', 'Structured answer generation queued.');

    Queue::assertPushed(GenerateStructuredAnswersJob::class, function (GenerateStructuredAnswersJob $job) use ($content): bool {
        return $job->contentId === (string) $content->id
            && $job->queue === 'generation';
    });
});

it('replaces existing generated answer blocks without duplicates when rerun', function () {
    [, , $content] = makeStructuredAnswersContext();

    StructuredAnswerBlock::query()->create([
        'content_id' => (string) $content->id,
        'question' => 'Old question',
        'answer' => 'Old answers are removed before new generated blocks are persisted.',
        'entities' => ['old'],
        'platforms' => ['Google'],
        'order' => 0,
    ]);

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(structuredAnswersResponse([
        [
            'question' => 'What is localization workflow automation?',
            'answer' => 'Localization workflow automation keeps translated content synchronized across editorial steps.',
            'entities' => ['localization'],
        ],
        [
            'question' => 'How do answer blocks help teams?',
            'answer' => 'Answer blocks help teams publish clear question-and-answer sections for AI retrieval.',
            'entities' => ['teams', 'AI retrieval'],
        ],
    ]));
    app()->instance(LlmManager::class, $llm);

    (new GenerateStructuredAnswersJob((string) $content->id))->handle(app(LlmManager::class));

    $questions = $content->fresh()->answerBlocks()->orderBy('order')->pluck('question')->all();

    expect($questions)->toBe([
        'What is localization workflow automation?',
        'How do answer blocks help teams?',
    ]);
});

it('parses markdown fenced json responses', function () {
    [, , $content] = makeStructuredAnswersContext();

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(new LlmResponse(
        text: <<<TEXT
```json
[
  {"question":"What is AEO?","answer":"AEO helps AI systems extract direct answers.","platforms":["Google"]}
]
```
TEXT,
        json: null,
        usage: new LlmUsage(100, 20, 120),
        modelUsed: 'gpt-4.1-mini',
        providerName: 'openai',
        requestId: 'req-fenced-json',
    ));
    app()->instance(LlmManager::class, $llm);

    (new GenerateStructuredAnswersJob((string) $content->id))->handle(app(LlmManager::class));

    expect($content->fresh()->answerBlocks()->count())->toBe(1)
        ->and((string) $content->fresh()->answerBlocks()->first()->question)->toBe('What is AEO?');
});

it('parses answer_blocks root keys', function () {
    [, , $content] = makeStructuredAnswersContext();

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(structuredAnswersResponse([
        'answer_blocks' => [
            [
                'title' => 'What is structured publishing?',
                'body' => 'Structured publishing turns long content into reusable answers.',
                'targets' => ['Perplexity'],
            ],
        ],
    ]));
    app()->instance(LlmManager::class, $llm);

    (new GenerateStructuredAnswersJob((string) $content->id))->handle(app(LlmManager::class));

    $block = $content->fresh()->answerBlocks()->first();

    expect($content->fresh()->answerBlocks()->count())->toBe(1)
        ->and((string) $block->question)->toBe('What is structured publishing?')
        ->and((array) ($block->platforms ?? []))->toBe(['Perplexity']);
});

it('keeps valid blocks when one malformed block is returned', function () {
    [, , $content] = makeStructuredAnswersContext();

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(structuredAnswersResponse([
        [
            'question' => '',
            'answer' => 'This block is invalid because it has no question.',
        ],
        [
            'question' => 'What is a valid answer block?',
            'answer' => 'A valid answer block has a question and an answer.',
        ],
    ]));
    app()->instance(LlmManager::class, $llm);

    (new GenerateStructuredAnswersJob((string) $content->id))->handle(app(LlmManager::class));

    $content->refresh();

    expect($content->answerBlocks()->count())->toBe(1)
        ->and((string) $content->answerBlocks()->first()->question)->toBe('What is a valid answer block?')
        ->and((int) data_get($content->answer_block_generation_meta, 'rejection_reasons.missing_question'))->toBe(1);
});

it('stores a clear failure reason when all ai blocks are rejected', function () {
    [, , $content] = makeStructuredAnswersContext();

    $llm = \Mockery::mock(LlmManager::class);
    $llm->shouldReceive('generateJson')->once()->andReturn(structuredAnswersResponse([
        [
            'question' => '',
            'answer' => '',
        ],
    ]));
    app()->instance(LlmManager::class, $llm);

    (new GenerateStructuredAnswersJob((string) $content->id))->handle(app(LlmManager::class));

    $content->refresh();

    expect((string) $content->answer_block_generation_status)->toBe(Content::ANSWER_BLOCK_STATUS_COMPLETED_WITH_WARNING)
        ->and((string) data_get($content->answer_block_generation_meta, 'failure_reason'))->toBe('all_blocks_rejected')
        ->and((string) $content->answer_block_generation_last_warning)->toContain('Fallback answer blocks');
});

it('shows generated blocks on the content detail page relation', function () {
    $this->withoutMiddleware();

    [, $user, $content] = makeStructuredAnswersContext();

    StructuredAnswerBlock::query()->create([
        'content_id' => (string) $content->id,
        'question' => 'What appears on the detail page?',
        'answer' => 'The content detail page reads answer blocks through the answerBlocks relation.',
        'entities' => ['detail page'],
        'platforms' => ['Google'],
        'order' => 0,
    ]);

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $content, 'tab' => 'answers']))
        ->assertOk()
        ->assertSee('What appears on the detail page?');
});

function makeStructuredAnswersContext(
    string $locale = 'en',
    ?string $sourceLocale = 'en',
    string $body = '<p>Structured answers body content for Argusly workflows.</p>',
    ?string $translationSourceContentId = null,
    string $userEmailPrefix = 'structured-answers'
): array {
    $organization = Organization::query()->create([
        'name' => 'Structured Answers Org ' . Str::random(4),
        'slug' => 'structured-answers-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Structured Answers Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en', 'nl'],
    ]);

    $site = ClientSite::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Structured Answers Site',
        'site_url' => 'https://structured-answers.example.com',
        'allowed_domains' => ['structured-answers.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Structured Answers User',
        'organization_id' => $workspace->organization_id,
        'password' => bcrypt('secret'),
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
        'email' => $userEmailPrefix . '-' . Str::lower(Str::random(6)) . '@example.com',
    ]);

    $content = Content::withoutEvents(fn () => Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Structured Answers Content',
        'language' => $locale,
        'translation_source_locale' => $sourceLocale,
        'translation_source_content_id' => $translationSourceContentId,
        'is_source_locale' => $translationSourceContentId === null,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'publish_status' => 'draft',
        'primary_keyword' => 'structured answers',
        'created_by' => (int) $user->id,
        'updated_by' => (int) $user->id,
    ]));

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'draft',
        'body' => $body,
        'source' => 'pl',
        'created_by' => (int) $user->id,
    ]);

    $revision = ContentRevision::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'revision_number' => 1,
        'label' => 'R1',
        'content_html' => $body,
        'is_active' => true,
    ]);

    Content::withoutEvents(function () use ($content, $version, $revision): void {
        $content->forceFill([
            'current_version_id' => (string) $version->id,
            'current_revision_id' => (string) $revision->id,
        ])->save();
    });

    return [$workspace, $user, $content->fresh(['answerBlocks'])];
}

function structuredAnswersResponse(array $items): LlmResponse
{
    return new LlmResponse(
        text: json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
        json: $items,
        usage: new LlmUsage(150, 70, 220),
        modelUsed: 'gpt-4.1-mini',
        providerName: 'openai',
        requestId: 'req-structured-answers-' . Str::lower(Str::random(6)),
    );
}
