<?php

namespace App\Services\Content;

use App\Models\Content;
use App\Models\StructuredAnswerBlock;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnswerBlockInjectorService
{
    private const ROOT_ID = 'pl-answer-block-root';

    /**
     * @return Collection<int,StructuredAnswerBlock>
     */
    public function visibleBlocks(Content $content, ?string $mode = null, ?int $maxVisible = null): Collection
    {
        $content->loadMissing('answerBlocks');

        $mode ??= $this->resolveRenderMode($content);
        if ($mode === Content::ANSWER_BLOCK_RENDER_MODE_DISABLED) {
            return collect();
        }

        $limit = $this->resolveMaxVisible($content, $maxVisible);
        if ($limit < 1) {
            return collect();
        }

        $seen = [];

        return $content->answerBlocks
            ->sortBy(fn (StructuredAnswerBlock $block): int => (int) $block->order)
            ->filter(function (StructuredAnswerBlock $block) use (&$seen): bool {
                $question = $this->normalizeComparableText((string) $block->question);
                $answer = $this->normalizeComparableText((string) $block->answer);

                if ($question === '' || $answer === '' || isset($seen[$question])) {
                    return false;
                }

                $seen[$question] = true;

                return true;
            })
            ->take($limit)
            ->values();
    }

    public function resolveRenderMode(Content $content): string
    {
        $mode = trim((string) ($content->answer_block_render_mode ?? ''));
        if (in_array($mode, Content::answerBlockRenderModes(), true)) {
            return $mode;
        }

        $content->loadMissing('answerBlocks');

        return $content->answerBlocks->isNotEmpty()
            ? Content::ANSWER_BLOCK_RENDER_MODE_AI_OPTIMIZED
            : Content::ANSWER_BLOCK_RENDER_MODE_DISABLED;
    }

    public function resolveMaxVisible(Content $content, ?int $override = null): int
    {
        $value = $override ?? $content->answer_block_max_visible ?? config('argusly.answer_blocks.default_max_visible', 3);

        return max(1, min(10, (int) $value));
    }

    public function inject(string $html, Content $content, ?string $mode = null, ?int $maxVisible = null): string
    {
        $html = trim($html);
        if ($html === '') {
            return $html;
        }

        $mode ??= $this->resolveRenderMode($content);
        if ($mode === Content::ANSWER_BLOCK_RENDER_MODE_DISABLED) {
            return $html;
        }

        $blocks = $this->visibleBlocks($content, $mode, $maxVisible);
        if ($blocks->isEmpty() || str_contains($html, 'data-answer-block="true"')) {
            return $html;
        }

        try {
            return match ($mode) {
                Content::ANSWER_BLOCK_RENDER_MODE_BOTTOM => $this->injectBottom($html, $blocks),
                Content::ANSWER_BLOCK_RENDER_MODE_INLINE => $this->injectInline($html, $blocks),
                default => $this->injectAiOptimized($html, $blocks),
            };
        } catch (Throwable $exception) {
            Log::warning('content.answer_blocks.injection_failed', [
                'content_id' => (string) $content->id,
                'mode' => $mode,
                'message' => $exception->getMessage(),
            ]);

            return $html;
        }
    }

    /**
     * @param Collection<int,StructuredAnswerBlock> $blocks
     */
    private function injectBottom(string $html, Collection $blocks): string
    {
        [$document, $root] = $this->parseFragment($html);
        if (! $root) {
            return $html;
        }

        foreach ($blocks as $block) {
            if ($this->documentContainsBlock($root, $block)) {
                continue;
            }

            $root->appendChild($this->renderBlockNode($document, $block, 'h2'));
        }

        return $this->serializeRoot($root);
    }

    /**
     * @param Collection<int,StructuredAnswerBlock> $blocks
     */
    private function injectInline(string $html, Collection $blocks): string
    {
        [$document, $root] = $this->parseFragment($html);
        if (! $root) {
            return $html;
        }

        $slots = $this->defaultInsertionSlots($root);
        if ($slots === []) {
            return $html;
        }

        foreach ($blocks as $index => $block) {
            if ($this->documentContainsBlock($root, $block)) {
                continue;
            }

            $slot = $slots[min($index, count($slots) - 1)] ?? null;
            if (! $slot instanceof DOMNode || ! $slot->parentNode) {
                continue;
            }

            $this->insertAfter($slot, $this->renderBlockNode($document, $block, 'h2'));
        }

        return $this->serializeRoot($root);
    }

    /**
     * @param Collection<int,StructuredAnswerBlock> $blocks
     */
    private function injectAiOptimized(string $html, Collection $blocks): string
    {
        [$document, $root] = $this->parseFragment($html);
        if (! $root) {
            return $html;
        }

        $slots = $this->defaultInsertionSlots($root);
        if ($slots === []) {
            return $html;
        }

        $headings = $this->collectHeadings($root);
        $usedSlots = [];
        $pending = [];

        foreach ($blocks as $block) {
            if ($this->documentContainsBlock($root, $block)) {
                continue;
            }

            $slot = $this->bestHeadingSlot($block, $headings, $usedSlots);
            if ($slot instanceof DOMNode) {
                $this->insertAfter($slot, $this->renderBlockNode($document, $block, 'h3'));
                $usedSlots[spl_object_id($slot)] = true;

                continue;
            }

            $pending[] = $block;
        }

        foreach ($pending as $index => $block) {
            $slot = $slots[min($index, count($slots) - 1)] ?? null;
            if (! $slot instanceof DOMNode || ! $slot->parentNode) {
                continue;
            }

            $this->insertAfter($slot, $this->renderBlockNode($document, $block, 'h2'));
        }

        return $this->serializeRoot($root);
    }

    /**
     * @return array{0:DOMDocument,1:?DOMElement}
     */
    private function parseFragment(string $html): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);

        $wrapped = '<div id="'.self::ROOT_ID.'">'.$html.'</div>';
        $loaded = $document->loadHTML(
            mb_convert_encoding($wrapped, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();

        if (! $loaded) {
            return [$document, null];
        }

        $root = $document->getElementById(self::ROOT_ID);

        return [$document, $root instanceof DOMElement ? $root : null];
    }

    /**
     * @return array<int,DOMElement>
     */
    private function defaultInsertionSlots(DOMElement $root): array
    {
        $slots = [];

        foreach ($root->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if ($tag === 'p') {
                $slots[] = $child;
                break;
            }
        }

        foreach ($this->collectHeadings($root) as $heading) {
            $slots[] = $heading;
        }

        if ($slots === []) {
            foreach ($root->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $slots[] = $child;
                }
            }
        }

        $unique = [];
        $resolved = [];

        foreach ($slots as $slot) {
            $key = spl_object_id($slot);
            if (isset($unique[$key])) {
                continue;
            }

            $unique[$key] = true;
            $resolved[] = $slot;
        }

        return $resolved;
    }

    /**
     * @return array<int,DOMElement>
     */
    private function collectHeadings(DOMElement $root): array
    {
        $headings = [];

        foreach ($root->getElementsByTagName('*') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if (in_array($tag, ['h2', 'h3'], true)) {
                $headings[] = $node;
            }
        }

        return $headings;
    }

    /**
     * @param array<int,DOMElement> $headings
     * @param array<int,bool> $usedSlots
     */
    private function bestHeadingSlot(StructuredAnswerBlock $block, array $headings, array $usedSlots): ?DOMElement
    {
        $needle = collect(array_merge(
            $this->tokenize((string) $block->question),
            collect((array) $block->entities)->flatMap(fn ($item): array => $this->tokenize((string) $item))->all()
        ))->unique()->values()->all();

        $best = null;
        $bestScore = 0;

        foreach ($headings as $heading) {
            if (isset($usedSlots[spl_object_id($heading)])) {
                continue;
            }

            $score = count(array_intersect($needle, $this->tokenize((string) $heading->textContent)));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $heading;
            }
        }

        return $bestScore > 0 ? $best : null;
    }

    private function documentContainsBlock(DOMElement $root, StructuredAnswerBlock $block): bool
    {
        $question = $this->normalizeComparableText((string) $block->question);
        if ($question === '') {
            return true;
        }

        foreach ($root->getElementsByTagName('*') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($node->getAttribute('data-answer-question') === $question) {
                return true;
            }
        }

        return false;
    }

    private function renderBlockNode(DOMDocument $document, StructuredAnswerBlock $block, string $headingTag): DOMNode
    {
        $html = view('components.content.answer-block', [
            'block' => $block,
            'headingTag' => $headingTag,
        ])->render();

        [$fragmentDocument, $root] = $this->parseFragment($html);
        if (! $root) {
            return $document->createTextNode('');
        }

        $first = null;
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $first = $child;
                break;
            }
        }

        return $document->importNode($first ?? $fragmentDocument->createTextNode(''), true);
    }

    private function insertAfter(DOMNode $target, DOMNode $newNode): void
    {
        if (! $target->parentNode) {
            return;
        }

        if ($target->nextSibling) {
            $target->parentNode->insertBefore($newNode, $target->nextSibling);

            return;
        }

        $target->parentNode->appendChild($newNode);
    }

    private function serializeRoot(DOMElement $root): string
    {
        $html = '';

        foreach ($root->childNodes as $child) {
            $html .= $root->ownerDocument?->saveHTML($child) ?? '';
        }

        return trim($html);
    }

    /**
     * @return array<int,string>
     */
    private function tokenize(string $value): array
    {
        $normalized = $this->normalizeComparableText($value);
        if ($normalized === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $normalized), fn (string $token): bool => mb_strlen($token) >= 3));
    }

    private function normalizeComparableText(string $value): string
    {
        $value = strtolower(strip_tags($value));
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value);
    }
}
