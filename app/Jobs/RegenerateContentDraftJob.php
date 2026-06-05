<?php

namespace App\Jobs;

use App\Models\Content;
use App\Models\CreditAction;
use App\Models\Draft;
use App\Exceptions\InsufficientCreditsException;
use App\Services\Content\ContentLifecycleService;
use App\Services\CreditWalletService;
use App\Services\DraftGenerationService;
use App\Services\PlanQuotaService;
use App\Support\SeoMetadata;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RegenerateContentDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public bool $failOnTimeout = true;

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(
        public string $draftId,
        public int $userId,
        public bool $autoRepushToWp = false
    ) {}

    public function handle(
        DraftGenerationService $draftGenerationService,
        ContentLifecycleService $contentLifecycleService,
        CreditWalletService $creditWalletService,
        PlanQuotaService $planQuotaService
    ): void {
        $draft = Draft::query()->findOrFail($this->draftId);
        $content = $draft->content_id ? Content::query()->find($draft->content_id) : null;

        $creditCost = $this->resolveCreditCostForRegeneration($draft);
        if ($creditCost <= 0) {
            $draft->status = 'failed';
            $draft->last_error = 'Draft has no credit cost configured.';
            $draft->save();
            return;
        }

        try {
            $draft->status = 'processing';
            $draft->last_error = null;
            $draft->save();

            $creditWalletService->reserveForDraft($draft, (string) $this->userId);
            $result = $draftGenerationService->generateWithRepair($draft, 2);

            $existingMeta = is_array($draft->meta) ? $draft->meta : [];
            $resultMeta = (array) ($result['meta'] ?? []);
            $mergedMeta = array_replace_recursive($existingMeta, $resultMeta);
            $seoFields = SeoMetadata::merge(
                [
                    'seo_title' => $result['title'] ?? $draft->title,
                    'seo_meta_description' => data_get($result, 'meta.description'),
                    'robots_index' => data_get($result, 'meta.robots_index'),
                    'robots_follow' => data_get($result, 'meta.robots_follow'),
                    'schema_type' => data_get($result, 'meta.schema_type'),
                ],
                $mergedMeta,
                [
                    'seo_title' => $draft->seo_title,
                    'seo_meta_description' => $draft->seo_meta_description,
                    'seo_h1' => $draft->seo_h1,
                    'seo_canonical' => $draft->seo_canonical,
                    'seo_og_title' => $draft->seo_og_title,
                    'seo_og_description' => $draft->seo_og_description,
                    'seo_og_image' => $draft->seo_og_image,
                    'seo_twitter_title' => $draft->seo_twitter_title,
                    'seo_twitter_description' => $draft->seo_twitter_description,
                    'robots_index' => $draft->robots_index,
                    'robots_follow' => $draft->robots_follow,
                    'schema_type' => $draft->schema_type,
                ],
            );
            if (trim((string) ($seoFields['seo_h1'] ?? '')) === '') {
                $seoFields['seo_h1'] = $seoFields['seo_title'] ?: ($result['title'] ?? $draft->title);
            }
            $mergedMeta = array_replace_recursive($mergedMeta, array_filter([
                'meta_description' => $seoFields['seo_meta_description'],
                'canonical_url' => $seoFields['seo_canonical'],
                'og_title' => $seoFields['seo_og_title'],
                'og_description' => $seoFields['seo_og_description'],
                'og_image' => $seoFields['seo_og_image'],
                'twitter_title' => $seoFields['seo_twitter_title'],
                'twitter_description' => $seoFields['seo_twitter_description'],
                'robots_index' => $seoFields['robots_index'],
                'robots_follow' => $seoFields['robots_follow'],
                'schema_type' => $seoFields['schema_type'],
            ], static fn ($value) => is_bool($value) || trim((string) $value) !== ''));
            $mergedMeta['generation'] = array_filter([
                'provider' => (string) data_get($result, 'provider', config('llm.default_provider', 'openai')),
                'model' => (string) data_get($result, 'model', ''),
                'tokens' => (int) data_get($result, 'usage.total_tokens', 0),
                'input_tokens' => (int) data_get($result, 'usage.input_tokens', 0),
                'output_tokens' => (int) data_get($result, 'usage.output_tokens', 0),
                'request_id' => (string) data_get($result, 'request_id', ''),
                'credits' => $creditCost,
                'generated_at' => now()->toIso8601String(),
                'trigger' => 'app_content_regenerate_job',
            ], fn ($value) => $value !== null);

            $draft->meta = $mergedMeta;
            $draft->save();

            $creditWalletService->commitUsageForDraft($draft, (string) $this->userId);
            if ($draft->clientSite?->workspace) {
                $planQuotaService->incrementUsage(
                    workspace: $draft->clientSite->workspace,
                    site: $draft->clientSite,
                    metric: PlanQuotaService::METRIC_ARTICLES_GENERATED,
                    amount: 1,
                );
            }

            $draft->status = 'generated';
            $draft->title = $result['title'] ?? $draft->title;
            $draft->seo_title = $seoFields['seo_title'] ?: ($result['title'] ?? $draft->title);
            $draft->seo_meta_description = $seoFields['seo_meta_description'] ?: $draft->seo_meta_description;
            $draft->seo_h1 = $seoFields['seo_h1'] ?: $draft->seo_h1;
            $draft->seo_canonical = $seoFields['seo_canonical'] ?: $draft->seo_canonical;
            $draft->seo_og_title = $seoFields['seo_og_title'] ?: $draft->seo_og_title;
            $draft->seo_og_description = $seoFields['seo_og_description'] ?: $draft->seo_og_description;
            $draft->seo_og_image = $seoFields['seo_og_image'] ?: $draft->seo_og_image;
            $draft->seo_twitter_title = $seoFields['seo_twitter_title'] ?: $draft->seo_twitter_title;
            $draft->seo_twitter_description = $seoFields['seo_twitter_description'] ?: $draft->seo_twitter_description;
            $draft->robots_index = $seoFields['robots_index'] ?? $draft->robots_index;
            $draft->robots_follow = $seoFields['robots_follow'] ?? $draft->robots_follow;
            $draft->schema_type = $seoFields['schema_type'] ?: $draft->schema_type;
            $draft->content_html = $result['content_html'] ?? $draft->content_html;
            $draft->meta = $mergedMeta;
            $draft->links = $result['links'] ?? $draft->links;
            $draft->delivery_status = 'pending';
            $draft->delivery_last_error = null;
            $draft->last_error = null;
            $draft->delivered_at = now();
            $draft->save();

            try {
                if ($content) {
                    $contentLifecycleService->ensureRevisionFromDraft($draft, $this->userId);
                }

                if ($this->autoRepushToWp) {
                    $draft->status = 'ready_to_deliver';
                    $draft->delivery_status = 'pending';
                    $draft->delivery_last_error = null;
                    $draft->save();

                    DeliverDraftJob::dispatch((string) $draft->id)->onQueue((string) config('publishlayer.webhooks.queue', 'deliveries'));
                }
            } catch (Throwable $postProcessException) {
                Log::warning('RegenerateContentDraftJob post-process failed after successful generation.', [
                    'draft_id' => (string) $draft->id,
                    'content_id' => (string) ($draft->content_id ?? ''),
                    'error' => $postProcessException->getMessage(),
                ]);

                $draft->last_error = 'Post-generation warning: '.mb_substr($postProcessException->getMessage(), 0, 4500);
                $draft->save();
            }
        } catch (Throwable $exception) {
            $draft->refresh();
            if ($draft->credit_status === 'reserved') {
                try {
                    $creditWalletService->releaseReservationForDraft($draft, (string) $this->userId);
                } catch (Throwable) {
                    // Best effort release.
                }
            }

            $draft->status = 'failed';
            $draft->last_error = mb_substr($exception->getMessage(), 0, 5000);
            $draft->save();

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('RegenerateContentDraftJob failed.', [
            'draft_id' => $this->draftId,
            'user_id' => $this->userId,
            'auto_repush_to_wp' => $this->autoRepushToWp,
            'error' => $exception->getMessage(),
        ]);

        $draft = Draft::query()->find($this->draftId);
        if (! $draft) {
            return;
        }

        $draft->status = 'failed';
        $draft->last_error = $exception instanceof InsufficientCreditsException
            ? mb_substr(sprintf(
                'INSUFFICIENT_CREDITS: Insufficient credits. Required: %d, available: %d. Buy extra credits to continue.',
                $exception->required,
                $exception->available,
            ), 0, 5000)
            : mb_substr($exception->getMessage(), 0, 5000);
        $draft->save();
    }

    private function resolveCreditCostForRegeneration(Draft $draft): int
    {
        $currentCost = (int) ($draft->credit_cost ?? 0);
        if ($currentCost > 0) {
            return $currentCost;
        }

        $preferredActionKey = match (strtolower((string) $draft->output_type)) {
            'faq', 'faq_set' => 'content.faq_set',
            'outline' => 'content.outline',
            'brief' => 'content.brief',
            default => 'content.article',
        };

        $action = CreditAction::query()
            ->where('key', $preferredActionKey)
            ->where('is_active', true)
            ->first();

        if (! $action) {
            $action = CreditAction::query()
                ->where('category', 'content')
                ->where('is_active', true)
                ->orderBy('credits_cost')
                ->first();
        }

        if (! $action) {
            return 0;
        }

        $draft->credit_action_id = $draft->credit_action_id ?: $action->id;
        $draft->credit_cost = (int) $action->credits_cost;
        $draft->save();

        return (int) $draft->credit_cost;
    }
}
