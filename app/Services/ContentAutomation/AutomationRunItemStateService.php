<?php

namespace App\Services\ContentAutomation;

use App\Enums\ContentAutomationPublicationMode;
use App\Enums\ContentAutomationRunStatus;
use App\Exceptions\InsufficientCreditsException;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\ContentAutomationRun;
use App\Models\ContentAutomationRunItem;
use App\Models\Draft;
use App\Models\ContentPublication;
use Throwable;

class AutomationRunItemStateService
{
    public function __construct(
        private readonly AutomationLocaleResolver $localeResolver,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $articles
     * @return array{source_items: array<string, ContentAutomationRunItem>, translation_items: array<string, array<string, ContentAutomationRunItem>>, all_items: array<int, ContentAutomationRunItem>}
     */
    public function createPlannedItems(ContentAutomation $automation, ContentAutomationRun $run, array $articles): array
    {
        $allItems = [];
        $sourceItems = [];
        $translationItems = [];
        $existingItems = ContentAutomationRunItem::query()
            ->where('automation_run_id', (string) $run->id)
            ->get()
            ->keyBy(fn (ContentAutomationRunItem $item): string => $this->itemKey(
                (string) $item->item_type,
                (int) $item->chain_index,
                (string) $item->locale
            ));

        foreach ($this->localeResolver->buildResultBlueprints($automation, $articles) as $blueprint) {
            $key = $this->itemKey(
                (string) $blueprint['item_type'],
                (int) $blueprint['chain_index'],
                (string) $blueprint['locale']
            );

            /** @var ContentAutomationRunItem|null $existing */
            $existing = $existingItems->get($key);

            $payload = [
                'automation_run_id' => (string) $run->id,
                'automation_id' => (string) $automation->id,
                'chain_index' => (int) $blueprint['chain_index'],
                'client_site_id' => $automation->client_site_id,
                'locale' => (string) $blueprint['locale'],
                'source_locale' => (string) $blueprint['source_locale'],
                'is_source_locale' => (bool) $blueprint['is_source_locale'],
                'item_type' => (string) $blueprint['item_type'],
                'title' => (string) $blueprint['title'],
                'metadata' => array_merge(is_array($existing?->metadata) ? $existing->metadata : [], [
                    'plan' => $blueprint['plan'],
                    'source_key' => (string) $blueprint['source_key'],
                    'source_sequence' => (int) $blueprint['source_sequence'],
                ]),
            ];

            if (! $existing instanceof ContentAutomationRunItem) {
                $payload['status'] = ContentAutomationRunItem::STATUS_PLANNED;
                $payload['generation_status'] = (string) ((string) $blueprint['item_type'] === 'source' ? 'planned' : 'pending');
                $payload['translation_status'] = (string) ((string) $blueprint['item_type'] === 'source' ? 'not_required' : 'pending');
            }

            $item = ContentAutomationRunItem::query()->updateOrCreate([
                'automation_run_id' => (string) $run->id,
                'item_type' => (string) $blueprint['item_type'],
                'chain_index' => (int) $blueprint['chain_index'],
                'locale' => (string) $blueprint['locale'],
            ], $payload);

            $allItems[] = $item;

            if ($item->item_type === ContentAutomationRunItem::TYPE_SOURCE) {
                $sourceItems[(string) $blueprint['source_key']] = $item;
            } else {
                $translationItems[(string) $blueprint['source_key']][(string) $blueprint['locale']] = $item;
            }
        }

        foreach ($translationItems as $sourceKey => $items) {
            $sourceItem = $sourceItems[$sourceKey] ?? null;

            if (! $sourceItem instanceof ContentAutomationRunItem) {
                continue;
            }

            foreach ($items as $translationItem) {
                $translationItem->forceFill([
                    'source_run_item_id' => (string) $sourceItem->id,
                ])->save();
            }
        }

        return [
            'source_items' => $sourceItems,
            'translation_items' => $translationItems,
            'all_items' => $allItems,
        ];
    }

    private function itemKey(string $itemType, int $chainIndex, string $locale): string
    {
        return implode('|', [$itemType, (string) $chainIndex, $locale]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function recordSourceResult(
        ContentAutomation $automation,
        ContentAutomationRun $run,
        ContentAutomationRunItem $sourceItem,
        array $result,
    ): void {
        $content = $sourceItem->content()->with(['drafts', 'publications'])->first()
            ?? Content::query()->with(['drafts', 'publications'])->find((string) ($result['content_id'] ?? ''));

        if ($content instanceof Content && $content->drafts->isNotEmpty()) {
            $this->syncFromContent($content);
        }

        $sourceItem = $sourceItem->fresh() ?? $sourceItem;
        $rootId = (string) ($content?->localizationRootId() ?? $sourceItem->content_family_id ?? $sourceItem->content_id ?? '');

        if ($content instanceof Content && $content->drafts->isEmpty()) {
            $sourceItem->forceFill([
                'status' => (string) ($result['item_status'] ?? ContentAutomationRunItem::STATUS_COMPLETED),
                'locale' => $content->localeCode(),
                'source_locale' => $content->localeCode(),
                'is_source_locale' => true,
                'content_family_id' => $rootId !== '' ? $rootId : (string) $content->id,
                'generation_status' => (string) (($result['item_status'] ?? ContentAutomationRunItem::STATUS_COMPLETED) === ContentAutomationRunItem::STATUS_COMPLETED ? 'completed' : 'pending'),
                'translation_status' => ContentAutomationRunItem::TRANSLATION_STATUS_NOT_REQUIRED,
                'delivery_status' => $content->delivery_status,
                'publication_status' => $content->publish_status,
            ])->save();
        }

        if ($content instanceof Content) {
            $this->appendHistoryEntry(
                $sourceItem,
                'generated:' . (string) $content->id,
                sprintf('Generated %s source', strtoupper($content->localeCode()))
            );
        }

        $queueResults = collect((array) ($result['translation_queue_results'] ?? []))
            ->keyBy(fn (array $queueResult): string => (string) ($queueResult['locale'] ?? ''));
        $queueErrors = collect((array) ($result['translation_errors'] ?? []))
            ->keyBy(fn (array $error): string => (string) ($error['locale'] ?? ''));

        $translationItems = ContentAutomationRunItem::query()
            ->where('source_run_item_id', (string) $sourceItem->id)
            ->get()
            ->keyBy('locale');

        foreach ($translationItems as $locale => $item) {
            $queueResult = $queueResults->get((string) $locale);
            $queueError = $queueErrors->get((string) $locale);

            $payload = [
                'source_locale' => (string) ($content?->localeCode() ?? $sourceItem->locale),
                'is_source_locale' => false,
                'content_family_id' => $rootId !== '' ? $rootId : null,
                'last_error_code' => null,
                'last_error_message' => null,
            ];

            if (is_array($queueError)) {
                $payload['status'] = ContentAutomationRunItem::STATUS_FAILED;
                $payload['generation_status'] = 'pending';
                $payload['translation_status'] = 'failed';
                $payload['failure_stage'] = 'translation_queue';
                $payload['last_error_code'] = 'translation_queue_failed';
                $payload['last_error_message'] = (string) ($queueError['message'] ?? 'Translation queue failed.');
            } elseif (is_array($queueResult)) {
                $payload['status'] = ContentAutomationRunItem::STATUS_PARTIAL;
                $payload['generation_status'] = 'pending';
                $payload['translation_status'] = (string) ($queueResult['mode'] ?? 'queued') === 'refresh' ? 'refresh_queued' : 'queued';
                $payload['failure_stage'] = null;
                $payload['metadata'] = array_merge(is_array($item->metadata) ? $item->metadata : [], [
                    'queue_result' => $queueResult,
                ]);

                $existingVariantId = trim((string) ($queueResult['existing_variant_id'] ?? ''));
                if ($existingVariantId !== '') {
                    $payload['content_id'] = $existingVariantId;
                }
            }

            $item->forceFill($payload)->save();

            if (is_array($queueError)) {
                $this->appendHistoryEntry(
                    $item,
                    'translation_failed:' . (string) $locale . ':' . md5((string) ($queueError['message'] ?? '')),
                    sprintf('%s translation failed', strtoupper((string) $locale))
                );
            } elseif (is_array($queueResult)) {
                $this->appendHistoryEntry(
                    $item,
                    'translation_queued:' . (string) $locale . ':' . (string) ($queueResult['mode'] ?? 'translate'),
                    sprintf(
                        '%s %s',
                        (string) ($queueResult['mode'] ?? 'translate') === 'refresh'
                            ? 'Refreshing existing'
                            : 'Queued',
                        strtoupper((string) $locale) . ' translation'
                    )
                );
            }

            if (filled($item->content_id)) {
                $existingContent = Content::query()->with(['drafts', 'publications'])->find((string) $item->content_id);
                if ($existingContent instanceof Content) {
                    $this->syncFromContent($existingContent);
                }
            }
        }

        $this->syncRun($run);
    }

    public function syncFromContent(Content $content): void
    {
        $runId = trim((string) ($content->automation_run_id ?? ''));
        $automationId = trim((string) ($content->automation_id ?? ''));

        if ($runId === '' || $automationId === '') {
            return;
        }

        $content->loadMissing(['drafts' => fn ($query) => $query->latest('created_at'), 'publications']);

        $rootId = $content->localizationRootId();
        $expectedItemType = $content->is_source_locale
            ? ContentAutomationRunItem::TYPE_SOURCE
            : ContentAutomationRunItem::TYPE_TRANSLATION;
        $items = ContentAutomationRunItem::query()
            ->where('automation_run_id', $runId)
            ->where('automation_id', $automationId)
            ->where('item_type', $expectedItemType)
            ->where(function ($query) use ($content, $rootId): void {
                $query->where('content_id', (string) $content->id);

                if ($rootId !== '') {
                    $query->orWhere(function ($familyQuery) use ($content, $rootId): void {
                        $familyQuery->where('content_family_id', $rootId)
                            ->where('locale', $content->localeCode());
                    });
                }
            })
            ->get();

        foreach ($items as $item) {
            $this->applyContentStateToItem($item, $content);
        }

        $run = ContentAutomationRun::query()->find($runId);
        if ($run instanceof ContentAutomationRun) {
            $this->syncRun($run);
        }
    }

    public function markTranslationFailure(Draft $sourceDraft, string $targetLocale, Throwable $exception): void
    {
        if ($exception instanceof InsufficientCreditsException) {
            $this->markTranslationInsufficientCreditsFailure($sourceDraft, $targetLocale, $exception);

            return;
        }

        $content = $sourceDraft->content;
        if (! $content instanceof Content) {
            return;
        }

        $runId = trim((string) ($content->automation_run_id ?? ''));
        $automationId = trim((string) ($content->automation_id ?? ''));
        if ($runId === '' || $automationId === '') {
            return;
        }

        $rootId = $content->localizationRootId();

        $item = ContentAutomationRunItem::query()
            ->where('automation_run_id', $runId)
            ->where('automation_id', $automationId)
            ->where('item_type', ContentAutomationRunItem::TYPE_TRANSLATION)
            ->where('locale', $targetLocale)
            ->when($rootId !== '', fn ($query) => $query->where('content_family_id', $rootId))
            ->orderByDesc('updated_at')
            ->first();

        if (! $item instanceof ContentAutomationRunItem) {
            return;
        }

        $item->forceFill([
            'status' => ContentAutomationRunItem::STATUS_FAILED,
            'failure_stage' => 'translation',
            'last_error_code' => 'translation_failed',
            'last_error_message' => $exception->getMessage(),
            'generation_status' => 'pending',
            'translation_status' => 'failed',
            'finished_at' => now(),
        ])->save();

        $run = ContentAutomationRun::query()->find($runId);
        if ($run instanceof ContentAutomationRun) {
            $this->syncRun($run);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function markTranslationInsufficientCreditsFailure(
        Draft $sourceDraft,
        string $targetLocale,
        InsufficientCreditsException $exception,
        array $context = [],
    ): void {
        $content = $sourceDraft->content;
        if (! $content instanceof Content) {
            return;
        }

        $runId = trim((string) ($content->automation_run_id ?? ''));
        $automationId = trim((string) ($content->automation_id ?? ''));
        if ($runId === '' || $automationId === '') {
            return;
        }

        $rootId = $content->localizationRootId();
        $item = ContentAutomationRunItem::query()
            ->where('automation_run_id', $runId)
            ->where('automation_id', $automationId)
            ->where('item_type', ContentAutomationRunItem::TYPE_TRANSLATION)
            ->where('locale', $targetLocale)
            ->when($rootId !== '', fn ($query) => $query->where('content_family_id', $rootId))
            ->orderByDesc('updated_at')
            ->first();

        if (! $item instanceof ContentAutomationRunItem) {
            return;
        }

        $failureDetails = $this->insufficientCreditsFailureDetails(
            $exception,
            array_merge($context, [
                'run_id' => $runId,
                'automation_id' => $automationId,
                'source_draft_id' => (string) $sourceDraft->id,
                'source_content_id' => (string) ($content->id ?? ''),
                'target_locale' => $targetLocale,
            ]),
        );

        $item->forceFill([
            'status' => ContentAutomationRunItem::STATUS_FAILED,
            'failure_stage' => 'translation',
            'last_error_code' => 'insufficient_credits',
            'last_error_message' => $failureDetails['user_safe_message'],
            'generation_status' => 'pending',
            'translation_status' => 'failed',
            'finished_at' => now(),
            'metadata' => array_merge(is_array($item->metadata) ? $item->metadata : [], [
                'failure_pattern' => $failureDetails['pattern'],
                'failure_code' => $failureDetails['error_code'],
                'failure_details' => $failureDetails,
            ]),
        ])->save();

        $run = ContentAutomationRun::query()->find($runId);
        if ($run instanceof ContentAutomationRun) {
            $this->syncRun($run);
        }
    }

    public function syncRun(ContentAutomationRun $run): void
    {
        $run->loadMissing(['items', 'automation']);

        $items = $run->items;
        $contentIds = Content::query()
            ->where('automation_run_id', (string) $run->id)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $publishedContentIds = Content::query()
            ->where('automation_run_id', (string) $run->id)
            ->where(function ($query): void {
                $query->where('publish_status', 'published')
                    ->orWhere('status', 'published');
            })
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $truth = [
            'intended_count' => $items->count(),
            'completed_count' => $items->where('status', ContentAutomationRunItem::STATUS_COMPLETED)->count(),
            'failed_count' => $items->where('status', ContentAutomationRunItem::STATUS_FAILED)->count(),
            'partial_count' => $items->where('status', ContentAutomationRunItem::STATUS_PARTIAL)->count(),
            'skipped_count' => $items->where('status', ContentAutomationRunItem::STATUS_SKIPPED)->count(),
            'pending_count' => $items->whereIn('status', [
                ContentAutomationRunItem::STATUS_PLANNED,
                ContentAutomationRunItem::STATUS_RUNNING,
            ])->count(),
            'generated_count' => count($contentIds),
            'generated_content_ids' => $contentIds,
            'published_content_ids' => $publishedContentIds,
        ];

        $status = $this->runStatusFromTruth($truth, $run);
        $lastError = $items
            ->filter(fn (ContentAutomationRunItem $item): bool => filled($item->last_error_message))
            ->sortByDesc('updated_at')
            ->first();

        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $metadata['truth'] = $truth;
        $metadata['last_error_code'] = $lastError?->last_error_code;
        $metadata['last_error_message'] = $lastError?->last_error_message;
        $metadata['last_failure_stage'] = $lastError?->failure_stage;
        $metadata['failure_pattern'] = data_get($lastError?->metadata, 'failure_pattern');
        $metadata['failure_code'] = data_get($lastError?->metadata, 'failure_code');
        $metadata['failure_details'] = data_get($lastError?->metadata, 'failure_details');

        $run->forceFill([
            'status' => $status->value,
            'generated_content_ids' => $contentIds,
            'published_content_ids' => $publishedContentIds,
            'result_summary' => $this->summaryFromTruth($truth),
            'error_message' => data_get($lastError?->metadata, 'failure_details.user_safe_message', $lastError?->last_error_message ?? $run->error_message),
            'metadata' => $metadata,
        ])->save();
    }

    private function applyContentStateToItem(ContentAutomationRunItem $item, Content $content): void
    {
        $content->loadMissing(['drafts' => fn ($query) => $query->latest('created_at'), 'publications']);
        $latestDraft = $content->drafts->sortByDesc('created_at')->first();

        $generationStatus = $this->generationStatus($latestDraft);
        $translationStatus = $item->item_type === ContentAutomationRunItem::TYPE_TRANSLATION
            ? $this->translationStatus($content, $generationStatus)
            : ContentAutomationRunItem::TRANSLATION_STATUS_NOT_REQUIRED;
        $deliveryStatus = $this->deliveryStatus($content);
        $publicationStatus = $this->publicationStatus($content);
        $status = $this->itemStatus($item, $content, $generationStatus, $translationStatus, $deliveryStatus, $publicationStatus);

        $item->forceFill([
            'content_id' => (string) $content->id,
            'draft_id' => $latestDraft?->id,
            'locale' => $content->localeCode(),
            'source_locale' => $content->translation_source_locale ?: $content->localeCode(),
            'is_source_locale' => (bool) $content->is_source_locale,
            'content_family_id' => $content->localizationRootId(),
            'generation_status' => $generationStatus,
            'translation_status' => $translationStatus,
            'delivery_status' => $deliveryStatus,
            'publication_status' => $publicationStatus,
            'status' => $status,
            'failure_stage' => $status === ContentAutomationRunItem::STATUS_FAILED ? ($item->failure_stage ?: 'content_state') : $item->failure_stage,
            'finished_at' => $status === ContentAutomationRunItem::STATUS_COMPLETED ? now() : $item->finished_at,
        ])->save();

        $this->appendLifecycleHistory($item, $content, $generationStatus, $translationStatus, $deliveryStatus, $publicationStatus);
    }

    private function generationStatus(?Draft $draft): string
    {
        if (! $draft instanceof Draft) {
            return 'pending';
        }

        return match ((string) $draft->status) {
            'failed' => 'failed',
            'ready', 'delivered', 'published' => 'completed',
            'queued' => 'queued',
            'generating' => 'running',
            default => 'pending',
        };
    }

    private function translationStatus(Content $content, string $generationStatus): string
    {
        if (! $content->isTranslationVariant()) {
            return ContentAutomationRunItem::TRANSLATION_STATUS_NOT_REQUIRED;
        }

        if ($generationStatus === 'failed') {
            return 'failed';
        }

        return $generationStatus === 'completed'
            ? 'completed'
            : 'queued';
    }

    private function deliveryStatus(Content $content): ?string
    {
        $publication = $content->publications
            ->first(fn (ContentPublication $publication): bool => $publication->locale?->value === $content->localeCode()
                || (string) $publication->getRawOriginal('locale') === $content->localeCode())
            ?? $content->publications->sortByDesc('updated_at')->first();

        return $publication?->delivery_status ?: ($content->delivery_status ?: null);
    }

    private function publicationStatus(Content $content): ?string
    {
        $publication = $content->publications
            ->first(fn (ContentPublication $publication): bool => $publication->locale?->value === $content->localeCode()
                || (string) $publication->getRawOriginal('locale') === $content->localeCode())
            ?? $content->publications->sortByDesc('updated_at')->first();

        return (string) ($publication?->remote_status ?: ($content->publish_status ?: 'draft'));
    }

    private function itemStatus(
        ContentAutomationRunItem $item,
        Content $content,
        string $generationStatus,
        string $translationStatus,
        ?string $deliveryStatus,
        ?string $publicationStatus,
    ): string {
        if ($generationStatus === 'failed' || $translationStatus === 'failed' || $deliveryStatus === 'failed' || $publicationStatus === 'failed') {
            return ContentAutomationRunItem::STATUS_FAILED;
        }

        $automation = $item->automation()->first();
        $autoPublish = $automation?->publication_mode === ContentAutomationPublicationMode::AUTO_PUBLISH;

        if ($generationStatus !== 'completed') {
            return ContentAutomationRunItem::STATUS_PARTIAL;
        }

        if ($item->item_type === ContentAutomationRunItem::TYPE_TRANSLATION && $translationStatus !== 'completed') {
            return ContentAutomationRunItem::STATUS_PARTIAL;
        }

        if (! $autoPublish) {
            return ContentAutomationRunItem::STATUS_COMPLETED;
        }

        return in_array((string) $publicationStatus, ['published', 'scheduled'], true)
            || in_array((string) $deliveryStatus, ['delivered', 'partial_success'], true)
                ? ContentAutomationRunItem::STATUS_COMPLETED
                : ContentAutomationRunItem::STATUS_PARTIAL;
    }

    /**
     * @param  array<string, mixed>  $truth
     */
    private function runStatusFromTruth(array $truth, ContentAutomationRun $run): ContentAutomationRunStatus
    {
        $items = $run->relationLoaded('items') ? $run->items : $run->items()->get();
        $hasInsufficientCreditsFailure = $items->contains(function (ContentAutomationRunItem $item): bool {
            return (string) $item->last_error_code === 'insufficient_credits'
                || (string) data_get($item->metadata, 'failure_pattern') === 'insufficient_credits';
        });
        $intended = (int) ($truth['intended_count'] ?? 0);
        $completed = (int) ($truth['completed_count'] ?? 0);
        $failed = (int) ($truth['failed_count'] ?? 0);
        $partial = (int) ($truth['partial_count'] ?? 0);
        $pending = (int) ($truth['pending_count'] ?? 0);
        $skipped = (int) ($truth['skipped_count'] ?? 0);

        if ($intended === 0 && (string) ($run->status?->value ?? $run->status) === ContentAutomationRunStatus::SKIPPED->value) {
            return ContentAutomationRunStatus::SKIPPED;
        }

        if ($completed === $intended && $intended > 0) {
            return ContentAutomationRunStatus::COMPLETED;
        }

        if ($hasInsufficientCreditsFailure) {
            return ContentAutomationRunStatus::FAILED;
        }

        if ($failed === $intended && $intended > 0) {
            return ContentAutomationRunStatus::FAILED;
        }

        if ($skipped === $intended && $intended > 0) {
            return ContentAutomationRunStatus::SKIPPED;
        }

        if ($completed > 0 || $failed > 0 || $partial > 0 || $pending > 0) {
            return ContentAutomationRunStatus::PARTIAL;
        }

        return ContentAutomationRunStatus::FAILED;
    }

    /**
     * @param  array<string, mixed>  $truth
     */
    private function summaryFromTruth(array $truth): string
    {
        return sprintf(
            '%d completed, %d pending, %d failed.',
            (int) ($truth['completed_count'] ?? 0),
            (int) (($truth['partial_count'] ?? 0) + ($truth['pending_count'] ?? 0)),
            (int) ($truth['failed_count'] ?? 0),
        );
    }

    private function appendLifecycleHistory(
        ContentAutomationRunItem $item,
        Content $content,
        string $generationStatus,
        string $translationStatus,
        ?string $deliveryStatus,
        ?string $publicationStatus,
    ): void {
        $locale = strtoupper($content->localeCode());

        if ($item->item_type === ContentAutomationRunItem::TYPE_TRANSLATION && $translationStatus === 'completed') {
            $this->appendHistoryEntry(
                $item,
                'translation_completed:' . (string) $content->id,
                sprintf('%s translation completed', $locale)
            );
        }

        if ($item->item_type === ContentAutomationRunItem::TYPE_SOURCE) {
            if ($publicationStatus === 'scheduled' && $content->scheduled_publish_at) {
                $this->appendHistoryEntry(
                    $item,
                    'source_scheduled:' . $content->scheduled_publish_at->toIso8601String(),
                    'Source scheduled for ' . $content->scheduled_publish_at->format('Y-m-d H:i')
                );
            } elseif (in_array((string) $publicationStatus, ['published', 'delivered'], true) || $generationStatus === 'completed' && (string) $content->publish_status === 'published') {
                $this->appendHistoryEntry(
                    $item,
                    'source_published:' . (string) $content->id,
                    sprintf('Published %s source', $locale)
                );
            }
        }

        if (
            $item->item_type === ContentAutomationRunItem::TYPE_TRANSLATION
            && $content->scheduled_publish_at
            && (bool) ($content->sync_with_source ?? true)
            && in_array((string) $publicationStatus, ['scheduled', 'published'], true)
        ) {
            $this->appendHistoryEntry(
                $item,
                'translation_synced_schedule:' . $content->scheduled_publish_at->toIso8601String(),
                sprintf('%s synced to same publish datetime', $locale)
            );
        }

        if (in_array((string) $deliveryStatus, ['delivered', 'partial_success'], true)) {
            $this->appendHistoryEntry(
                $item,
                'delivered:' . (string) $content->id . ':' . (string) $deliveryStatus,
                sprintf('Delivered %s', $locale)
            );
        }
    }

    private function appendHistoryEntry(ContentAutomationRunItem $item, string $key, string $message): void
    {
        $metadata = is_array($item->metadata) ? $item->metadata : [];
        $historyKeys = collect((array) data_get($metadata, 'history_keys', []))
            ->filter()
            ->values();

        if ($historyKeys->contains($key)) {
            return;
        }

        $history = collect((array) data_get($metadata, 'history', []))
            ->filter(fn ($entry): bool => is_array($entry) && filled($entry['message'] ?? null))
            ->values();

        $history->push([
            'key' => $key,
            'message' => $message,
            'recorded_at' => now()->toIso8601String(),
        ]);

        $metadata['history'] = $history->all();
        $metadata['history_keys'] = $historyKeys->push($key)->all();

        $item->forceFill([
            'metadata' => $metadata,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function insufficientCreditsFailureDetails(
        InsufficientCreditsException $exception,
        array $context = [],
    ): array {
        $required = (int) $exception->required;
        $available = (int) $exception->available;
        $sourceLocation = sprintf('%s:%d', $exception->getFile(), $exception->getLine());
        $runId = $context['run_id'] ?? null;
        $automationId = $context['automation_id'] ?? null;
        $jobClass = $context['job'] ?? null;

        return [
            'pattern' => 'insufficient_credits',
            'error_code' => 'PL-CREDITS-INSUFFICIENT',
            'required_credits' => $required,
            'available_credits' => $available,
            'user_safe_message' => sprintf(
                'This automation could not continue because there are not enough credits available. Required: %d, available: %d. Please add credits or reduce the automation scope and try again.',
                $required,
                $available,
            ),
            'admin_message' => implode("\n", array_filter([
                'Exception: ' . $exception::class,
                'Required credits: ' . $required,
                'Available credits: ' . $available,
                $jobClass ? 'Job: ' . $jobClass : null,
                'Source location: ' . $sourceLocation,
                $runId ? 'Run ID: ' . $runId : null,
                $automationId ? 'Automation ID: ' . $automationId : null,
            ])),
            'exception_class' => $exception::class,
            'job' => $jobClass,
            'source_location' => $sourceLocation,
            'run_id' => $runId,
            'automation_id' => $automationId,
            'context' => $context,
        ];
    }
}
