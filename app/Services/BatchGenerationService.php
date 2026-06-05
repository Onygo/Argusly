<?php

namespace App\Services;

use App\Jobs\GenerateBatchItemBriefJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentBatch;
use App\Models\ContentBatchItem;
use App\Models\CreditAction;
use App\Models\Draft;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BriefProcessing\BriefToDraftService;
use App\Services\Content\ContentDeduplicationService;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\LlmManager;
use App\Support\ContentPersistencePayloadNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class BatchGenerationService
{
    public function __construct(
        private readonly BriefToDraftService $briefToDraftService,
        private readonly ContentDeduplicationService $contentDeduplicationService,
        private readonly LlmManager $llmManager,
    ) {
    }

    /**
     * @param array<int, array{subkeyword:string, angle?:string|null, intent?:string|null}> $subkeywords
     * @param array<string, mixed> $settings
     */
    public function createBatch(
        Workspace $workspace,
        User $user,
        ?ClientSite $clientSite,
        string $mainKeyword,
        array $subkeywords,
        array $settings = []
    ): ContentBatch {
        if (count($subkeywords) < 1 || count($subkeywords) > 10) {
            throw new RuntimeException('Batch must contain between 1 and 10 subkeywords.');
        }

        return DB::transaction(function () use ($workspace, $user, $clientSite, $mainKeyword, $subkeywords, $settings): ContentBatch {
            $batch = ContentBatch::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->id,
                'client_site_id' => $clientSite?->id,
                'user_id' => $user->id,
                'main_keyword' => trim($mainKeyword),
                'settings_json' => $settings,
                'status' => 'draft',
                'items_total' => count($subkeywords),
                'items_done' => 0,
                'credits_estimated' => $this->estimateCreditsForCount(count($subkeywords)),
                'credits_used' => 0,
            ]);

            foreach ($subkeywords as $index => $item) {
                ContentBatchItem::query()->create([
                    'id' => (string) Str::uuid(),
                    'batch_id' => $batch->id,
                    'subkeyword' => trim((string) ($item['subkeyword'] ?? '')),
                    'angle' => $this->nullableTrim($item['angle'] ?? null),
                    'intent' => $this->nullableTrim($item['intent'] ?? null),
                    'status' => 'pending',
                    'sort_order' => $index + 1,
                ]);
            }

            return $batch->fresh(['items']);
        });
    }

    public function estimateCredits(ContentBatch $batch): int
    {
        return $this->estimateCreditsForCount((int) $batch->items_total);
    }

    public function start(ContentBatch $batch): void
    {
        $batch->loadMissing('items');

        if (in_array((string) $batch->status, ['canceled', 'completed'], true)) {
            throw new RuntimeException('This batch cannot be started.');
        }

        $items = $batch->items->filter(fn (ContentBatchItem $item) => in_array((string) $item->status, ['pending', 'failed'], true));
        if ($items->isEmpty()) {
            throw new RuntimeException('No pending items available to start.');
        }

        $batch->update(['status' => 'running']);

        foreach ($items as $item) {
            GenerateBatchItemBriefJob::dispatch((string) $item->id)->onQueue('generation');
        }
    }

    public function ensureBriefAndDraftForItem(ContentBatchItem $item): Draft
    {
        $item->loadMissing('batch.workspace', 'batch.clientSite', 'brief', 'draft');
        $batch = $item->batch;

        if (! $batch) {
            throw new RuntimeException('Batch item has no batch.');
        }

        if (! $batch->client_site_id) {
            throw new RuntimeException('Batch has no linked site. Select a site before starting.');
        }

        if ($item->draft) {
            return $item->draft;
        }

        $site = $batch->clientSite;
        if (! $site) {
            throw new RuntimeException('Linked site could not be resolved.');
        }

        $brief = $item->brief;

        if (! $brief) {
            $content = $this->createContentForItem($batch, $item, $site);
            $brief = $this->createBriefForItem($batch, $item, $site, $content);
        }

        $draft = $this->briefToDraftService->claimAndCreateDraft((string) $brief->id);
        if (! $draft) {
            $draft = Draft::query()->where('brief_id', $brief->id)->latest('created_at')->first();
        }

        if (! $draft) {
            throw new RuntimeException('Draft creation failed for batch item.');
        }

        $draft = $this->applyBatchContextToDraft($batch, $item, $draft);

        $item->update([
            'brief_id' => $brief->id,
            'draft_id' => $draft->id,
            'error_message' => null,
        ]);

        return $draft;
    }

    public function resolveCreditCostForDraft(Draft $draft): int
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

    public function markItemFailed(ContentBatchItem $item, string $message): void
    {
        $item->update([
            'status' => 'failed',
            'error_message' => mb_substr($message, 0, 5000),
        ]);

        $this->syncBatchProgress($item->batch()->firstOrFail());
    }

    public function syncBatchProgress(ContentBatch $batch): void
    {
        $counts = ContentBatchItem::query()
            ->where('batch_id', $batch->id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done_count")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count")
            ->selectRaw("SUM(CASE WHEN status IN ('pending','briefing','drafting') THEN 1 ELSE 0 END) as open_count")
            ->first();

        $total = (int) ($counts->total ?? 0);
        $done = (int) ($counts->done_count ?? 0);
        $failed = (int) ($counts->failed_count ?? 0);
        $open = (int) ($counts->open_count ?? 0);

        $status = (string) $batch->status;
        if ($status !== 'canceled') {
            if ($open > 0) {
                $status = 'running';
            } elseif ($done === $total && $total > 0) {
                $status = 'completed';
            } elseif ($done > 0 && $failed > 0 && ($done + $failed) === $total) {
                $status = 'partially_completed';
            } elseif ($done === 0 && $failed === $total && $total > 0) {
                $status = 'failed';
            }
        }

        $batch->update([
            'items_total' => $total,
            'items_done' => $done,
            'status' => $status,
        ]);
    }

    public function incrementBatchCreditsUsed(ContentBatch $batch, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $batch->increment('credits_used', $amount);
    }

    /**
     * @return array<int, array{subkeyword:string, angle:string|null, intent:string|null}>
     */
    public function parseSubkeywordLines(string $raw): array
    {
        $rows = collect(preg_split('/\R+/', $raw) ?: [])
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->values()
            ->all();

        $parsed = [];

        foreach ($rows as $line) {
            $parts = array_map('trim', explode('|', $line));
            $subkeyword = trim((string) ($parts[0] ?? ''));
            if ($subkeyword === '') {
                continue;
            }

            $parsed[] = [
                'subkeyword' => $subkeyword,
                'angle' => $this->nullableTrim($parts[1] ?? null),
                'intent' => $this->nullableTrim($parts[2] ?? null),
            ];
        }

        return collect($parsed)
            ->unique(fn (array $row) => mb_strtolower($row['subkeyword']))
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $existingSubkeywords
     * @return array<int, array{subkeyword:string, angle:string|null, intent:string|null, differentiator:string|null}>
     */
    public function suggestSubkeywords(string $mainKeyword, ?string $language = 'nl', array $existingSubkeywords = []): array
    {
        $mainKeyword = trim($mainKeyword);
        $language = trim((string) $language) !== '' ? trim((string) $language) : 'nl';
        $existing = collect($existingSubkeywords)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();

        try {
            $items = $this->suggestViaOpenAi($mainKeyword, $language, $existing);
            if (! empty($items)) {
                return $items;
            }
        } catch (\Throwable) {
            // Fallback below.
        }

        return $this->fallbackSuggestions($mainKeyword, $existing);
    }

    private function estimateCreditsForCount(int $count): int
    {
        $actionCost = (int) (
            CreditAction::query()
                ->where('key', 'content.article')
                ->where('is_active', true)
                ->value('credits_cost')
            ?? 0
        );

        if ($actionCost <= 0) {
            $actionCost = (int) (
                CreditAction::query()
                    ->where('category', 'content')
                    ->where('is_active', true)
                    ->orderBy('credits_cost')
                    ->value('credits_cost')
                ?? 0
            );
        }

        return max(0, $actionCost) * max(0, $count);
    }

    /**
     * @param array<int, string> $existing
     * @return array<int, array{subkeyword:string, angle:string|null, intent:string|null, differentiator:string|null}>
     */
    private function suggestViaOpenAi(string $mainKeyword, string $language, array $existing): array
    {
        $prompt = implode("\n", [
            'Generate exactly 10 unique SEO subkeywords for a B2B content cluster.',
            'Main keyword: ' . $mainKeyword,
            'Language: ' . $language,
            'Existing subkeywords to avoid: ' . (! empty($existing) ? implode(', ', $existing) : 'none'),
            'For each suggestion include:',
            '- subkeyword',
            '- angle (short)',
            '- intent (one of: informational, commercial, transactional, navigational, technical)',
            '- differentiator (short, non-overlapping perspective)',
            'Return strict JSON only in this shape:',
            '{"items":[{"subkeyword":"...","angle":"...","intent":"...","differentiator":"..."}]}',
            'No markdown. No explanation. No extra keys.',
        ]);

        $response = $this->llmManager->generateJson(
            new LlmRequest(
                messages: [
                    new LlmMessage('system', 'You are a precise SEO assistant. Return JSON only.'),
                    new LlmMessage('user', $prompt),
                ],
                responseFormat: 'json',
                metadata: [
                    'feature' => 'seo_optimization',
                    'modality' => 'text',
                    'trigger' => 'batch_subkeyword_suggestion',
                ],
            ),
            '{"items":[{"subkeyword":"...","angle":"...","intent":"...","differentiator":"..."}]}',
        );

        $decoded = $response->json;
        if (! is_array($decoded)) {
            return [];
        }

        $items = data_get($decoded, 'items', []);
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(function ($row): array {
                return [
                    'subkeyword' => trim((string) data_get($row, 'subkeyword', '')),
                    'angle' => $this->nullableTrim(data_get($row, 'angle')),
                    'intent' => $this->normalizeIntent(data_get($row, 'intent')),
                    'differentiator' => $this->nullableTrim(data_get($row, 'differentiator')),
                ];
            })
            ->filter(fn (array $row) => $row['subkeyword'] !== '')
            ->unique(fn (array $row) => mb_strtolower($row['subkeyword']))
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $existing
     * @return array<int, array{subkeyword:string, angle:string|null, intent:string|null, differentiator:string|null}>
     */
    private function fallbackSuggestions(string $mainKeyword, array $existing): array
    {
        $templates = [
            ['Checklist', 'Practical execution guide', 'commercial'],
            ['Best practices', 'Framework and standards', 'informational'],
            ['Common mistakes', 'Risk prevention focus', 'informational'],
            ['Implementation plan', 'Step-by-step rollout', 'technical'],
            ['Template', 'Reusable model for teams', 'technical'],
            ['KPIs', 'Measurement and reporting', 'commercial'],
            ['Cost breakdown', 'Budget and ROI perspective', 'commercial'],
            ['Governance model', 'Decision rights and process', 'informational'],
            ['Tool comparison', 'Selection criteria and tradeoffs', 'transactional'],
            ['Migration roadmap', 'Phased transition planning', 'technical'],
            ['Playbook', 'Operational runbook style', 'informational'],
            ['Use cases', 'Scenario-driven examples', 'navigational'],
        ];

        $existingSet = collect($existing)->map(fn ($value) => mb_strtolower(trim((string) $value)))->all();

        $items = [];
        foreach ($templates as $template) {
            [$suffix, $angle, $intent] = $template;
            $candidate = trim($mainKeyword . ' ' . $suffix);
            if ($candidate === '' || in_array(mb_strtolower($candidate), $existingSet, true)) {
                continue;
            }
            $items[] = [
                'subkeyword' => $candidate,
                'angle' => $angle,
                'intent' => $intent,
                'differentiator' => 'Unique focus on ' . strtolower($suffix),
            ];
            if (count($items) >= 10) {
                break;
            }
        }

        return $items;
    }

    private function createContentForItem(ContentBatch $batch, ContentBatchItem $item, ClientSite $site): Content
    {
        $settings = is_array($batch->settings_json) ? $batch->settings_json : [];

        $title = $this->buildItemTitle($batch, $item);

        $payload = [
            'id' => (string) Str::uuid(),
            'workspace_id' => $batch->workspace_id,
            'client_site_id' => $site->id,
            'title' => $title,
            'primary_keyword' => $item->subkeyword,
            'type' => 'article',
            'status' => 'brief',
            'source' => 'api',
            'external_key' => 'batch-' . $batch->id . '-' . $item->sort_order,
            'delivery_status' => 'pending',
            'generation_mode' => 'balanced',
            'brand_voice_id' => $settings['brand_voice_id'] ?? null,
            'buyer_persona_id' => $settings['buyer_persona_id'] ?? null,
            'team_member_id' => $settings['team_member_id'] ?? null,
            'preferred_length' => $settings['preferred_length'] ?? 'medium',
            'created_by' => $batch->user_id,
            'updated_by' => $batch->user_id,
        ];

        return $this->contentDeduplicationService->createOrReuse($payload, [
            'workspace_id' => (string) $batch->workspace_id,
            'client_site_id' => (string) $site->id,
            'batch_id' => (string) $batch->id,
            'batch_item_id' => (string) $item->id,
            'language' => (string) ($settings['language'] ?? 'nl'),
            'type' => 'article',
            'external_key' => (string) $payload['external_key'],
        ]);
    }

    private function createBriefForItem(ContentBatch $batch, ContentBatchItem $item, ClientSite $site, Content $content): Brief
    {
        $settings = is_array($batch->settings_json) ? $batch->settings_json : [];
        $siblings = ContentBatchItem::query()
            ->where('batch_id', $batch->id)
            ->where('id', '!=', $item->id)
            ->orderBy('sort_order')
            ->pluck('subkeyword')
            ->filter()
            ->values()
            ->all();

        $notes = trim(implode("\n\n", array_filter([
            (string) ($settings['notes'] ?? ''),
            $item->angle ? 'Angle: ' . $item->angle : null,
            'Batch cluster context: this article is part of a cluster around "' . $batch->main_keyword . '".',
            'Avoid overlap with sibling topics: ' . implode(', ', $siblings),
            'Use a complementary perspective and include internal linking opportunities to sibling articles where natural.',
        ])));

        return Brief::query()->create(ContentPersistencePayloadNormalizer::normalizeBrief([
            'id' => (string) Str::uuid(),
            'client_site_id' => $site->id,
            'content_id' => $content->id,
            'status' => 'queued',
            'progress' => 0,
            'title' => $this->buildItemTitle($batch, $item),
            'language' => (string) ($settings['language'] ?? 'nl'),
            'intent' => $item->intent,
            'primary_keyword' => $item->subkeyword,
            'audience' => $this->nullableTrim($settings['audience'] ?? null),
            'output_type' => (string) ($settings['output_type'] ?? 'kb_article'),
            'notes' => $notes !== '' ? $notes : null,
            'client_refs' => [
                'client_type' => 'batch',
                'site_url' => (string) ($site->base_url ?: $site->site_url),
                'batch' => [
                    'batch_id' => (string) $batch->id,
                    'item_id' => (string) $item->id,
                    'main_keyword' => (string) $batch->main_keyword,
                    'subkeyword' => (string) $item->subkeyword,
                    'angle' => $item->angle,
                    'intent' => $item->intent,
                    'siblings' => $siblings,
                ],
                'brand_voice_id' => $settings['brand_voice_id'] ?? null,
                'buyer_persona_id' => $settings['buyer_persona_id'] ?? null,
                'team_member_id' => $settings['team_member_id'] ?? null,
                'preferred_length' => $settings['preferred_length'] ?? 'medium',
            ],
        ]));
    }

    private function applyBatchContextToDraft(ContentBatch $batch, ContentBatchItem $item, Draft $draft): Draft
    {
        $settings = is_array($batch->settings_json) ? $batch->settings_json : [];
        $siblings = ContentBatchItem::query()
            ->where('batch_id', $batch->id)
            ->where('id', '!=', $item->id)
            ->orderBy('sort_order')
            ->pluck('subkeyword')
            ->filter()
            ->values()
            ->all();

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['language'] = (string) ($settings['language'] ?? ($meta['language'] ?? 'nl'));
        $meta['tone'] = (string) ($settings['tone'] ?? ($meta['tone'] ?? ''));
        $meta['audience'] = (string) ($settings['audience'] ?? ($meta['audience'] ?? ''));
        $meta['preferred_length'] = (string) ($settings['preferred_length'] ?? ($meta['preferred_length'] ?? 'medium'));
        $meta['buyer_persona_id'] = $settings['buyer_persona_id'] ?? ($meta['buyer_persona_id'] ?? null);
        $meta['primary_keyword'] = $item->subkeyword;
        $meta['secondary_keywords'] = collect(array_merge([(string) $batch->main_keyword], $siblings))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $notes = trim(implode("\n", array_filter([
            (string) ($meta['notes'] ?? ''),
            'Batch main keyword: ' . $batch->main_keyword,
            'Current subkeyword: ' . $item->subkeyword,
            $item->angle ? 'Angle: ' . $item->angle : null,
            'Instruction: make this article complementary to sibling batch items, avoid duplication, use a distinct angle.',
            ! empty($siblings) ? 'Sibling topics: ' . implode(', ', $siblings) : null,
        ])));

        if ($notes !== '') {
            $meta['notes'] = $notes;
        }

        $meta['batch_context'] = [
            'batch_id' => (string) $batch->id,
            'item_id' => (string) $item->id,
            'main_keyword' => (string) $batch->main_keyword,
            'subkeyword' => (string) $item->subkeyword,
            'angle' => $item->angle,
            'intent' => $item->intent,
            'siblings' => $siblings,
        ];

        $draft->meta = $meta;
        $draft->save();

        return $draft;
    }

    private function buildItemTitle(ContentBatch $batch, ContentBatchItem $item): string
    {
        if ($item->angle) {
            return $item->subkeyword . ' - ' . $item->angle;
        }

        return $item->subkeyword . ' | ' . $batch->main_keyword;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeIntent(mixed $value): ?string
    {
        $intent = strtolower(trim((string) $value));
        $allowed = ['informational', 'commercial', 'transactional', 'navigational', 'technical'];

        if ($intent === '') {
            return null;
        }

        return in_array($intent, $allowed, true) ? $intent : null;
    }
}
