<?php

namespace App\Jobs\Onboarding;

use App\Models\WebsiteScan;
use App\Services\OnboardingScan\AIAnalysisService;
use App\Services\OnboardingScan\ContentExtractionService;
use App\Services\OnboardingScan\WebsiteCrawlerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanWebsiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 300;
    public bool $failOnTimeout = true;

    private const STAGE_STARTED = 'scan_started';
    private const STAGE_CRAWLING = 'crawling_pages';
    private const STAGE_EXTRACTING = 'extracting_content';
    private const STAGE_ANALYZING = 'ai_analyzing';
    private const STAGE_COMPLETED = 'scan_completed';
    private const STAGE_FAILED = 'scan_failed';

    public function __construct(
        public readonly string $scanId,
    ) {
    }

    /**
     * Exponential backoff between retries.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600, 10800];
    }

    public function handle(
        WebsiteCrawlerService $crawler,
        ContentExtractionService $extractor,
        AIAnalysisService $analyzer,
    ): void {
        $currentStage = self::STAGE_STARTED;

        try {
            $scan = WebsiteScan::find($this->scanId);

            if (! $scan) {
                $this->logStage(self::STAGE_FAILED, ['error' => 'Scan not found']);

                return;
            }

            if ($scan->status === WebsiteScan::STATUS_COMPLETED) {
                $this->logStage(self::STAGE_STARTED, ['note' => 'Scan already completed, skipping']);

                return;
            }

            if ($scan->status === WebsiteScan::STATUS_FAILED && $scan->user_confirmed) {
                $this->logStage(self::STAGE_STARTED, ['note' => 'Scan already failed and confirmed, skipping']);

                return;
            }

            $this->logStage(self::STAGE_STARTED, [
                'scan_id' => $this->scanId,
                'url' => $scan->url,
            ]);

            // Stage: Crawling
            $currentStage = self::STAGE_CRAWLING;
            $scan->update([
                'status' => WebsiteScan::STATUS_CRAWLING,
                'progress' => 0.1,
                'started_at' => $scan->started_at ?? now(),
            ]);

            $crawlResult = $crawler->crawl($scan->url, 5);

            $homepage = $crawlResult['homepage'];
            if (! ($homepage['success'] ?? false)) {
                throw new \RuntimeException(
                    'Failed to fetch homepage: ' . ($homepage['error'] ?? 'Unknown error')
                );
            }

            $scan->update([
                'crawled_pages' => $crawlResult,
                'progress' => 0.3,
            ]);

            $this->logStage(self::STAGE_CRAWLING, [
                'homepage_success' => $homepage['success'] ?? false,
                'internal_pages_count' => count($crawlResult['internal_pages'] ?? []),
            ]);

            // Stage: Extracting
            $currentStage = self::STAGE_EXTRACTING;
            $scan->update([
                'status' => WebsiteScan::STATUS_EXTRACTING,
                'progress' => 0.4,
            ]);

            // Prepare pages for extraction
            $pagesToExtract = ['homepage' => $homepage];
            foreach ($crawlResult['internal_pages'] ?? [] as $url => $page) {
                $pagesToExtract[$url] = $page;
            }

            $extractedContent = $extractor->extract($pagesToExtract);

            $scan->update([
                'extracted_content' => $extractedContent,
                'progress' => 0.6,
            ]);

            $this->logStage(self::STAGE_EXTRACTING, [
                'pages_extracted' => count($extractedContent),
            ]);

            // Stage: AI Analysis
            $currentStage = self::STAGE_ANALYZING;
            $scan->update([
                'status' => WebsiteScan::STATUS_ANALYZING,
                'progress' => 0.7,
            ]);

            $analysis = $analyzer->analyze($extractedContent);

            $scan->update([
                'brand_profile' => $analysis['brand_profile'],
                'seo_profile' => $analysis['seo_profile'],
                'design_profile' => $analysis['design_profile'],
                'technical_profile' => $analysis['technical_profile'],
                'suggested_briefs' => $analysis['suggested_briefs'],
                'status' => WebsiteScan::STATUS_COMPLETED,
                'progress' => 1.0,
                'completed_at' => now(),
            ]);

            $this->logStage(self::STAGE_COMPLETED, [
                'scan_id' => $this->scanId,
                'briefs_suggested' => count($analysis['suggested_briefs'] ?? []),
            ]);

        } catch (Throwable $e) {
            $this->handleFailure($e, $currentStage);
        }
    }

    private function handleFailure(Throwable $e, string $stage): void
    {
        $scan = WebsiteScan::find($this->scanId);

        if ($scan) {
            $errorCode = $this->categorizeError($e);

            $scan->update([
                'status' => WebsiteScan::STATUS_FAILED,
                'failed_at' => now(),
                'error_code' => $errorCode,
                'error_message' => mb_substr($e->getMessage(), 0, 5000),
                'meta' => array_merge($scan->meta ?? [], [
                    'failed_stage' => $stage,
                    'failed_at_attempt' => $this->attempts(),
                ]),
            ]);
        }

        $this->logStage(self::STAGE_FAILED, [
            'scan_id' => $this->scanId,
            'stage' => $stage,
            'error_code' => $errorCode ?? 'UNKNOWN',
            'error' => $e->getMessage(),
            'attempt' => $this->attempts(),
        ]);

        // Only retry if the error is transient
        if (! $this->isRetryable($e)) {
            $this->fail($e);

            return;
        }

        throw $e;
    }

    private function isRetryable(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        $retryablePatterns = [
            'timeout',
            'connection',
            'rate limit',
            '429',
            '500',
            '502',
            '503',
            'temporarily',
            'too many requests',
            'service unavailable',
        ];

        foreach ($retryablePatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function categorizeError(Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'timeout')) {
            return 'TIMEOUT';
        }
        if (str_contains($message, 'ssl') || str_contains($message, 'certificate')) {
            return 'SSL_ERROR';
        }
        if (str_contains($message, 'connection') || str_contains($message, 'could not resolve')) {
            return 'CONNECTION_ERROR';
        }
        if (str_contains($message, '404') || str_contains($message, 'not found')) {
            return 'NOT_FOUND';
        }
        if (str_contains($message, '403') || str_contains($message, 'forbidden')) {
            return 'ACCESS_DENIED';
        }
        if (str_contains($message, '429') || str_contains($message, 'rate limit')) {
            return 'RATE_LIMITED';
        }
        if (str_contains($message, 'llm') || str_contains($message, 'ai')) {
            return 'AI_ANALYSIS_FAILED';
        }

        return 'SCAN_FAILED';
    }

    private function logStage(string $stage, array $context = []): void
    {
        $level = $stage === self::STAGE_FAILED ? 'error' : 'info';

        Log::log($level, "ScanWebsiteJob: {$stage}", array_merge([
            'job_stage' => $stage,
            'scan_id' => $this->scanId,
        ], $context));
    }

    /**
     * Handle permanent job failure.
     */
    public function failed(Throwable $exception): void
    {
        $scan = WebsiteScan::find($this->scanId);

        if ($scan && $scan->status !== WebsiteScan::STATUS_COMPLETED) {
            $scan->update([
                'status' => WebsiteScan::STATUS_FAILED,
                'failed_at' => now(),
                'error_code' => 'PERMANENTLY_FAILED',
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                'meta' => array_merge($scan->meta ?? [], [
                    'permanently_failed' => true,
                    'final_attempt' => $this->attempts(),
                ]),
            ]);
        }

        Log::error('ScanWebsiteJob permanently failed', [
            'scan_id' => $this->scanId,
            'attempt' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);
    }
}
