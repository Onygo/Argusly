<?php

namespace App\Services\Content;

use App\Models\Content;
use App\Models\StructuredAnswerBlock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnswerBlockSchemaService
{
    public function __construct(
        private readonly AnswerBlockInjectorService $injector
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function forContent(Content $content, ?string $mode = null, ?int $maxVisible = null): ?array
    {
        try {
            $mode ??= $this->injector->resolveRenderMode($content);
            if ($mode === Content::ANSWER_BLOCK_RENDER_MODE_DISABLED) {
                return null;
            }

            $blocks = $this->injector->visibleBlocks($content, $mode, $maxVisible);
        } catch (Throwable $exception) {
            Log::warning('content.answer_blocks.schema_failed', [
                'content_id' => (string) ($content->id ?? ''),
                'slug' => (string) ($content->publish_url_key ?? $content->canonical_url_key ?? ''),
                'locale' => method_exists($content, 'localeCode') ? $content->localeCode() : (string) ($content->language ?? ''),
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        $mainEntity = $blocks
            ->map(function (StructuredAnswerBlock $block): ?array {
                try {
                    $question = $this->cleanText($block->question);
                    $answer = $this->cleanText($block->answer);
                } catch (Throwable $exception) {
                    Log::warning('content.answer_blocks.schema_block_skipped', [
                        'block_id' => (string) ($block->id ?? ''),
                        'content_id' => (string) ($block->content_id ?? ''),
                        'message' => $exception->getMessage(),
                    ]);

                    return null;
                }

                if ($question === '' || $answer === '') {
                    return null;
                }

                return [
                    '@type' => 'Question',
                    'name' => $question,
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $answer,
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();

        if ($mainEntity === []) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $mainEntity,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function exportableBlocks(Content $content, ?string $mode = null, ?int $maxVisible = null): array
    {
        try {
            $blocks = $this->injector->visibleBlocks($content, $mode, $maxVisible);
        } catch (Throwable $exception) {
            Log::warning('content.answer_blocks.export_failed', [
                'content_id' => (string) ($content->id ?? ''),
                'slug' => (string) ($content->publish_url_key ?? $content->canonical_url_key ?? ''),
                'locale' => method_exists($content, 'localeCode') ? $content->localeCode() : (string) ($content->language ?? ''),
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        return $blocks
            ->map(function (StructuredAnswerBlock $block): ?array {
                try {
                    return [
                        'id' => (string) $block->id,
                        'question' => $this->cleanText($block->question),
                        'answer' => $this->cleanText($block->answer),
                        'entities' => collect((array) $block->entities)->map(fn ($item): string => $this->cleanText((string) $item))->filter()->values()->all(),
                        'platforms' => collect((array) $block->platforms)->map(fn ($item): string => $this->cleanText((string) $item))->filter()->values()->all(),
                        'order' => (int) $block->order,
                    ];
                } catch (Throwable $exception) {
                    Log::warning('content.answer_blocks.export_block_skipped', [
                        'block_id' => (string) ($block->id ?? ''),
                        'content_id' => (string) ($block->content_id ?? ''),
                        'message' => $exception->getMessage(),
                    ]);

                    return null;
                }
            })
            ->filter(fn ($block): bool => is_array($block) && $block['question'] !== '' && $block['answer'] !== '')
            ->values()
            ->all();
    }

    private function cleanText(?string $value): string
    {
        $plain = strip_tags((string) $value);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? '';

        return trim($plain);
    }
}
