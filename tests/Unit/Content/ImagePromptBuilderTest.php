<?php

use App\Models\BrandVoice;
use App\Models\CompanyProfile;
use App\Models\Content;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Ai\ImageGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('builds a consistent featured image prompt without text overlay instructions', function () {
    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Prompt Workspace',
        'organization_id' => Organization::query()->create([
            'name' => 'Prompt Org',
            'slug' => 'prompt-org-'.Str::random(6),
            'status' => 'active',
            'approved_at' => now(),
        ])->id,
    ]);

    CompanyProfile::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'company_name' => 'Prompt Co',
        'industry' => 'SaaS',
        'target_audience' => 'Marketing leaders',
    ]);

    $voice = BrandVoice::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'organization_id' => $workspace->organization_id,
        'name' => 'Brand Voice',
        'tone_of_voice' => 'confident and practical',
        'is_default' => true,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'title' => 'How to Scale Content Operations',
        'primary_keyword' => 'content operations',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
        'brand_voice_id' => $voice->id,
    ]);

    $prompt = app(ImageGenerationService::class)->buildPrompt($content);

    expect($prompt)
        ->toBeString()
        ->and($prompt)->toContain('No text overlay')
        ->and($prompt)->toContain('professional, modern, clean')
        ->and($prompt)->toContain('no logos');
});

it('includes custom image prompt instructions when configured on content', function () {
    $workspace = Workspace::query()->create([
        'id' => (string) Str::uuid(),
        'name' => 'Prompt Workspace',
        'organization_id' => Organization::query()->create([
            'name' => 'Prompt Org',
            'slug' => 'prompt-org-'.Str::random(6),
            'status' => 'active',
            'approved_at' => now(),
        ])->id,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'title' => 'How to Scale Content Operations',
        'primary_keyword' => 'content operations',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
        'image_prompt_instructions' => 'No text, cinematic lighting, blue palette.',
    ]);

    $prompt = app(ImageGenerationService::class)->buildPrompt($content);

    expect($prompt)
        ->toContain('Custom image direction (highest priority): No text, cinematic lighting, blue palette.');
});
