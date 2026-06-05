<?php

namespace App\Jobs;

use App\Models\Content;
use App\Models\StructuredAnswerBlock;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\Data\LlmResponse;
use App\Services\Llm\LlmManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GenerateStructuredAnswersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $contentId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(LlmManager $llmManager): void
    {
        $content = Content::query()
            ->with(['workspace', 'currentRevision', 'currentVersion', 'answerBlocks'])
            ->find($this->contentId);

        if (! $content) {
            Log::warning('structured_answers.missing_content', [
                'content_id' => $this->contentId,
            ]);

            return;
        }

        $this->markRunning($content);

        $body = trim((string) (
            $content->currentRevision?->content_html
            ?: $content->currentVersion?->body
            ?: ''
        ));

        $meta = $this->baseGenerationMeta($content);

        if ($body === '') {
            $meta = array_merge($meta, [
                'failure_reason' => 'empty_body',
                'failure_reason_message' => $this->failureMessageFor('empty_body'),
                'prompt_length' => 0,
                'raw_response_length' => 0,
                'parsed_block_count' => 0,
                'accepted_block_count' => 0,
                'rejected_block_count' => 0,
                'rejection_reasons' => [],
                'saved_block_ids' => [],
            ]);

            $this->markCompletedWithWarning($content, 0, $meta['failure_reason_message'], $meta);
            $this->logAttempt('structured_answers.completed_with_warning', $meta);

            return;
        }

        $prompt = $this->buildPrompt($content, strip_tags($body));
        $meta['prompt_length'] = mb_strlen($prompt);
        $this->logAttempt('structured_answers.started', $meta);

        $response = $llmManager->generateJson(
            new LlmRequest(
                messages: [
                    new LlmMessage('system', 'You extract answer-first Q&A blocks from articles. Return strict JSON only.'),
                    new LlmMessage('user', $prompt),
                ],
                temperature: 0.2,
                maxTokens: 2200,
                responseFormat: 'json',
                metadata: [
                    'feature' => 'seo_optimization',
                    'modality' => 'text',
                    'workspaceId' => (string) $content->workspace_id,
                    'siteId' => (string) $content->client_site_id,
                    'userId' => (int) ($content->updated_by ?: $content->created_by ?: 0),
                ],
            ),
            'JSON array or object of structured answer blocks'
        );

        [$items, $meta] = $this->parseResponse($content, $response, $meta);

        if ($items->isEmpty()) {
            $fallbackItems = $this->buildFallbackBlocks($content, $body);
            $meta['fallback_used'] = true;
            $meta['fallback_block_count'] = $fallbackItems->count();

            if ($fallbackItems->isEmpty()) {
                $warning = (string) ($meta['failure_reason_message'] ?? 'The AI returned no usable answer blocks for this draft.');
                $this->markCompletedWithWarning($content, 0, $warning, $meta);
                $this->logAttempt('structured_answers.completed_with_warning', $meta);

                return;
            }

            $savedIds = $this->persistBlocks($content, $fallbackItems);
            $meta['saved_block_ids'] = $savedIds;
            $meta['persisted_blocks_count'] = count($savedIds);

            $warning = trim(($meta['failure_reason_message'] ?? 'The AI returned no usable answer blocks for this draft.')
                .' Fallback answer blocks were created from the draft content.');

            $this->markCompletedWithWarning($content, count($savedIds), $warning, $meta);
            $this->logAttempt('structured_answers.completed_with_warning', $meta);

            return;
        }

        $savedIds = $this->persistBlocks($content, $items);
        $meta['saved_block_ids'] = $savedIds;
        $meta['persisted_blocks_count'] = count($savedIds);

        if ($savedIds === []) {
            throw new RuntimeException('Structured answer generation completed without persisting any blocks.');
        }

        $linkedCount = StructuredAnswerBlock::query()
            ->where('content_id', (string) $content->id)
            ->count();

        if ($linkedCount <= 0) {
            $meta['failure_reason'] = 'blocks_not_linked';
            $meta['failure_reason_message'] = $this->failureMessageFor('blocks_not_linked');

            $this->markCompletedWithWarning($content, count($savedIds), $meta['failure_reason_message'], $meta);
            $this->logAttempt('structured_answers.completed_with_warning', $meta);

            return;
        }

        $this->markCompleted($content, count($savedIds), $meta);
        $this->logAttempt('structured_answers.completed', $meta);
    }

    public function failed(Throwable $exception): void
    {
        $content = Content::query()->find($this->contentId);

        if (! $content) {
            return;
        }

        $meta = array_merge($this->baseGenerationMeta($content), [
            'failure_reason' => 'job_failed',
            'failure_reason_message' => mb_substr($exception->getMessage(), 0, 5000),
            'saved_block_ids' => (array) data_get($content->answer_block_generation_meta, 'saved_block_ids', []),
            'persisted_blocks_count' => (int) ($content->answer_block_generation_persisted_count ?? 0),
        ]);

        $this->markFailed($content, $meta['failure_reason_message'], $meta);
        $this->logAttempt('structured_answers.failed', $meta);
    }

    private function buildPrompt(Content $content, string $plainBody): string
    {
        return <<<PROMPT
Given the article below, extract 5-10 key user questions and provide clear, concise answers.

Rules:
- Each answer max 80 words
- First sentence = direct answer
- Use factual tone
- Include entity references where relevant
- Output strict JSON only
- You may return either an array or an object with answer_blocks, blocks, faqs, or questions
- Use keys question, answer, entities, platforms when possible
- Do not duplicate questions

Title: {$content->title}
Primary keyword: {$content->primary_keyword}
Language: {$content->localeCode()}

Article:
{$plainBody}
PROMPT;
    }

    private function markRunning(Content $content): void
    {
        $content->forceFill([
            'answer_block_generation_status' => Content::ANSWER_BLOCK_STATUS_RUNNING,
            'answer_block_generation_draft_revision_id' => $content->current_revision_id ?: null,
            'answer_block_generation_started_at' => $content->answer_block_generation_started_at ?: now(),
            'answer_block_generation_completed_at' => null,
            'answer_block_generation_failed_at' => null,
            'answer_block_generation_last_error' => null,
            'answer_block_generation_last_warning' => null,
            'answer_block_generation_meta' => null,
        ])->saveQuietly();
    }

    private function markCompleted(Content $content, int $persistedCount, array $meta): void
    {
        $content->forceFill([
            'answer_block_generation_status' => Content::ANSWER_BLOCK_STATUS_COMPLETED,
            'answer_block_generation_persisted_count' => $persistedCount,
            'answer_block_generation_draft_revision_id' => $content->current_revision_id ?: null,
            'answer_block_generation_completed_at' => now(),
            'answer_block_generation_failed_at' => null,
            'answer_block_generation_last_error' => null,
            'answer_block_generation_last_warning' => null,
            'answer_block_generation_meta' => $meta,
        ])->saveQuietly();
    }

    private function markCompletedWithWarning(Content $content, int $persistedCount, string $warning, array $meta): void
    {
        $content->forceFill([
            'answer_block_generation_status' => Content::ANSWER_BLOCK_STATUS_COMPLETED_WITH_WARNING,
            'answer_block_generation_persisted_count' => $persistedCount,
            'answer_block_generation_draft_revision_id' => $content->current_revision_id ?: null,
            'answer_block_generation_completed_at' => now(),
            'answer_block_generation_failed_at' => null,
            'answer_block_generation_last_error' => null,
            'answer_block_generation_last_warning' => $warning,
            'answer_block_generation_meta' => $meta,
        ])->saveQuietly();
    }

    private function markFailed(Content $content, string $message, array $meta): void
    {
        $content->forceFill([
            'answer_block_generation_status' => Content::ANSWER_BLOCK_STATUS_FAILED,
            'answer_block_generation_completed_at' => null,
            'answer_block_generation_failed_at' => now(),
            'answer_block_generation_last_error' => $message,
            'answer_block_generation_meta' => $meta,
        ])->saveQuietly();
    }

    /**
     * @return array{0:Collection<int,array<string,mixed>>,1:array<string,mixed>}
     */
    private function parseResponse(Content $content, LlmResponse $response, array $meta): array
    {
        $meta = array_merge($meta, [
            'provider' => $response->providerName,
            'model' => $response->modelUsed,
            'request_id' => $response->requestId,
            'raw_response_length' => mb_strlen(trim($response->text)),
            'fallback_used' => false,
            'fallback_block_count' => 0,
            'saved_block_ids' => [],
        ]);

        $decoded = $this->decodeResponsePayload($response);
        if ($decoded['status'] !== 'ok') {
            $meta['failure_reason'] = $decoded['status'];
            $meta['failure_reason_message'] = $this->failureMessageFor($decoded['status']);
            $meta['raw_block_count'] = 0;
            $meta['parsed_block_count'] = 0;
            $meta['accepted_block_count'] = 0;
            $meta['rejected_block_count'] = 0;
            $meta['rejection_reasons'] = [];

            $this->logAttempt('structured_answers.response', $meta);

            return [collect(), $meta];
        }

        $rows = $this->extractCandidateRows($decoded['payload']);
        $accepted = collect();
        $rejections = [];
        $seenQuestions = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $rejections['invalid_block_type'] = ($rejections['invalid_block_type'] ?? 0) + 1;

                continue;
            }

            $block = $this->normalizeBlock($row);
            $errors = $this->validateBlock($block);
            $questionKey = mb_strtolower($block['question']);

            if ($block['question'] !== '' && in_array($questionKey, $seenQuestions, true)) {
                $errors[] = 'duplicate_question';
            }

            if ($errors !== []) {
                foreach ($errors as $reason) {
                    $rejections[$reason] = ($rejections[$reason] ?? 0) + 1;
                }

                continue;
            }

            $seenQuestions[] = $questionKey;
            $accepted->push($block);
        }

        $accepted = $accepted->take(10)->values();

        $meta['raw_block_count'] = count($rows);
        $meta['parsed_block_count'] = count($rows);
        $meta['accepted_block_count'] = $accepted->count();
        $meta['rejected_block_count'] = max(0, $meta['parsed_block_count'] - $meta['accepted_block_count']);
        $meta['rejection_reasons'] = $rejections;

        if ($accepted->isEmpty()) {
            $meta['failure_reason'] = $meta['parsed_block_count'] === 0 ? 'ai_response_empty' : 'all_blocks_rejected';
            $meta['failure_reason_message'] = $this->failureMessageFor((string) $meta['failure_reason']);
        }

        $this->logAttempt('structured_answers.response', $meta);

        return [$accepted, $meta];
    }

    /**
     * @return array{status:string,payload:mixed}
     */
    private function decodeResponsePayload(LlmResponse $response): array
    {
        if (is_array($response->json)) {
            return [
                'status' => 'ok',
                'payload' => $response->json,
            ];
        }

        $text = trim($response->text);
        if ($text === '') {
            return [
                'status' => 'ai_response_empty',
                'payload' => null,
            ];
        }

        foreach ([$text, $this->extractMarkdownFenceBody($text), $this->extractJsonFragment($text)] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $decoded = $this->decodeJsonString($candidate);
            if ($decoded !== null) {
                return [
                    'status' => 'ok',
                    'payload' => $decoded,
                ];
            }
        }

        return [
            'status' => 'could_not_parse_json',
            'payload' => null,
        ];
    }

    private function decodeJsonString(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }

    private function extractMarkdownFenceBody(string $text): ?string
    {
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $matches) !== 1) {
            return null;
        }

        return trim((string) ($matches[1] ?? ''));
    }

    private function extractJsonFragment(string $text): ?string
    {
        $startArray = strpos($text, '[');
        $startObject = strpos($text, '{');
        $starts = array_values(array_filter([$startArray, $startObject], fn (mixed $value): bool => $value !== false));

        if ($starts === []) {
            return null;
        }

        $start = min($starts);
        $end = max(
            (int) (strrpos($text, ']') === false ? -1 : strrpos($text, ']')),
            (int) (strrpos($text, '}') === false ? -1 : strrpos($text, '}'))
        );

        if ($end < $start) {
            return null;
        }

        return trim(substr($text, $start, $end - $start + 1));
    }

    /**
     * @return array<int,mixed>
     */
    private function extractCandidateRows(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if ($this->looksLikeBlock($payload)) {
            return [$payload];
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        foreach (['answer_blocks', 'blocks', 'faqs', 'questions'] as $key) {
            $candidate = Arr::get($payload, $key);

            if (! is_array($candidate)) {
                continue;
            }

            return array_is_list($candidate) ? $candidate : [$candidate];
        }

        return [];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{question:string,answer:string,entities:array<int,string>,platforms:array<int,string>}
     */
    private function normalizeBlock(array $row): array
    {
        return [
            'question' => $this->trimToLength((string) ($row['question'] ?? $row['title'] ?? $row['heading'] ?? ''), 255),
            'answer' => $this->trimToLength((string) ($row['answer'] ?? $row['body'] ?? $row['content'] ?? ''), 4000),
            'entities' => $this->normalizeList($row['entities'] ?? []),
            'platforms' => $this->normalizePlatforms($row['platforms'] ?? $row['sources'] ?? $row['targets'] ?? []),
        ];
    }

    /**
     * @param array{question:string,answer:string,entities:array<int,string>,platforms:array<int,string>} $block
     * @return array<int,string>
     */
    private function validateBlock(array $block): array
    {
        $errors = [];

        if ($block['question'] === '') {
            $errors[] = 'missing_question';
        }

        if ($block['answer'] === '') {
            $errors[] = 'missing_answer';
        }

        if ($block['answer'] !== '' && mb_strlen($block['answer']) < 3) {
            $errors[] = 'answer_too_short';
        }

        return $errors;
    }

    /**
     * @return array<int,string>
     */
    private function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,;\n]+/', $value) ?: [];
        }

        return collect((array) $value)
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function normalizePlatforms(mixed $value): array
    {
        $platforms = $this->normalizeList($value);

        return $platforms === []
            ? ['Google', 'ChatGPT', 'Perplexity']
            : $platforms;
    }

    private function trimToLength(string $value, int $maxLength): string
    {
        return trim((string) Str::of($value)->squish()->substr(0, $maxLength));
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function buildFallbackBlocks(Content $content, string $body): Collection
    {
        $title = trim((string) ($content->seo_h1 ?: $content->title));
        $summary = trim((string) ($content->seo_meta_description ?: $content->public_blog_excerpt ?: ''));
        $paragraphs = collect(preg_split('/\n{2,}/', trim((string) preg_replace('/\s+/u', ' ', strip_tags($body)))) ?: [])
            ->map(fn (string $paragraph): string => trim($paragraph))
            ->filter()
            ->unique()
            ->values();

        $snippets = collect([$summary])
            ->merge($paragraphs->take(3))
            ->filter()
            ->values();

        $fallbacks = collect();

        if ($title !== '' && isset($snippets[0])) {
            $fallbacks->push([
                'question' => $this->trimToLength('What is '.$title.' about?', 255),
                'answer' => $this->trimToLength((string) $snippets[0], 4000),
                'entities' => $this->normalizeList([$content->primary_keyword]),
                'platforms' => ['Google', 'ChatGPT', 'Perplexity'],
            ]);
        }

        if ($title !== '' && isset($snippets[1])) {
            $fallbacks->push([
                'question' => $this->trimToLength('What should readers know about '.$title.'?', 255),
                'answer' => $this->trimToLength((string) $snippets[1], 4000),
                'entities' => $this->normalizeList([$content->primary_keyword]),
                'platforms' => ['Google', 'ChatGPT', 'Perplexity'],
            ]);
        }

        if ($title !== '' && isset($snippets[2])) {
            $fallbacks->push([
                'question' => $this->trimToLength('What are the key points of '.$title.'?', 255),
                'answer' => $this->trimToLength((string) $snippets[2], 4000),
                'entities' => $this->normalizeList([$content->primary_keyword]),
                'platforms' => ['Google', 'ChatGPT', 'Perplexity'],
            ]);
        }

        return $fallbacks
            ->filter(fn (array $item): bool => $this->validateBlock($item) === [])
            ->unique(fn (array $item): string => mb_strtolower($item['question']))
            ->take(3)
            ->values();
    }

    /**
     * @param Collection<int,array<string,mixed>> $items
     * @return array<int,string>
     */
    private function persistBlocks(Content $content, Collection $items): array
    {
        $savedIds = [];

        DB::transaction(function () use ($content, $items, &$savedIds): void {
            StructuredAnswerBlock::query()
                ->where('content_id', (string) $content->id)
                ->delete();

            foreach ($items->values() as $index => $item) {
                $block = StructuredAnswerBlock::query()->create([
                    'content_id' => (string) $content->id,
                    'question' => $item['question'],
                    'answer' => $item['answer'],
                    'entities' => $item['entities'],
                    'platforms' => $item['platforms'],
                    'order' => $index,
                ]);

                $savedIds[] = (string) $block->id;
            }

            if (trim((string) ($content->answer_block_render_mode ?? '')) === '') {
                $content->forceFill([
                    'answer_block_render_mode' => Content::ANSWER_BLOCK_RENDER_MODE_AI_OPTIMIZED,
                ])->saveQuietly();
            }
        });

        return $savedIds;
    }

    /**
     * @return array<string,mixed>
     */
    private function baseGenerationMeta(Content $content): array
    {
        return [
            'content_id' => (string) $content->id,
            'draft_revision_id' => (string) ($content->current_revision_id ?? ''),
            'locale' => $content->localeCode(),
            'user_id' => (int) ($content->updated_by ?: $content->created_by ?: 0),
            'organization_id' => $content->workspace?->organization_id,
            'source_locale' => (string) ($content->translation_source_locale ?: $content->localeCode()),
            'target_table' => 'structured_answer_blocks',
            'target_relation' => 'answerBlocks',
        ];
    }

    private function logAttempt(string $event, array $meta): void
    {
        Log::info($event, $meta);
    }

    private function looksLikeBlock(array $payload): bool
    {
        return array_key_exists('question', $payload)
            || array_key_exists('title', $payload)
            || array_key_exists('heading', $payload)
            || array_key_exists('answer', $payload)
            || array_key_exists('body', $payload)
            || array_key_exists('content', $payload);
    }

    private function failureMessageFor(string $reason): string
    {
        return match ($reason) {
            'ai_response_empty' => 'AI response was empty.',
            'could_not_parse_json' => 'Could not parse JSON.',
            'all_blocks_rejected' => 'All blocks rejected by validation.',
            'blocks_not_linked' => 'Blocks saved but not linked to this draft.',
            'empty_body' => 'No draft body is available for structured answer generation.',
            default => 'The AI returned no usable answer blocks for this draft.',
        };
    }
}
