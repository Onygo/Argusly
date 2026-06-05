<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\ContentSource;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeSourceBriefingContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Source Brief Org',
        'slug' => 'source-brief-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Source Brief Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Source Brief Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Source Brief Site',
        'site_url' => 'https://brief-source.example.com',
        'allowed_domains' => ['brief-source.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'source-brief-plan'],
        [
            'name' => 'Source Brief Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $user = User::query()->create([
        'name' => 'Source Brief User',
        'email' => 'source-brief+' . Str::random(5) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$organization, $workspace, $site, $user];
}

function sampleArticleHtml(string $title = 'What is Answer Engine Optimization?'): string
{
    return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <title>{$title}</title>
    <meta name="description" content="A practical explanation of answer engine optimization for modern search and AI systems.">
    <link rel="canonical" href="https://example.com/aeo-guide">
    <meta property="article:published_time" content="2026-04-28T09:00:00Z">
    <meta name="author" content="Editor Example">
</head>
<body>
    <header>Navigation</header>
    <main>
        <article>
            <h1>{$title}</h1>
            <p>Answer engine optimization helps brands become the answer in AI systems, search experiences, and assistant results. Teams use it to improve discoverability, answer clarity, and structured content performance across digital channels.</p>
            <p>This article explains how structured content, entity coverage, direct answers, and content design shape AI visibility. It also covers common mistakes, measurement ideas, and strategic opportunities for content teams.</p>
            <h2>Why answer-first content matters</h2>
            <p>Answer-first content improves scanability for readers and helps machine systems identify direct responses. It also creates a stronger content foundation for zero-click search and AI answer surfaces.</p>
            <h2>How to structure content for AI systems</h2>
            <p>Use direct answers, question-led sections, concise summaries, and clear entities. Add examples, implementation notes, and decision criteria so the content supports original positioning instead of generic paraphrasing.</p>
            <h3>Common mistakes</h3>
            <p>Teams often mirror competitor headings, bury the answer, and skip examples. They also publish generic SEO pages without a differentiated perspective or a clear next step for the audience.</p>
            <p>Strong content should connect the topic to audience pains, internal expertise, and brand proof points. That approach creates more original material and more useful content outcomes for both readers and AI systems.</p>
        </article>
    </main>
    <footer>Footer</footer>
</body>
</html>
HTML;
}

function sampleMarketingSeoHtml(): string
{
    return <<<HTML
<!doctype html>
<html lang="nl">
<head>
    <title>GEO uitgelegd voor SEO teams</title>
    <meta name="description" content="Een praktische uitleg van GEO voor moderne SEO teams.">
</head>
<body>
    <header>Top navigation</header>
    <div class="page-shell">
        <div class="hero">Hero banner</div>
        <div class="content-wrap marketing-layout">
            <div class="share-links">Share</div>
            <div class="post-content">
                <h1>GEO uitgelegd voor SEO teams</h1>
                <p>Generative engine optimization helpt teams zichtbaar te blijven in AI-antwoorden. Het onderwerp draait niet alleen om rankings, maar ook om antwoordkwaliteit, entiteiten en structuur.</p>
                <p>Voor marketingteams is het relevant omdat zoekgedrag verschuift naar AI-systemen. Daardoor moeten artikelen sneller antwoord geven, duidelijker structureren en beter aansluiten op concrete vragen van prospects.</p>
                <h2>Wat is GEO?</h2>
                <p>GEO beschrijft hoe je content optimaliseert zodat AI-systemen de pagina beter begrijpen en eerder als bruikbare bron selecteren. Dat vraagt om directe definities, heldere koppen en een consistent semantisch kader.</p>
                <h2>Waarom is GEO belangrijk?</h2>
                <p>De opkomst van zero-click ervaringen verandert hoe content gevonden wordt. Bedrijven hebben daarom meer baat bij artikelen die antwoorden structureren, vragen afvangen en een eigen invalshoek bieden.</p>
                <h3>Praktische voorbeelden</h3>
                <p>Denk aan een uitlegartikel met definities, voorbeelden, veelgestelde vragen en concrete besliscriteria. Zo wordt het stuk nuttiger voor lezers en beter interpreteerbaar voor AI-systemen.</p>
                <p>Een goed GEO-artikel koppelt het onderwerp aan merkcontext, doelgroepvragen en implementatiekeuzes. Daarmee ontstaat originele content die meer is dan een samenvatting van wat concurrenten al publiceren.</p>
            </div>
            <aside>Sidebar callout</aside>
        </div>
    </div>
    <footer>Footer</footer>
</body>
</html>
HTML;
}

