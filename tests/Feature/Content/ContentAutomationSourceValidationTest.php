<?php

use App\Enums\ContentOriginType;
use App\Enums\ContentSource;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\Workspace;
use App\Support\KeywordSanitizer;
use App\Support\TitleSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('ContentSource enum', function () {
    it('has the correct valid values', function () {
        expect(ContentSource::values())->toBe(['wp', 'manual', 'api', 'automation', 'import', 'system']);
    });

    it('validates known values correctly', function () {
        expect(ContentSource::isValid('wp'))->toBeTrue()
            ->and(ContentSource::isValid('manual'))->toBeTrue()
            ->and(ContentSource::isValid('api'))->toBeTrue()
            ->and(ContentSource::isValid('automation'))->toBeTrue()
            ->and(ContentSource::isValid('content_automation'))->toBeFalse()
            ->and(ContentSource::isValid('test'))->toBeFalse()
            ->and(ContentSource::isValid('invalid'))->toBeFalse();
    });

    it('normalizes content_automation to automation', function () {
        expect(ContentSource::normalize('content_automation'))->toBe(ContentSource::AUTOMATION)
            ->and(ContentSource::normalize('automation_run'))->toBe(ContentSource::AUTOMATION)
            ->and(ContentSource::normalize('chained_content'))->toBe(ContentSource::AUTOMATION)
            ->and(ContentSource::normalize('content_chain'))->toBe(ContentSource::AUTOMATION)
            ->and(ContentSource::normalize('generated'))->toBe(ContentSource::AUTOMATION)
            ->and(ContentSource::normalize('translated'))->toBe(ContentSource::AUTOMATION)
            ->and(ContentSource::normalize('scheduled'))->toBe(ContentSource::AUTOMATION)
            ->and(ContentSource::normalize('series'))->toBe(ContentSource::AUTOMATION)
            ->and(ContentSource::normalize('system'))->toBe(ContentSource::SYSTEM)
            ->and(ContentSource::normalize('import'))->toBe(ContentSource::IMPORT);
    });

    it('normalizes unknown values to api', function () {
        expect(ContentSource::normalize('unknown'))->toBe(ContentSource::API)
            ->and(ContentSource::normalize('test'))->toBe(ContentSource::API)
            ->and(ContentSource::normalize('some_random_value'))->toBe(ContentSource::API);
    });
});

describe('Content model source validation', function () {
    it('accepts valid source enum values', function () {
        [$workspace, $site] = makeSourceValidationContext();

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => 'Valid source test',
            'language' => 'en',
            'type' => 'article',
            'status' => 'draft',
            'source' => 'automation',
        ]);

        expect($content->source)->toBe(ContentSource::AUTOMATION)
            ->and($content->fresh()->source)->toBe(ContentSource::AUTOMATION);
    });

    it('accepts ContentSource enum directly', function () {
        [$workspace, $site] = makeSourceValidationContext();

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => 'Enum source test',
            'language' => 'en',
            'type' => 'article',
            'status' => 'draft',
            'source' => ContentSource::AUTOMATION,
        ]);

        expect($content->source)->toBe(ContentSource::AUTOMATION);
    });

    it('normalizes content_automation to automation with warning log', function () {
        Log::spy();
        [$workspace, $site] = makeSourceValidationContext();

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => 'Content automation normalization test',
            'language' => 'en',
            'type' => 'article',
            'status' => 'draft',
            'source' => 'content_automation',
        ]);

        expect($content->source)->toBe(ContentSource::AUTOMATION)
            ->and($content->fresh()->source)->toBe(ContentSource::AUTOMATION);

        Log::shouldHaveReceived('warning')
            ->with('content.source_normalized', \Mockery::on(fn (array $ctx): bool => ($ctx['original_value'] ?? '') === 'content_automation' && ($ctx['normalized_value'] ?? '') === 'automation'
            ))
            ->once();
    });

    it('normalizes unknown source values to api', function () {
        Log::spy();
        [$workspace, $site] = makeSourceValidationContext();

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => 'Unknown source normalization test',
            'language' => 'en',
            'type' => 'article',
            'status' => 'draft',
            'source' => 'unknown_source_value',
        ]);

        expect($content->source)->toBe(ContentSource::API);
    });

    it('persists automation source variants as automation', function (string $incomingSource) {
        [$workspace, $site] = makeSourceValidationContext();

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => 'Automation source variant',
            'language' => 'en',
            'type' => 'article',
            'status' => 'draft',
            'source' => $incomingSource,
        ]);

        expect($content->getRawOriginal('source'))->toBe(ContentSource::AUTOMATION->value)
            ->and($content->fresh()->source)->toBe(ContentSource::AUTOMATION);
    })->with([
        'automation_run',
        'chained_content',
        'scheduled',
        'series',
        'generated',
        'translated',
    ]);

    it('defaults null source to api', function () {
        [$workspace, $site] = makeSourceValidationContext();

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => 'Null source test',
            'language' => 'en',
            'type' => 'article',
            'status' => 'draft',
            'source' => null,
        ]);

        expect($content->source)->toBe(ContentSource::API);
    });
});

