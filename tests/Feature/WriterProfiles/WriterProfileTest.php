<?php

use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WriterProfile;
use App\Services\DraftGenerationService;
use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\Data\LlmUsage;
use App\Services\Llm\LlmManager;
use App\Services\WriterProfiles\WriterProfileAnalysisService;
use App\Services\WriterProfiles\WriterProfileFitService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(\App\Http\Middleware\EnsureBillingOnboardingCompleted::class);
});

it('creates a writer profile and does not retain source text when privacy is off', function (): void {
    [$user, $workspace] = writerProfileUserAndWorkspace();
    $this->mock(LlmManager::class, function ($mock): void {
        $mock->shouldReceive('generateJson')->once()->andReturn(writerProfileLlmResponse());
    });

    $this->actingAs($user)
        ->post(route('app.brand.writer-profiles.store'), [
            'name' => 'Practical editor',
            'source_type' => 'uploaded_texts',
            'profile_scope' => 'author',
            'source_texts' => str_repeat('This is a practical paragraph with clear structure and direct advice. ', 10),
        ])
        ->assertSessionHasNoErrors();

    $profile = WriterProfile::query()->where('workspace_id', $workspace->id)->firstOrFail();

    expect($profile->tone_summary)->toBe('Direct and practical.')
        ->and($profile->sources)->toHaveCount(1)
        ->and($profile->sources->first()->source_text)->toBeNull();
});

it('keeps writer profiles tenant isolated in the customer UI', function (): void {
    [$user, $workspace] = writerProfileUserAndWorkspace();
    [, $otherWorkspace] = writerProfileUserAndWorkspace('other@example.test', 'Other Org');

    WriterProfile::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Visible profile',
        'source_type' => 'manual',
        'profile_scope' => 'author',
        'status' => WriterProfile::STATUS_ACTIVE,
    ]);
    WriterProfile::query()->create([
        'workspace_id' => $otherWorkspace->id,
        'name' => 'Hidden profile',
        'source_type' => 'manual',
        'profile_scope' => 'author',
        'status' => WriterProfile::STATUS_ACTIVE,
    ]);

    $this->actingAs($user)
        ->get(route('app.brand.writer-profiles'))
        ->assertOk()
        ->assertSee('Visible profile')
        ->assertDontSee('Hidden profile');
});

it('parses analysis output and injects writer profile without overriding brand facts', function (): void {
    [$user, $workspace] = writerProfileUserAndWorkspace();
    $site = \App\Models\ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Site',
        'type' => 'wordpress',
        'site_url' => 'https://example.test',
        'allowed_domains' => ['example.test'],
        'is_active' => true,
    ]);
    $profile = WriterProfile::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Practical profile',
        'source_type' => 'manual',
        'profile_scope' => 'author',
        'status' => WriterProfile::STATUS_ACTIVE,
        'tone_summary' => 'Direct and concrete',
        'writing_style_summary' => 'Short paragraphs',
        'dont_rules' => ['Do not copy source wording.'],
    ]);
    $content = Content::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Brand fact article',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'writer_profile_id' => $profile->id,
    ]);
    $brief = \App\Models\Brief::query()->create([
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'ready',
        'title' => 'Brand fact article',
        'language' => 'en',
    ]);
    $draft = Draft::query()->create([
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Brand fact article',
        'output_type' => 'kb_article',
        'meta' => [],
    ]);

    $payload = app(DraftGenerationService::class)->buildGenerationPayloadForDraft($draft);

    expect($payload['system'])->toContain('Writer profile')
        ->and($payload['system'])->toContain('Do not reuse unique sentences')
        ->and($payload['system'])->toContain('Company:')
        ->and($payload['system'])->toContain('lower priority than campaign, brand, persona');
});

it('scores generated content fit and flags improvement points', function (): void {
    [$user, $workspace] = writerProfileUserAndWorkspace();
    $profile = WriterProfile::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Practical profile',
        'source_type' => 'manual',
        'profile_scope' => 'author',
        'status' => WriterProfile::STATUS_ACTIVE,
        'tone_summary' => 'Direct, concrete and practical',
        'writing_style_summary' => 'Short paragraphs with action',
        'vocabulary_notes' => 'practical action clear',
    ]);

    $score = app(WriterProfileFitService::class)->score($profile, '<p>This is practical and clear.</p><p>Use this action to improve the draft.</p>');

    expect($score['score'])->toBeGreaterThan(60)
        ->and($score['overfitting_risk'])->toBe(0);
});

it('applies a default writer profile to social post generation', function (): void {
    [$user, $workspace] = writerProfileUserAndWorkspace();
    WriterProfile::query()->create([
        'workspace_id' => $workspace->id,
        'name' => 'LinkedIn profile',
        'source_type' => 'manual',
        'profile_scope' => 'company',
        'status' => WriterProfile::STATUS_ACTIVE,
        'tone_summary' => 'Direct and concise',
        'channel_defaults' => ['linkedin' => true],
    ]);

    $variant = \App\Models\SocialPostVariant::query()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'platform' => 'linkedin',
        'post_type' => 'text',
        'variant_type' => 'test',
        'status' => 'draft',
        'variant_number' => 1,
        'generation_prompt_context' => ['language' => 'en'],
    ]);

    $this->mock(LlmManager::class, function ($mock): void {
        $mock->shouldReceive('generateJson')->once()->withArgs(function ($request): bool {
            return str_contains($request->messages[0]->content, 'LinkedIn profile');
        })->andReturn(new LlmResponse(
            text: '{}',
            json: ['hook' => 'Practical hook', 'body' => 'Short useful body.', 'hashtags' => ['content'], 'mentions' => [], 'quality_score' => 82],
            usage: new LlmUsage(100, 80, 180),
            modelUsed: 'gpt-test',
            providerName: 'openai',
        ));
    });

    $result = app(\App\Services\SocialDistribution\SocialPostVariantGenerationProvider::class)->generate($variant);

    expect($result['hook'])->toBe('Practical hook');
});

function writerProfileUserAndWorkspace(string $email = 'owner@example.test', string $organizationName = 'Acme'): array
{
    $organization = Organization::query()->create([
        'name' => $organizationName,
        'slug' => \Illuminate\Support\Str::slug($organizationName).'-'.\Illuminate\Support\Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);
    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => $organizationName.' Workspace',
    ]);
    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'email' => $email,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    return [$user, $workspace];
}

function writerProfileLlmResponse(): LlmResponse
{
    return new LlmResponse(
        text: '{}',
        json: [
            'tone_summary' => 'Direct and practical.',
            'writing_style_summary' => 'Short paragraphs with useful transitions.',
            'structure_summary' => 'Problem, insight, action.',
            'vocabulary_notes' => 'Plain professional words.',
            'formatting_preferences' => 'Short paragraphs.',
            'do_rules' => ['Be concrete.'],
            'dont_rules' => ['Avoid hype.'],
            'example_patterns' => ['Problem -> insight -> action.'],
            'confidence_score' => 0.82,
        ],
        usage: new LlmUsage(200, 120, 320),
        modelUsed: 'gpt-test',
        providerName: 'openai',
    );
}