it('previews a valid URL source and persists extraction data', function () {
    [, $workspace, , $user] = makeSourceBriefingContext();

    Http::fake([
        'https://example.com/aeo' => Http::response(sampleArticleHtml(), 200, ['Content-Type' => 'text/html']),
    ]);

    $response = $this->actingAs($user)
        ->post(route('app.content.create.from-url.preview'), [
            'source_url' => 'https://example.com/aeo',
        ]);

    $response->assertRedirect();

    $source = ContentSource::query()->firstOrFail();

    expect((string) $source->workspace_id)->toBe((string) $workspace->id)
        ->and((string) $source->type)->toBe('url')
        ->and((string) $source->extraction_status)->toBe('extracted')
        ->and((string) $source->source_domain)->toBe('example.com')
        ->and((string) $source->source_language)->toBe('en')
        ->and((string) $source->source_title)->toContain('Answer Engine Optimization')
        ->and(trim((string) $source->extracted_text))->not->toBe('');
});

it('returns a clean error when extracted content is too thin', function () {
    [, , , $user] = makeSourceBriefingContext();

    Http::fake([
        'https://example.com/thin' => Http::response('<html><body><article><h1>Thin page</h1><p>Too short.</p></article></body></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $response = $this->from(route('app.content.create'))
        ->actingAs($user)
        ->post(route('app.content.create.from-url.preview'), [
            'source_url' => 'https://example.com/thin',
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors(['source_url']);

    $source = ContentSource::query()->firstOrFail();
    expect((string) $source->extraction_status)->toBe('failed');
});

it('accepts structured seo articles on generic marketing layouts without false thin detection', function () {
    [, $workspace, , $user] = makeSourceBriefingContext();

    Http::fake([
        'https://example.com/geo' => Http::response(sampleMarketingSeoHtml(), 200, ['Content-Type' => 'text/html']),
    ]);

    $response = $this->actingAs($user)
        ->post(route('app.content.create.from-url.preview'), [
            'source_url' => 'https://example.com/geo',
        ]);

    $response->assertRedirect();

    $source = ContentSource::query()->firstOrFail();

    expect((string) $source->workspace_id)->toBe((string) $workspace->id)
        ->and((string) $source->extraction_status)->toBe('extracted')
        ->and((string) $source->source_language)->toBe('nl')
        ->and((int) data_get($source->metadata_json, 'extraction.word_count', 0))->toBeGreaterThan(120)
        ->and((bool) data_get($source->metadata_json, 'extraction.quality.seo_article_structure', false))->toBeTrue()
        ->and((string) data_get($source->metadata_json, 'extraction.method', ''))->not->toBe('');
});

it('generates a source-based brief with keyword opportunities', function () {
    [, , , $user] = makeSourceBriefingContext();

    Http::fake([
        'https://example.com/aeo' => Http::response(sampleArticleHtml(), 200, ['Content-Type' => 'text/html']),
    ]);

    $this->actingAs($user)->post(route('app.content.create.from-url.preview'), [
        'source_url' => 'https://example.com/aeo',
    ]);

    $source = ContentSource::query()->firstOrFail();

    $response = $this->actingAs($user)->post(route('app.content.create.from-url.generate'), [
        'content_source_id' => (string) $source->id,
        'output_mode' => 'brief_keywords',
    ]);

    $response->assertRedirect();

    $source->refresh();

    expect((string) $source->extraction_status)->toBe('generated')
        ->and((string) data_get($source->generated_payload_json, 'brief.working_title'))->not->toBe('')
        ->and((string) data_get($source->generated_payload_json, 'brief.primary_keyword'))->not->toBe('')
        ->and((array) data_get($source->generated_payload_json, 'keywords.secondary_keywords'))->not->toBeEmpty()
        ->and((array) data_get($source->generated_payload_json, 'keywords.entities'))->not->toBeEmpty();
});

it('adds a chain proposal when requested', function () {
    [, , , $user] = makeSourceBriefingContext();

    Http::fake([
        'https://example.com/aeo' => Http::response(sampleArticleHtml(), 200, ['Content-Type' => 'text/html']),
    ]);

    $this->actingAs($user)->post(route('app.content.create.from-url.preview'), [
        'source_url' => 'https://example.com/aeo',
    ]);

    $source = ContentSource::query()->firstOrFail();

    $this->actingAs($user)->post(route('app.content.create.from-url.generate'), [
        'content_source_id' => (string) $source->id,
        'output_mode' => 'brief_chain',
    ])->assertRedirect();

    $source->refresh();

    expect((string) data_get($source->generated_payload_json, 'chain_proposal.pillar_topic'))->not->toBe('')
        ->and(count((array) data_get($source->generated_payload_json, 'chain_proposal.supporting_subtopics', [])))->toBeGreaterThanOrEqual(3);
});

it('saves a generated source brief and links it back to the source record', function () {
    [, , $site, $user] = makeSourceBriefingContext();

    Http::fake([
        'https://example.com/aeo' => Http::response(sampleArticleHtml(), 200, ['Content-Type' => 'text/html']),
    ]);

    $this->actingAs($user)->post(route('app.content.create.from-url.preview'), [
        'source_url' => 'https://example.com/aeo',
    ]);

    $source = ContentSource::query()->firstOrFail();

    $this->actingAs($user)->post(route('app.content.create.from-url.generate'), [
        'content_source_id' => (string) $source->id,
        'output_mode' => 'brief_keywords',
    ])->assertRedirect();

    $response = $this->actingAs($user)->post(route('app.content.create.from-url.save'), [
        'content_source_id' => (string) $source->id,
        'destination_mode' => 'connected',
        'site_id' => (string) $site->id,
        'next_action' => 'save',
    ]);

    $response->assertRedirect();

    $brief = Brief::query()->latest('created_at')->firstOrFail();

    expect((string) $brief->content_source_id)->toBe((string) $source->id)
        ->and((string) $brief->source)->toBe('url_source')
        ->and((string) data_get($brief->client_refs, 'source_briefing.content_source_id'))->toBe((string) $source->id)
        ->and((string) data_get($brief->client_refs, 'source_briefing.source_domain'))->toBe('example.com')
        ->and((string) $brief->content_id)->not->toBe('');
});

it('saves source brief with valid content type and no SQL truncation', function () {
    [, , $site, $user] = makeSourceBriefingContext();

    Http::fake([
        'https://example.com/aeo' => Http::response(sampleArticleHtml(), 200, ['Content-Type' => 'text/html']),
    ]);

    $this->actingAs($user)->post(route('app.content.create.from-url.preview'), [
        'source_url' => 'https://example.com/aeo',
    ]);

    $source = ContentSource::query()->firstOrFail();

    $this->actingAs($user)->post(route('app.content.create.from-url.generate'), [
        'content_source_id' => (string) $source->id,
        'output_mode' => 'brief_only',
    ])->assertRedirect();

    $response = $this->actingAs($user)->post(route('app.content.create.from-url.save'), [
        'content_source_id' => (string) $source->id,
        'destination_mode' => 'connected',
        'site_id' => (string) $site->id,
        'next_action' => 'save',
    ]);

    // Should not fail with SQL truncation error
    $response->assertRedirect();

    $brief = Brief::query()->latest('created_at')->firstOrFail();
    $content = \App\Models\Content::query()->find($brief->content_id);

    // Content.type should be valid DB enum value 'article'
    expect((string) $content->type)->toBe('article');

    // Brief.content_type uses display value 'blog'
    expect((string) $brief->content_type)->toBe('blog');

    // Content.source should be valid DB enum value 'manual'
    expect($content->source->value)->toBe('manual');
});

it('does not allow another organization to use a source record it does not own', function () {
    [, , , $owner] = makeSourceBriefingContext();
    [, , $foreignSite, $foreignUser] = makeSourceBriefingContext();

    Http::fake([
        'https://example.com/aeo' => Http::response(sampleArticleHtml(), 200, ['Content-Type' => 'text/html']),
    ]);

    $this->actingAs($owner)->post(route('app.content.create.from-url.preview'), [
        'source_url' => 'https://example.com/aeo',
    ]);

    $source = ContentSource::query()->firstOrFail();

    $this->actingAs($foreignUser)
        ->post(route('app.content.create.from-url.save'), [
            'content_source_id' => (string) $source->id,
            'destination_mode' => 'connected',
            'site_id' => (string) $foreignSite->id,
        ])
        ->assertNotFound();
});

it('returns a meaningful error for blocked local URLs', function () {
    [, , , $user] = makeSourceBriefingContext();

    $response = $this->from(route('app.content.create'))
        ->actingAs($user)
        ->post(route('app.content.create.from-url.preview'), [
            'source_url' => 'http://127.0.0.1/private',
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors(['source_url']);

    $source = ContentSource::query()->firstOrFail();
    expect((string) $source->extraction_status)->toBe('failed')
        ->and((string) data_get($source->metadata_json, 'failure_code'))->toBe('SOURCE_FETCH_BLOCKED')
        ->and((string) data_get($source->metadata_json, 'error'))->toContain('cannot be analyzed');
});

it('returns a meaningful error for empty extraction results', function () {
    [, , , $user] = makeSourceBriefingContext();

    Http::fake([
        'https://example.com/empty' => Http::response('<html><head><title>Empty shell</title></head><body><script>window.app = true;</script><nav>Menu</nav></body></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $response = $this->from(route('app.content.create'))
        ->actingAs($user)
        ->post(route('app.content.create.from-url.preview'), [
            'source_url' => 'https://example.com/empty',
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors(['source_url']);

    $source = ContentSource::query()->firstOrFail();
    expect((string) data_get($source->metadata_json, 'failure_code'))->toBe('SOURCE_EXTRACTION_TOO_SHORT');
});

it('returns a meaningful error for oversized source pages', function () {
    [, , , $user] = makeSourceBriefingContext();

    Http::fake([
        'https://example.com/huge' => Http::response(str_repeat('x', 4_000_001), 200, ['Content-Type' => 'text/html']),
    ]);

    $response = $this->from(route('app.content.create'))
        ->actingAs($user)
        ->post(route('app.content.create.from-url.preview'), [
            'source_url' => 'https://example.com/huge',
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors(['source_url']);

    $source = ContentSource::query()->firstOrFail();
    expect((string) data_get($source->metadata_json, 'failure_code'))->toBe('SOURCE_PAGE_TOO_LARGE')
        ->and((string) data_get($source->metadata_json, 'error'))->toContain('too large');
});

it('returns a meaningful error when the source fetch times out', function () {
    [, , , $user] = makeSourceBriefingContext();
    config(['source_extraction.jina_enabled' => false]);
    Queue::fake();

    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds');
    });

    $response = $this->from(route('app.content.create'))
        ->actingAs($user)
        ->post(route('app.content.create.from-url.preview'), [
            'source_url' => 'https://example.com/timeout',
        ]);

    $response->assertRedirect();

    $source = ContentSource::query()->firstOrFail();
    expect((string) $source->extraction_status)->toBe('pending')
        ->and((string) data_get($source->metadata_json, 'pending_message'))->toContain('fallback methods');
});

it('extracts useful content from malformed but readable html', function () {
    [, , , $user] = makeSourceBriefingContext();

    $body = str_repeat('Agentic marketing workflows need governance, measurement, and editorial oversight. ', 20);
    Http::fake([
        'https://example.com/malformed' => Http::response('<html><head><title>Malformed article</title><body><main><article><h1>Malformed article</h1><p>' . $body . '</p><h2>Workflow design</h2><p>' . $body . '</p><h2>Measurement</h2><p>' . $body, 200, ['Content-Type' => 'text/html']),
    ]);

    $response = $this->actingAs($user)
        ->post(route('app.content.create.from-url.preview'), [
            'source_url' => 'https://example.com/malformed',
        ]);

    $response->assertRedirect();

    $source = ContentSource::query()->firstOrFail();
    expect((string) $source->extraction_status)->toBe('extracted')
        ->and((int) data_get($source->metadata_json, 'extraction.extracted_characters', 0))->toBeGreaterThan(600);
});
