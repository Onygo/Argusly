<?php

use App\Jobs\ExtractSourceUrlJob;
use App\Models\ClientSite;
use App\Models\ContentSource;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SourceExtraction\SourceUrlExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeExtractorFlowContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Extractor Flow Org',
        'slug' => 'extractor-flow-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Extractor Flow Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Extractor Flow Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Extractor Flow Site',
        'site_url' => 'https://extractor-flow.example.com',
        'allowed_domains' => ['extractor-flow.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'extractor-flow-plan'],
        [
            'name' => 'Extractor Flow Plan',
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
        'name' => 'Extractor Flow User',
        'email' => 'extractor-flow+' . Str::random(5) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$organization, $workspace, $site, $user];
}

function extractorArticleHtml(): string
{
    $paragraph = str_repeat('Source extraction should analyze themes, entities, questions, and structure without copying source content. ', 12);

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <title>Layered extraction for source briefing</title>
    <meta name="description" content="A source article about resilient URL extraction.">
    <link rel="canonical" href="https://example.com/layered-extraction">
</head>
<body>
    <header>Header navigation should disappear</header>
    <nav>Pricing Login Menu</nav>
    <main>
        <article>
            <h1>Layered extraction for source briefing</h1>
            <p>{$paragraph}</p>
            <h2>Fallback methods</h2>
            <p>{$paragraph}</p>
            <h2>Reliability metadata</h2>
            <p>{$paragraph}</p>
        </article>
    </main>
    <footer>Footer boilerplate should disappear</footer>
</body>
</html>
HTML;
}

