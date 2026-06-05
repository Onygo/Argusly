<?php

use App\Jobs\Onboarding\ScanWebsiteJob;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebsiteScan;
use App\Services\OnboardingScan\AIAnalysisService;
use App\Services\OnboardingScan\ContentExtractionService;
use App\Services\OnboardingScan\WebsiteCrawlerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

describe('ScanWebsiteJob', function () {

    it('updates scan status through stages on success', function () {
        $user = createScanJobTestUser();

        $scan = WebsiteScan::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'url' => 'https://example.com',
            'status' => WebsiteScan::STATUS_QUEUED,
            'progress' => 0,
        ]);

        // Mock the services
        $crawler = Mockery::mock(WebsiteCrawlerService::class);
        $crawler->shouldReceive('crawl')
            ->once()
            ->andReturn([
                'homepage' => [
                    'url' => 'https://example.com',
                    'success' => true,
                    'html' => '<html><head><title>Test</title></head><body><h1>Hello</h1></body></html>',
                ],
                'internal_pages' => [],
                'diagnostics' => [],
            ]);

        $extractor = Mockery::mock(ContentExtractionService::class);
        $extractor->shouldReceive('extract')
            ->once()
            ->andReturn([
                'homepage' => [
                    'url' => 'https://example.com',
                    'title' => 'Test',
                    'headings' => [['level' => 1, 'text' => 'Hello']],
                    'main_content' => 'Hello world',
                    'word_count' => 2,
                ],
            ]);

        $analyzer = Mockery::mock(AIAnalysisService::class);
        $analyzer->shouldReceive('analyze')
            ->once()
            ->andReturn([
                'brand_profile' => ['company_name' => 'Test Company'],
                'seo_profile' => ['primary_keywords' => ['test']],
                'design_profile' => ['primary_colors' => ['#000000']],
                'technical_profile' => ['detected_cms' => []],
                'suggested_briefs' => [
                    ['title' => 'Test Brief', 'primary_keyword' => 'test'],
                ],
            ]);

        app()->instance(WebsiteCrawlerService::class, $crawler);
        app()->instance(ContentExtractionService::class, $extractor);
        app()->instance(AIAnalysisService::class, $analyzer);

        $job = new ScanWebsiteJob($scan->id);
        $job->handle($crawler, $extractor, $analyzer);

        $scan->refresh();

        expect($scan->status)->toBe(WebsiteScan::STATUS_COMPLETED);
        expect($scan->progress)->toBe(1.0);
        expect($scan->brand_profile)->toBe(['company_name' => 'Test Company']);
        expect($scan->suggested_briefs)->toHaveCount(1);
        expect($scan->completed_at)->not->toBeNull();
    });

    it('marks scan as failed when crawling fails', function () {
        $user = createScanJobTestUser();

        $scan = WebsiteScan::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'url' => 'https://invalid-site.test',
            'status' => WebsiteScan::STATUS_QUEUED,
            'progress' => 0,
        ]);

        $crawler = Mockery::mock(WebsiteCrawlerService::class);
        $crawler->shouldReceive('crawl')
            ->once()
            ->andReturn([
                'homepage' => [
                    'url' => 'https://invalid-site.test',
                    'success' => false,
                    'html' => null,
                    'error' => 'Connection timeout',
                ],
                'internal_pages' => [],
                'diagnostics' => [],
            ]);

        $extractor = Mockery::mock(ContentExtractionService::class);
        $analyzer = Mockery::mock(AIAnalysisService::class);

        app()->instance(WebsiteCrawlerService::class, $crawler);
        app()->instance(ContentExtractionService::class, $extractor);
        app()->instance(AIAnalysisService::class, $analyzer);

        $job = new ScanWebsiteJob($scan->id);

        try {
            $job->handle($crawler, $extractor, $analyzer);
        } catch (\RuntimeException $e) {
            // Expected
        }

        $scan->refresh();

        expect($scan->status)->toBe(WebsiteScan::STATUS_FAILED);
        expect($scan->error_message)->toContain('Failed to fetch homepage');
    });

    it('skips already completed scans', function () {
        $user = createScanJobTestUser();

        $scan = WebsiteScan::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'url' => 'https://example.com',
            'status' => WebsiteScan::STATUS_COMPLETED,
            'progress' => 1.0,
            'completed_at' => now(),
        ]);

        $crawler = Mockery::mock(WebsiteCrawlerService::class);
        $crawler->shouldNotReceive('crawl');

        $extractor = Mockery::mock(ContentExtractionService::class);
        $analyzer = Mockery::mock(AIAnalysisService::class);

        $job = new ScanWebsiteJob($scan->id);
        $job->handle($crawler, $extractor, $analyzer);

        // Should not throw and scan should remain completed
        $scan->refresh();
        expect($scan->status)->toBe(WebsiteScan::STATUS_COMPLETED);
    });

    it('handles missing scan gracefully', function () {
        $crawler = Mockery::mock(WebsiteCrawlerService::class);
        $crawler->shouldNotReceive('crawl');

        $extractor = Mockery::mock(ContentExtractionService::class);
        $analyzer = Mockery::mock(AIAnalysisService::class);

        $job = new ScanWebsiteJob('non-existent-uuid');
        $job->handle($crawler, $extractor, $analyzer);

        // Should not throw
        expect(true)->toBeTrue();
    });

    it('has correct retry configuration', function () {
        $job = new ScanWebsiteJob('test-id');

        expect($job->tries)->toBe(5);
        expect($job->timeout)->toBe(300);
        expect($job->backoff())->toBe([60, 300, 900, 3600, 10800]);
    });
});

function createScanJobTestUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Test Org ' . Str::random(4),
        'slug' => 'test-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Test User',
        'email' => 'test+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'active' => true,
        'approved_at' => now(),
    ]);
}
