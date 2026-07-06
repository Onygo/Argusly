<?php

use App\Models\BrandVoice;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\TeamMember;
use App\Models\Workspace;
use App\Services\DraftGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('builds generation context with organization brand voice team member and selected length', function () {
    $organization = Organization::create([
        'name' => 'Org Context',
        'slug' => 'org-context-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'company_description' => 'Infodation helps organizations implement practical AI in software delivery.',
        'positioning_statement' => 'From hype to measurable value with a pragmatic engineering-first approach.',
        'target_audience' => 'CTOs, engineering managers, and founders.',
        'industry' => 'Technology',
    ]);

    $workspace = Workspace::create([
        'name' => 'Workspace Context',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Site',
        'site_url' => 'https://context.example.com',
        'allowed_domains' => ['context.example.com'],
        'is_active' => true,
    ]);

    $brandVoice = BrandVoice::create([
        'workspace_id' => $workspace->id,
        'organization_id' => $organization->id,
        'name' => 'Corporate Professional',
        'tone_of_voice' => 'Professional and confident',
        'writing_style' => 'Short clear paragraphs with practical examples',
        'do_rules' => 'Be concrete and outcome-focused',
        'dont_rules' => 'Avoid fluff and vague promises',
        'vocabulary_guidelines' => 'Use B2B and engineering terminology where relevant',
        'default_language' => 'en',
        'is_default' => true,
    ]);

    $teamMember = TeamMember::create([
        'organization_id' => $organization->id,
        'name' => 'Jane Doe',
        'role' => 'CTO',
        'expertise' => 'Software architecture and AI integration',
        'writing_perspective' => 'Hands-on leadership perspective',
        'personality_traits' => 'Pragmatic, clear, direct',
        'is_active' => true,
    ]);

    $content = Content::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'AI Strategy',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
        'brand_voice_id' => $brandVoice->id,
        'team_member_id' => $teamMember->id,
        'preferred_length' => 'long',
    ]);

    $brief = Brief::create([
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'queued',
        'progress' => 0,
        'title' => 'Brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::create([
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Draft',
        'output_type' => 'kb_article',
    ]);

    $context = app(DraftGenerationService::class)->buildSystemContextForDraft($draft);

    expect($context)->toContain('SYSTEM CONTEXT');
    expect($context)->toContain('Infodation helps organizations implement practical AI');
    expect($context)->toContain('Tone: Professional and confident');
    expect($context)->toContain('Name: Jane Doe');
    expect($context)->toContain('Write between 1400 and 1800 words.');
});

it('uses sensible defaults when company profile brand voice and team member are missing', function () {
    $organization = Organization::create([
        'name' => 'Org Empty',
        'slug' => 'org-empty-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Workspace Empty',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Site Empty',
        'site_url' => 'https://empty.example.com',
        'allowed_domains' => ['empty.example.com'],
        'is_active' => true,
    ]);

    $content = Content::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Fallback Content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
    ]);

    $brief = Brief::create([
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'queued',
        'progress' => 0,
        'title' => 'Brief Empty',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::create([
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Draft Empty',
        'output_type' => 'kb_article',
    ]);

    $context = app(DraftGenerationService::class)->buildSystemContextForDraft($draft);

    expect($context)->toContain('SYSTEM CONTEXT');
    expect($context)->toContain('Write from the company perspective.');
    expect($context)->toContain('Tone: Professional, clear, editorially specific, confident.');
    expect($context)->toContain('Write between 900 and 1200 words.');
});
