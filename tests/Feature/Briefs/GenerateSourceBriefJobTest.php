<?php

use App\Jobs\GenerateSourceBriefJob;
use App\Models\ClientSite;
use App\Models\ContentSource;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SourceBriefing\ArticleContentExtractor;
use App\Services\SourceBriefing\ChainProposalGenerator;
use App\Services\SourceBriefing\SourceBasedBriefGenerator;
use App\Services\SourceBriefing\SourceContentAnalyzer;
use App\Services\SourceBriefing\UrlSourceFetcher;
use App\Services\SourceBriefing\WorkspaceSourceContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeJobTestContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Job Test Org',
        'slug' => 'job-test-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Job Test Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Job Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Job Test Site',
        'site_url' => 'https://job-test.example.com',
        'allowed_domains' => ['job-test.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'job-test-plan'],
        [
            'name' => 'Job Test Plan',
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
        'name' => 'Job Test User',
        'email' => 'job-test+' . Str::random(5) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$organization, $workspace, $site, $user];
}

function createJobTestSource(Workspace $workspace, User $user): ContentSource
{
    return ContentSource::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'type' => 'url',
        'source_url' => 'https://example.com/job-test-article',
        'final_url' => 'https://example.com/job-test-article',
        'source_domain' => 'example.com',
        'source_title' => 'Job Test Article Title',
        'source_language' => 'en',
        'extraction_status' => 'extracted',
        'generation_status' => ContentSource::GENERATION_STATUS_QUEUED,
        'generation_output_mode' => 'brief_only',
        'fetched_at' => now(),
        'extracted_text' => 'This is a test article with enough content to be valid. It discusses important topics about content marketing and provides valuable insights for readers. The content covers multiple paragraphs and sections to simulate a real article structure. Additional content here to meet minimum requirements for the analyzer.',
        'extracted_outline_json' => [
            'h1' => 'Job Test Article Title',
            'h2' => ['Introduction to Content Marketing', 'Best Practices', 'Conclusion and Next Steps'],
            'h3' => ['Why It Matters', 'Getting Started'],
        ],
        'metadata_json' => [
            'extraction' => [
                'word_count' => 200,
                'summary' => 'A test article about content marketing for job testing.',
            ],
        ],
        'created_by_user_id' => (int) $user->id,
    ]);
}