describe('Content automation origin type tracking', function () {
    it('distinguishes automation from chained_via_automation origin types', function () {
        [$workspace, $site] = makeSourceValidationContext();

        $automationContent = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => 'Direct automation content',
            'language' => 'en',
            'type' => 'article',
            'status' => 'draft',
            'source' => ContentSource::AUTOMATION,
            'origin_type' => ContentOriginType::AUTOMATION,
        ]);

        $chainedContent = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => 'Chained automation content',
            'language' => 'en',
            'type' => 'article',
            'status' => 'draft',
            'source' => ContentSource::AUTOMATION,
            'origin_type' => ContentOriginType::CHAINED_VIA_AUTOMATION,
        ]);

        expect($automationContent->source)->toBe(ContentSource::AUTOMATION)
            ->and($automationContent->origin_type)->toBe(ContentOriginType::AUTOMATION)
            ->and($chainedContent->source)->toBe(ContentSource::AUTOMATION)
            ->and($chainedContent->origin_type)->toBe(ContentOriginType::CHAINED_VIA_AUTOMATION);
    });

    it('correctly identifies automation-originated content', function () {
        [$workspace, $site] = makeSourceValidationContext();

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => 'Automation origin test',
            'language' => 'en',
            'type' => 'article',
            'status' => 'draft',
            'source' => ContentSource::AUTOMATION,
            'origin_type' => ContentOriginType::CHAINED_VIA_AUTOMATION,
        ]);

        expect($content->origin_type->isFromAutomation())->toBeTrue()
            ->and($content->origin_type->isChained())->toBeTrue();
    });
});

describe('Content title sanitization for automation', function () {
    it('truncates oversized LLM-generated titles to database limit', function () {
        [$workspace, $site] = makeSourceValidationContext();
        $longTitle = str_repeat('AI cybersecurity architecture ', 20);

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => $longTitle,
            'language' => 'en',
            'type' => 'article',
            'status' => 'draft',
            'source' => ContentSource::AUTOMATION,
        ]);

        expect(mb_strlen((string) $content->title))->toBeLessThanOrEqual(TitleSanitizer::MAX_LENGTH)
            ->and((string) $content->title)->toContain('AI cybersecurity');
    });

    it('strips HTML and normalizes whitespace from titles', function () {
        [$workspace, $site] = makeSourceValidationContext();

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => '<h1>Article    Title</h1>  with   extra   spaces',
            'language' => 'en',
            'type' => 'article',
            'status' => 'draft',
            'source' => ContentSource::AUTOMATION,
        ]);

        expect((string) $content->title)->toBe('Article Title with extra spaces');
    });
});

describe('Content primary_keyword sanitization for automation', function () {
    it('rejects paragraph-like text in primary_keyword and derives a fallback', function () {
        Log::spy();
        [$workspace, $site] = makeSourceValidationContext();

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => 'Keyword test',
            'language' => 'en',
            'type' => 'article',
            'status' => 'draft',
            'source' => ContentSource::AUTOMATION,
            'primary_keyword' => 'This is a full paragraph that explains the entire topic in detail. It contains multiple sentences and is far too long to be a keyword.',
        ]);

        expect(mb_strlen((string) $content->primary_keyword))->toBeLessThanOrEqual(KeywordSanitizer::MAX_LENGTH)
            ->and(str_word_count((string) $content->primary_keyword))->toBeLessThanOrEqual(KeywordSanitizer::MAX_WORD_COUNT);

        Log::shouldHaveReceived('notice')
            ->with('content.keyword_sanitized', \Mockery::on(fn (array $ctx): bool => ($ctx['was_rejected'] ?? false) === true
            ))
            ->once();
    });

    it('rejects JSON fragments in primary_keyword', function () {
        [$workspace, $site] = makeSourceValidationContext();

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => 'JSON keyword test',
            'language' => 'en',
            'type' => 'article',
            'status' => 'draft',
            'source' => ContentSource::AUTOMATION,
            'primary_keyword' => '{"keyword": "ai cybersecurity", "volume": 1000}',
        ]);

        expect((string) $content->primary_keyword)->not->toContain('{')
            ->and((string) $content->primary_keyword)->not->toContain('}');
    });

    it('accepts valid short keyphrases', function () {
        [$workspace, $site] = makeSourceValidationContext();

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => 'Valid keyword test',
            'language' => 'en',
            'type' => 'article',
            'status' => 'draft',
            'source' => ContentSource::AUTOMATION,
            'primary_keyword' => 'ai cybersecurity best practices',
        ]);

        expect((string) $content->primary_keyword)->toBe('ai cybersecurity best practices');
    });
});

describe('Database constraint safety', function () {
    it('never causes MySQL truncation warning for source field', function () {
        [$workspace, $site] = makeSourceValidationContext();

        // This should be normalized, not cause truncation
        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => 'Truncation safety test',
            'language' => 'en',
            'type' => 'article',
            'status' => 'draft',
            'source' => 'this_is_a_very_long_invalid_source_value_that_should_not_cause_truncation',
        ]);

        // Value should be normalized, not truncated
        expect(ContentSource::isValid($content->getRawOriginal('source')))->toBeTrue();
    });

    it('persists automation content without database warnings', function () {
        [$workspace, $site] = makeSourceValidationContext();

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => 'No warning test',
            'language' => 'en',
            'type' => 'article',
            'status' => 'draft',
            'source' => ContentSource::AUTOMATION,
            'origin_type' => ContentOriginType::CHAINED_VIA_AUTOMATION,
            'primary_keyword' => 'valid keyword',
        ]);

        $fresh = $content->fresh();

        expect($fresh->source)->toBe(ContentSource::AUTOMATION)
            ->and($fresh->origin_type)->toBe(ContentOriginType::CHAINED_VIA_AUTOMATION)
            ->and((string) $fresh->primary_keyword)->toBe('valid keyword');
    });
});

function makeSourceValidationContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Source Validation Org',
        'slug' => 'source-validation-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Source Validation Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => (string) $workspace->id,
        'type' => 'wordpress',
        'name' => 'Source Validation Site',
        'site_url' => 'https://source-validation.example.com',
        'base_url' => 'https://source-validation.example.com',
        'allowed_domains' => ['source-validation.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$workspace, $site];
}