it('extracts source context directly from simple html', function () {
    config(['source_extraction.jina_enabled' => false]);
    Http::fake([
        'https://example.com/article' => Http::response(extractorArticleHtml(), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = app(SourceUrlExtractor::class)->extract('https://example.com/article', null, ['use_cache' => false]);

    expect($result->success)->toBeTrue()
        ->and($result->method)->toBe('direct')
        ->and($result->title)->toContain('Layered extraction')
        ->and($result->chars)->toBeGreaterThan(800)
        ->and($result->estimatedTokens)->toBeGreaterThan(100);
});

it('removes script style navigation and footer boilerplate from extraction', function () {
    config(['source_extraction.jina_enabled' => false]);
    Http::fake([
        'https://example.com/noise' => Http::response(str_replace(
            '</article>',
            '<script>track()</script><style>.x{}</style></article>',
            extractorArticleHtml()
        ), 200, ['Content-Type' => 'text/html']),
    ]);

    $result = app(SourceUrlExtractor::class)->extract('https://example.com/noise', null, ['use_cache' => false]);

    expect($result->success)->toBeTrue()
        ->and((string) $result->extractedText)->not->toContain('Pricing Login Menu')
        ->and((string) $result->extractedText)->not->toContain('Footer boilerplate')
        ->and((string) $result->extractedText)->not->toContain('track()');
});

it('falls back to jina reader when direct extraction is too short', function () {
    config([
        'source_extraction.jina_enabled' => true,
        'source_extraction.jina_api_key' => 'jina-test-key',
    ]);
    $markdown = "# Reader fallback title\n\n" . str_repeat('Reader fallback extracts semantic context and topic coverage for original brief generation. ', 20);

    Http::fake([
        'https://example.com/thin' => Http::response('<html><body><h1>Thin</h1><p>Short.</p></body></html>', 200, ['Content-Type' => 'text/html']),
        'https://r.jina.ai/http://example.com/thin' => Http::response($markdown, 200, ['Content-Type' => 'text/plain']),
    ]);

    $result = app(SourceUrlExtractor::class)->extract('https://example.com/thin', null, ['use_cache' => false]);

    expect($result->success)->toBeTrue()
        ->and($result->method)->toBe('jina_reader')
        ->and($result->title)->toBe('Reader fallback title');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://r.jina.ai/http://example.com/thin'
        && $request->hasHeader('Authorization', 'Bearer jina-test-key'));
});

it('reports jina auth requirements clearly when the reader endpoint requires a key', function () {
    config([
        'source_extraction.jina_enabled' => true,
        'source_extraction.jina_api_key' => null,
    ]);

    Http::fake([
        'https://example.com/thin-auth' => Http::response('<html><body><h1>Thin</h1><p>Short.</p></body></html>', 200, ['Content-Type' => 'text/html']),
        'https://r.jina.ai/http://example.com/thin-auth' => Http::response([
            'data' => null,
            'code' => 401,
            'name' => 'AuthenticationRequiredError',
            'status' => 40103,
            'message' => 'Authentication is required to use this endpoint. Please provide a valid API key via Authorization header.',
        ], 401),
    ]);

    $result = app(SourceUrlExtractor::class)->extract('https://example.com/thin-auth', null, [
        'use_cache' => false,
        'browser_enabled' => false,
    ]);

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('SOURCE_JINA_AUTH_REQUIRED')
        ->and((bool) data_get($result->metadata, 'has_api_key'))->toBeFalse();
});

it('builds jina reader urls without double prefixing r jina ai', function () {
    $extractor = app(SourceUrlExtractor::class);

    expect($extractor->buildJinaReaderUrl('https://example.com/page'))->toBe('https://r.jina.ai/http://example.com/page')
        ->and($extractor->buildJinaReaderUrl('https://r.jina.ai/http://example.com/page'))->toBe('https://r.jina.ai/http://example.com/page')
        ->and($extractor->buildJinaReaderUrl('https://example.com/page'))->not->toContain('r.jina.ai/http://r.jina.ai');
});

it('dispatches queued fallback when preview extraction times out', function () {
    config(['source_extraction.jina_enabled' => false]);
    Queue::fake();
    [, , , $user] = makeExtractorFlowContext();

    Http::fake(function () {
        throw new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds');
    });

    $response = $this->actingAs($user)
        ->post(route('app.content.create.from-url.preview'), [
            'source_url' => 'https://example.com/slow',
        ]);

    $response->assertRedirect();
    Queue::assertPushed(ExtractSourceUrlJob::class);

    $source = ContentSource::query()->firstOrFail();
    expect((string) $source->extraction_status)->toBe('pending')
        ->and((string) data_get($source->metadata_json, 'pending_message'))->toContain('fallback methods');
});

it('generates a brief from manual source notes when extraction fails', function () {
    [, , , $user] = makeExtractorFlowContext();
    $notes = str_repeat('Manual source notes describe agentic marketing workflow risks, governance needs, entity coverage, and audience opportunities. ', 12);

    $response = $this->actingAs($user)
        ->post(route('app.content.create.from-url.generate'), [
            'source_url' => 'http://127.0.0.1/blocked',
            'manual_source_notes' => $notes,
            'output_mode' => 'brief_only',
        ]);

    $response->assertRedirect();

    $source = ContentSource::query()->firstOrFail();
    expect((string) $source->generation_status)->toBe(ContentSource::GENERATION_STATUS_COMPLETED)
        ->and((string) data_get($source->metadata_json, 'extraction.method'))->toBe('manual_source_notes')
        ->and((string) data_get($source->generated_payload_json, 'brief.source_inspiration_note'))->toContain('not as copy');
});

it('skips browser fallback safely when disabled', function () {
    config([
        'source_extraction.jina_enabled' => false,
        'source_extraction.browser_enabled' => false,
    ]);

    Http::fake([
        'https://example.com/blocked' => Http::response('<html><body><h1>Thin</h1><p>Short.</p></body></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $result = app(SourceUrlExtractor::class)->extract('https://example.com/blocked', null, [
        'use_cache' => false,
        'browser_enabled' => true,
    ]);

    expect($result->success)->toBeFalse()
        ->and($result->errorCode)->toBe('SOURCE_BROWSER_DISABLED');
});

it('returns a cached successful extraction without refetching', function () {
    config(['source_extraction.jina_enabled' => false]);
    Http::fake([
        'https://example.com/cache' => Http::response(extractorArticleHtml(), 200, ['Content-Type' => 'text/html']),
    ]);

    $first = app(SourceUrlExtractor::class)->extract('https://example.com/cache');
    expect($first->success)->toBeTrue();

    Http::fake(function () {
        throw new RuntimeException('Network should not be called on cache hit.');
    });

    $second = app(SourceUrlExtractor::class)->extract('https://example.com/cache');

    expect($second->success)->toBeTrue()
        ->and((bool) data_get($second->metadata, 'cache_hit'))->toBeTrue();
});

it('keeps compliance copy visible on the create content page', function () {
    [, , , $user] = makeExtractorFlowContext();

    $this->actingAs($user)
        ->get(route('app.content.create'))
        ->assertOk()
        ->assertSee('Not for copying source content')
        ->assertSee('Paste source notes manually');
});