describe('GenerateSourceBriefJob', function () {
    it('marks source as running when job starts', function () {
        [, $workspace, , $user] = makeJobTestContext();
        $source = createJobTestSource($workspace, $user);

        // Mock the services to avoid actual AI calls
        $mockContextBuilder = Mockery::mock(WorkspaceSourceContextBuilder::class);
        $mockContextBuilder->shouldReceive('build')->andReturn([
            'company_profile' => 'Test Company',
            'brand_voice' => 'Professional',
        ]);
        $mockFetcher = Mockery::mock(UrlSourceFetcher::class);
        $mockFetcher->shouldNotReceive('fetch');
        $mockExtractor = Mockery::mock(ArticleContentExtractor::class);
        $mockExtractor->shouldNotReceive('extract');

        $mockAnalyzer = Mockery::mock(SourceContentAnalyzer::class);
        $mockAnalyzer->shouldReceive('analyze')->andReturn([
            'main_topic' => 'Content Marketing',
            'primary_keyword' => 'content marketing',
            'secondary_keywords' => ['digital marketing', 'seo'],
        ]);

        $mockBriefGenerator = Mockery::mock(SourceBasedBriefGenerator::class);
        $mockBriefGenerator->shouldReceive('generate')->andReturn([
            'brief' => [
                'working_title' => 'Generated Test Title',
                'primary_keyword' => 'content marketing',
                'summary' => 'Test summary',
            ],
            'keywords' => [],
        ]);

        $mockChainGenerator = Mockery::mock(ChainProposalGenerator::class);

        $job = new GenerateSourceBriefJob($source->id, 'brief_only');
        $job->handle(
            $mockFetcher,
            $mockExtractor,
            $mockContextBuilder,
            $mockAnalyzer,
            $mockBriefGenerator,
            $mockChainGenerator
        );

        $source->refresh();

        expect((string) $source->generation_status)->toBe(ContentSource::GENERATION_STATUS_COMPLETED);
        expect((string) $source->extraction_status)->toBe('generated');
        expect($source->generated_payload_json)->not->toBeNull();
        expect(data_get($source->generated_payload_json, 'brief.working_title'))->toBe('Generated Test Title');
        expect($source->generation_completed_at)->not->toBeNull();
    });

    it('skips already completed sources', function () {
        [, $workspace, , $user] = makeJobTestContext();
        $source = createJobTestSource($workspace, $user);

        // Mark as already completed
        $source->markGenerationCompleted(
            ['analysis' => 'existing'],
            ['brief' => ['working_title' => 'Existing Title']]
        );

        $mockContextBuilder = Mockery::mock(WorkspaceSourceContextBuilder::class);
        $mockFetcher = Mockery::mock(UrlSourceFetcher::class);
        $mockFetcher->shouldNotReceive('fetch');
        $mockExtractor = Mockery::mock(ArticleContentExtractor::class);
        $mockExtractor->shouldNotReceive('extract');
        $mockAnalyzer = Mockery::mock(SourceContentAnalyzer::class);
        $mockBriefGenerator = Mockery::mock(SourceBasedBriefGenerator::class);
        $mockChainGenerator = Mockery::mock(ChainProposalGenerator::class);

        // None of these should be called
        $mockContextBuilder->shouldNotReceive('build');
        $mockAnalyzer->shouldNotReceive('analyze');
        $mockBriefGenerator->shouldNotReceive('generate');

        $job = new GenerateSourceBriefJob($source->id, 'brief_only');
        $job->handle(
            $mockFetcher,
            $mockExtractor,
            $mockContextBuilder,
            $mockAnalyzer,
            $mockBriefGenerator,
            $mockChainGenerator
        );

        $source->refresh();

        // Should still have original data
        expect(data_get($source->generated_payload_json, 'brief.working_title'))->toBe('Existing Title');
    });

    it('marks source as failed when job fails permanently', function () {
        [, $workspace, , $user] = makeJobTestContext();
        $source = createJobTestSource($workspace, $user);

        $exception = new RuntimeException('Test error: service unavailable');

        $job = new GenerateSourceBriefJob($source->id, 'brief_only');

        // Simulate the failed() callback which is called when job permanently fails
        $job->failed($exception);

        $source->refresh();

        expect((string) $source->generation_status)->toBe(ContentSource::GENERATION_STATUS_FAILED);
        expect($source->generation_failure_message)->not->toBeNull();
        expect($source->generation_diagnostics_json)->not->toBeNull();
    });

    it('rethrows exception for retry when job fails during execution', function () {
        [, $workspace, , $user] = makeJobTestContext();
        $source = createJobTestSource($workspace, $user);

        $mockContextBuilder = Mockery::mock(WorkspaceSourceContextBuilder::class);
        $mockFetcher = Mockery::mock(UrlSourceFetcher::class);
        $mockFetcher->shouldNotReceive('fetch');
        $mockExtractor = Mockery::mock(ArticleContentExtractor::class);
        $mockExtractor->shouldNotReceive('extract');
        $mockContextBuilder->shouldReceive('build')->andThrow(new RuntimeException('Test error: service unavailable'));

        $mockAnalyzer = Mockery::mock(SourceContentAnalyzer::class);
        $mockBriefGenerator = Mockery::mock(SourceBasedBriefGenerator::class);
        $mockChainGenerator = Mockery::mock(ChainProposalGenerator::class);

        $job = new GenerateSourceBriefJob($source->id, 'brief_only');

        expect(fn () => $job->handle(
            $mockFetcher,
            $mockExtractor,
            $mockContextBuilder,
            $mockAnalyzer,
            $mockBriefGenerator,
            $mockChainGenerator
        ))->toThrow(RuntimeException::class);
    });

    it('marks source fetch timeouts as terminal customer-actionable failures', function () {
        [, $workspace, , $user] = makeJobTestContext();
        config(['source_extraction.jina_enabled' => false]);
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds with 0 bytes received');
        });

        $source = createJobTestSource($workspace, $user);
        $source->update([
            'extraction_status' => 'pending',
            'extracted_text' => null,
        ]);

        $mockFetcher = Mockery::mock(UrlSourceFetcher::class);
        $mockFetcher->shouldNotReceive('fetch');

        $mockExtractor = Mockery::mock(ArticleContentExtractor::class);
        $mockExtractor->shouldNotReceive('extract');

        $mockContextBuilder = Mockery::mock(WorkspaceSourceContextBuilder::class);
        $mockContextBuilder->shouldNotReceive('build');

        $mockAnalyzer = Mockery::mock(SourceContentAnalyzer::class);
        $mockAnalyzer->shouldNotReceive('analyze');

        $mockBriefGenerator = Mockery::mock(SourceBasedBriefGenerator::class);
        $mockBriefGenerator->shouldNotReceive('generate');

        $mockChainGenerator = Mockery::mock(ChainProposalGenerator::class);
        $mockChainGenerator->shouldNotReceive('generate');

        $job = new GenerateSourceBriefJob($source->id, 'brief_only');
        $job->handle(
            $mockFetcher,
            $mockExtractor,
            $mockContextBuilder,
            $mockAnalyzer,
            $mockBriefGenerator,
            $mockChainGenerator
        );

        $source->refresh();

        expect((string) $source->generation_status)->toBe(ContentSource::GENERATION_STATUS_FAILED);
        expect((string) $source->generation_failure_code)->toBe('SOURCE_FETCH_TIMEOUT');
        expect((string) $source->generation_failure_message)->toContain('could not fetch this URL');
        expect((bool) data_get($source->generation_diagnostics_json, 'terminal'))->toBeTrue();
    });

    it('generates chain proposal when output mode is brief_chain', function () {
        [, $workspace, , $user] = makeJobTestContext();
        $source = createJobTestSource($workspace, $user);
        $source->update(['generation_output_mode' => 'brief_chain']);

        $mockContextBuilder = Mockery::mock(WorkspaceSourceContextBuilder::class);
        $mockFetcher = Mockery::mock(UrlSourceFetcher::class);
        $mockFetcher->shouldNotReceive('fetch');
        $mockExtractor = Mockery::mock(ArticleContentExtractor::class);
        $mockExtractor->shouldNotReceive('extract');
        $mockContextBuilder->shouldReceive('build')->andReturn([
            'company_profile' => 'Test Company',
        ]);

        $mockAnalyzer = Mockery::mock(SourceContentAnalyzer::class);
        $mockAnalyzer->shouldReceive('analyze')->andReturn([
            'main_topic' => 'Content Marketing',
        ]);

        $mockChainGenerator = Mockery::mock(ChainProposalGenerator::class);
        $mockChainGenerator->shouldReceive('generate')->once()->andReturn([
            'pillar_topic' => 'Content Marketing Strategy',
            'supporting_subtopics' => [
                ['title' => 'SEO Basics'],
                ['title' => 'Social Media Tips'],
                ['title' => 'Email Marketing'],
            ],
        ]);

        $mockBriefGenerator = Mockery::mock(SourceBasedBriefGenerator::class);
        $mockBriefGenerator->shouldReceive('generate')->andReturn([
            'brief' => ['working_title' => 'Test'],
            'chain_proposal' => [
                'pillar_topic' => 'Content Marketing Strategy',
                'supporting_subtopics' => [
                    ['title' => 'SEO Basics'],
                    ['title' => 'Social Media Tips'],
                    ['title' => 'Email Marketing'],
                ],
            ],
        ]);

        $job = new GenerateSourceBriefJob($source->id, 'brief_chain');
        $job->handle(
            $mockFetcher,
            $mockExtractor,
            $mockContextBuilder,
            $mockAnalyzer,
            $mockBriefGenerator,
            $mockChainGenerator
        );

        $source->refresh();

        expect((string) $source->generation_status)->toBe(ContentSource::GENERATION_STATUS_COMPLETED);
    });

    it('handles source not found gracefully', function () {
        $job = new GenerateSourceBriefJob('non-existent-uuid', 'brief_only');

        $mockFetcher = Mockery::mock(UrlSourceFetcher::class);
        $mockExtractor = Mockery::mock(ArticleContentExtractor::class);
        $mockContextBuilder = Mockery::mock(WorkspaceSourceContextBuilder::class);
        $mockAnalyzer = Mockery::mock(SourceContentAnalyzer::class);
        $mockBriefGenerator = Mockery::mock(SourceBasedBriefGenerator::class);
        $mockChainGenerator = Mockery::mock(ChainProposalGenerator::class);

        expect(fn () => $job->handle(
            $mockFetcher,
            $mockExtractor,
            $mockContextBuilder,
            $mockAnalyzer,
            $mockBriefGenerator,
            $mockChainGenerator
        ))->toThrow(RuntimeException::class, 'ContentSource not found');
    });
});
