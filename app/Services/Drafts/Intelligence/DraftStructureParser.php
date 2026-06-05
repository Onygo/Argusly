<?php

namespace App\Services\Drafts\Intelligence;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Str;

class DraftStructureParser
{
    /**
     * @return array<string,mixed>
     */
    public function parse(string $html): array
    {
        $wrappedHtml = '<div>' . $html . '</div>';
        $dom = new DOMDocument('1.0', 'UTF-8');

        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $root = $dom->documentElement;
        $blocks = [];

        if ($root instanceof DOMNode) {
            foreach ($root->childNodes as $child) {
                $this->collectBlocks($child, $blocks);
            }
        }

        $blocks = collect($blocks)
            ->filter(fn (array $block): bool => trim((string) ($block['text'] ?? '')) !== '')
            ->values()
            ->all();

        $headings = collect($blocks)
            ->where('type', 'heading')
            ->map(fn (array $block): array => [
                'level' => (int) ($block['level'] ?? 2),
                'text' => (string) ($block['text'] ?? ''),
            ])
            ->values()
            ->all();

        $paragraphs = collect($blocks)
            ->whereIn('type', ['paragraph', 'list_item'])
            ->pluck('text')
            ->values()
            ->all();

        $sections = [];
        $currentSection = null;

        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'heading') {
                if ($currentSection !== null) {
                    $sections[] = $this->finalizeSection($currentSection);
                }

                $currentSection = [
                    'heading' => (string) ($block['text'] ?? ''),
                    'level' => (int) ($block['level'] ?? 2),
                    'blocks' => [],
                ];

                continue;
            }

            $currentSection ??= [
                'heading' => null,
                'level' => null,
                'blocks' => [],
            ];
            $currentSection['blocks'][] = $block;
        }

        if ($currentSection !== null) {
            $sections[] = $this->finalizeSection($currentSection);
        }

        $plainText = collect($blocks)
            ->pluck('text')
            ->implode("\n\n");

        $ctaCandidateBlocks = collect($blocks)
            ->filter(function (array $block): bool {
                $text = (string) ($block['text'] ?? '');

                return preg_match('/\b(plan|boek|vraag|start|begin|gebruik|zet|verken|bepaal|schedule|book|request|download|contact|get started|demo|pilot|checklist)\b/iu', $text) === 1;
            })
            ->pluck('text')
            ->values()
            ->all();

        $summarySections = collect($sections)
            ->filter(fn (array $section): bool => preg_match('/\b(summary|samenvatting|key takeaways|at a glance|in het kort|belangrijkste punten)\b/iu', (string) ($section['heading'] ?? '')) === 1)
            ->values();
        $faqSections = collect($sections)
            ->filter(fn (array $section): bool => preg_match('/\b(faq|veelgestelde vragen)\b/iu', (string) ($section['heading'] ?? '')) === 1 || Str::contains((string) ($section['heading'] ?? ''), '?'))
            ->values();
        $comparisonSections = collect($sections)
            ->filter(function (array $section): bool {
                $haystack = (string) (($section['heading'] ?? '') . ' ' . ($section['text'] ?? ''));

                return preg_match('/\b(vs\.?|versus|compare|comparison|difference|verschil)\b/iu', $haystack) === 1;
            })
            ->values();
        $stepSections = collect($sections)
            ->filter(function (array $section): bool {
                $heading = (string) ($section['heading'] ?? '');
                $paragraphs = collect((array) ($section['paragraphs'] ?? []));

                return preg_match('/\b(step|steps|stap|stappen|how to|aanpak|roadmap|proces)\b/iu', $heading) === 1
                    || $paragraphs->count() >= 3;
            })
            ->values();
        $definitionPassages = collect($paragraphs)
            ->filter(fn (string $paragraph): bool => preg_match('/\b(is|are|means|refers to|can be defined as|betekent|is een|verwijst naar)\b/iu', $paragraph) === 1)
            ->values()
            ->all();
        $extractablePassages = collect($paragraphs)
            ->filter(function (string $paragraph): bool {
                $wordCount = $this->wordCount($paragraph);

                return $wordCount >= 18
                    && $wordCount <= 70
                    && preg_match('/\b(is|are|means|should|start|plan|because|samengevat|summary|in short|key takeaway|first|second|third|stap|stappen)\b/iu', $paragraph) === 1;
            })
            ->values()
            ->all();

        $nonHeadingTextBlocks = collect($blocks)
            ->whereNotIn('type', ['heading'])
            ->pluck('text')
            ->values();

        return [
            'blocks' => $blocks,
            'plain_text' => $this->normalizeWhitespace($plainText),
            'intro' => $this->normalizeWhitespace($nonHeadingTextBlocks->take(2)->implode("\n\n")),
            'conclusion' => $this->normalizeWhitespace($nonHeadingTextBlocks->take(-2)->implode("\n\n")),
            'headings' => $headings,
            'paragraphs' => $paragraphs,
            'sections' => $sections,
            'cta_candidate_blocks' => array_values(array_unique(array_filter($ctaCandidateBlocks))),
            'summary_section_count' => $summarySections->count(),
            'faq_section_count' => $faqSections->count(),
            'comparison_section_count' => $comparisonSections->count(),
            'step_section_count' => $stepSections->count(),
            'definition_passages' => $definitionPassages,
            'extractable_passages' => $extractablePassages,
            'list_count' => collect($blocks)->where('type', 'list_item')->count(),
            'heading_count' => count($headings),
            'sentence_count' => $this->sentenceCount($plainText),
            'word_count' => $this->wordCount($plainText),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $blocks
     */
    private function collectBlocks(DOMNode $node, array &$blocks): void
    {
        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);

            if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                $blocks[] = [
                    'type' => 'heading',
                    'tag' => $tag,
                    'level' => (int) substr($tag, 1),
                    'text' => $this->normalizeWhitespace($node->textContent ?? ''),
                ];

                return;
            }

            if ($tag === 'p') {
                $blocks[] = [
                    'type' => 'paragraph',
                    'tag' => $tag,
                    'text' => $this->normalizeWhitespace($node->textContent ?? ''),
                ];

                return;
            }

            if ($tag === 'li') {
                $blocks[] = [
                    'type' => 'list_item',
                    'tag' => $tag,
                    'text' => $this->normalizeWhitespace($node->textContent ?? ''),
                ];

                return;
            }
        }

        foreach ($node->childNodes as $child) {
            $this->collectBlocks($child, $blocks);
        }
    }

    /**
     * @param array{heading:?string,level:?int,blocks:array<int,array<string,mixed>>} $section
     * @return array<string,mixed>
     */
    private function finalizeSection(array $section): array
    {
        $text = collect($section['blocks'])
            ->pluck('text')
            ->implode("\n\n");

        return [
            'heading' => $section['heading'],
            'level' => $section['level'],
            'paragraphs' => collect($section['blocks'])
                ->pluck('text')
                ->values()
                ->all(),
            'text' => $this->normalizeWhitespace($text),
            'word_count' => $this->wordCount($text),
        ];
    }

    private function sentenceCount(string $text): int
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [];

        return collect($sentences)->filter(fn (string $sentence): bool => trim($sentence) !== '')->count();
    }

    private function wordCount(string $text): int
    {
        preg_match_all('/[\pL\pN\']+/u', $text, $matches);

        return count($matches[0] ?? []);
    }

    private function normalizeWhitespace(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::of($value)->trim()->toString();
    }
}
