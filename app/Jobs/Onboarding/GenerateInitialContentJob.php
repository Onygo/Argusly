<?php

namespace App\Jobs\Onboarding;

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\WebsiteScan;
use App\Services\BriefProcessing\BriefToDraftService;
use App\Services\Content\ContentDeduplicationService;
use App\Support\ContentPersistencePayloadNormalizer;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GenerateInitialContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly string $scanId,
        public readonly string $clientSiteId,
    ) {
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('initial-content:' . $this->scanId . ':' . $this->clientSiteId))
                ->expireAfter(600)
                ->releaseAfter(60),
        ];
    }

    public function handle(BriefToDraftService $draftService, ContentDeduplicationService $contentDeduplicationService): void
    {
        $scan = WebsiteScan::find($this->scanId);

        if (! $scan) {
            Log::warning('GenerateInitialContentJob: Scan not found', [
                'scan_id' => $this->scanId,
            ]);

            return;
        }

        if (! $scan->user_confirmed) {
            Log::info('GenerateInitialContentJob: Scan not confirmed, skipping', [
                'scan_id' => $this->scanId,
            ]);

            return;
        }

        $suggestedBriefs = $scan->suggested_briefs ?? [];

        if (empty($suggestedBriefs)) {
            Log::info('GenerateInitialContentJob: No brief suggestions available', [
                'scan_id' => $this->scanId,
            ]);

            return;
        }

        Log::info('GenerateInitialContentJob: Starting content generation', [
            'scan_id' => $this->scanId,
            'client_site_id' => $this->clientSiteId,
            'suggested_briefs_count' => count($suggestedBriefs),
        ]);

        $createdBriefs = [];
        $createdContentIds = [];
        $site = ClientSite::query()->with('workspace')->find($this->clientSiteId);
        if (! $site?->workspace_id) {
            Log::warning('GenerateInitialContentJob: Client site missing workspace', [
                'scan_id' => $this->scanId,
                'client_site_id' => $this->clientSiteId,
            ]);

            return;
        }

        $requestedLocale = $site?->workspace?->defaultContentLanguageCode() ?? 'en';

        // Create 3-5 briefs from suggestions
        $briefsToCreate = array_slice($suggestedBriefs, 0, 5);

        foreach ($briefsToCreate as $index => $suggestion) {
            try {
                // Create Content record first
                $externalKey = 'onboarding-scan-' . $this->scanId . '-' . $index;
                $content = $contentDeduplicationService->createOrReuse([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => (string) $site->workspace_id,
                    'client_site_id' => $this->clientSiteId,
                    'title' => $suggestion['title'] ?? 'Untitled Content',
                    'language' => $requestedLocale,
                    'translation_source_locale' => null,
                    'is_source_locale' => true,
                    'status' => 'draft',
                    'source' => 'onboarding_scan',
                    'external_key' => $externalKey,
                ], [
                    'workspace_id' => (string) $site->workspace_id,
                    'client_site_id' => $this->clientSiteId,
                    'scan_id' => $this->scanId,
                    'suggestion_index' => (string) $index,
                    'language' => $requestedLocale,
                    'type' => 'article',
                    'external_key' => $externalKey,
                ]);

                $createdContentIds[] = $content->id;

                // Create Brief linked to Content
                $brief = Brief::query()
                    ->where('content_id', (string) $content->id)
                    ->latest('created_at')
                    ->first();

                if (! $brief) {
                    $brief = Brief::create(ContentPersistencePayloadNormalizer::normalizeBrief([
                    'id' => (string) Str::uuid(),
                    'client_site_id' => $this->clientSiteId,
                    'content_id' => $content->id,
                    'status' => 'draft',
                    'source' => 'onboarding_scan',
                    'title' => $suggestion['title'] ?? 'Untitled Brief',
                    'language' => $requestedLocale,
                    'primary_keyword' => $suggestion['primary_keyword'] ?? $suggestion['title'] ?? null,
                    'audience' => $suggestion['audience'] ?? null,
                    'intent' => $this->normalizeIntent($suggestion['intent'] ?? 'informational'),
                    'notes' => $suggestion['notes'] ?? null,
                    'content_type' => 'blog_post',
                    'output_type' => 'blog_post',
                    ]));
                }

                $createdBriefs[] = $brief;

                Log::info('GenerateInitialContentJob: Brief created', [
                    'scan_id' => $this->scanId,
                    'brief_id' => $brief->id,
                    'content_id' => $content->id,
                    'title' => $brief->title,
                ]);

            } catch (Throwable $e) {
                Log::error('GenerateInitialContentJob: Failed to create brief', [
                    'scan_id' => $this->scanId,
                    'suggestion_index' => $index,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Generate draft for the first brief only
        if (! empty($createdBriefs)) {
            $firstBrief = $createdBriefs[0];

            try {
                $firstBrief->update(['status' => 'queued']);
                $draft = $draftService->claimAndCreateDraft((string) $firstBrief->id);

                Log::info('GenerateInitialContentJob: Draft generation queued', [
                    'scan_id' => $this->scanId,
                    'brief_id' => $firstBrief->id,
                    'draft_id' => $draft?->id,
                ]);

            } catch (Throwable $e) {
                Log::error('GenerateInitialContentJob: Failed to queue draft generation', [
                    'scan_id' => $this->scanId,
                    'brief_id' => $firstBrief->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Update scan meta with created brief and content IDs
        $scan->update([
            'meta' => array_merge($scan->meta ?? [], [
                'created_brief_ids' => collect($createdBriefs)->pluck('id')->toArray(),
                'created_content_ids' => $createdContentIds,
                'content_generation_completed_at' => now()->toIso8601String(),
            ]),
        ]);

        Log::info('GenerateInitialContentJob: Completed', [
            'scan_id' => $this->scanId,
            'briefs_created' => count($createdBriefs),
            'contents_created' => count($createdContentIds),
        ]);
    }

    private function normalizeIntent(?string $intent): string
    {
        $intent = strtolower(trim($intent ?? ''));

        $validIntents = ['informational', 'transactional', 'navigational', 'commercial'];

        return in_array($intent, $validIntents, true) ? $intent : 'informational';
    }

    public function failed(Throwable $exception): void
    {
        Log::error('GenerateInitialContentJob: Job failed', [
            'scan_id' => $this->scanId,
            'client_site_id' => $this->clientSiteId,
            'error' => $exception->getMessage(),
        ]);
    }
}
