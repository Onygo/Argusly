<?php

namespace App\Services\ContentChain;

use App\Models\Content;
use App\Models\ContentChainGuidance;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InlineLinkCandidateMatcher
{
    /**
     * @param Collection<int,Content> $targets
     * @param array<string,mixed> $signals
     * @return array{inline:Collection<int,array<string,mixed>>,footer:Collection<int,array<string,mixed>>}
     */
    public function match(Content $source, Collection $targets, array $signals, ?ContentChainGuidance $guidance = null): array
    {
        $sourceHtml = $this->sourceHtml($source);
        if ($sourceHtml === '') {
            return ['inline' => collect(), 'footer' => collect()];
        }

        $genericTerms = collect((array) config('content_chain.inline_links.generic_terms', []))
            ->map(fn (string $term): string => Str::lower(trim($term)))
            ->filter()
            ->values()
            ->all();
        $allowHeadingLinks = $guidance?->allow_heading_links ?? (bool) config('content_chain.inline_links.allow_heading_links', false);
        $maxLinks = max(1, (int) ($guidance?->max_inline_links ?: config('content_chain.inline_links.default_max_links', 4)));
        $confidenceThreshold = (float) config('content_chain.suggestions.confidence_threshold', 0.58);
        $allowedTags = collect((array) config('content_chain.inline_links.allowed_tags', ['p', 'li', 'blockquote']))
            ->map(fn (string $tag): string => Str::lower(trim($tag)))
            ->values()
            ->all();

        if ($allowHeadingLinks) {
            $allowedTags = array_values(array_unique(array_merge($allowedTags, ['h2', 'h3', 'h4'])));
        }

        $document = $this->loadDocument($sourceHtml);
        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return ['inline' => collect(), 'footer' => collect()];
        }

        $existingLinkTargets = $this->existingLinkTargets($body);
        $blocks = $this->extractTextBlocks($body, $allowedTags);
        $inlineRows = [];
        $usedTargets = [];

        foreach ($targets as $target) {
            $targetId = (string) $target->id;
            if ($targetId === '' || isset($usedTargets[$targetId])) {
                continue;
            }

            $phrases = $this->phrasesForTarget($target, $genericTerms);
            if ($phrases === []) {
                continue;
            }

            foreach ($phrases as $phrase) {
                foreach ($blocks as $block) {
                    if ($this->containsPhrase($block['text'], $phrase) && ! $this->containsExistingAnchor($block['html'], $phrase)) {
                        $confidence = $this->confidenceForPhrase($source, $target, $phrase, $signals);
                        if ($confidence < $confidenceThreshold) {
                            continue;
                        }

                        $inlineRows[] = [
                            'target' => $target,
                            'suggestion_kind' => 'inline_link',
                            'suggestion_type' => 'contextual_inline',
                            'anchor_text' => $phrase,
                            'placement_type' => 'inline',
                            'placement_label' => $block['label'],
                            'rationale' => sprintf(
                                'Matched "%s" in %s to contextual chained content.',
                                $phrase,
                                $block['label']
                            ),
                            'confidence_score' => round($confidence, 2),
                            'placement_meta' => [
                                'tag' => $block['tag'],
                                'context' => Str::limit($block['text'], 220, ''),
                            ],
                            'meta' => [
                                'target_url' => $this->targetUrl($target),
                                'existing_target' => in_array($this->targetUrl($target), $existingLinkTargets, true),
                            ],
                        ];

                        $usedTargets[$targetId] = true;
                        break 2;
                    }
                }
            }

            if (count($inlineRows) >= $maxLinks) {
                break;
            }
        }

        $inline = collect($inlineRows)
            ->take($maxLinks)
            ->values();

        $usedInlineIds = $inline->map(fn (array $row): string => (string) $row['target']->id)->all();

        $footer = $targets
            ->reject(fn (Content $target): bool => in_array((string) $target->id, $usedInlineIds, true))
            ->filter(fn (Content $target): bool => $this->targetUrl($target) !== '')
            ->take((int) config('content_chain.suggestions.max_footer_links_per_content', 3))
            ->map(function (Content $target): array {
                return [
                    'target' => $target,
                    'suggestion_kind' => 'footer_link',
                    'suggestion_type' => 'supplementary_footer',
                    'anchor_text' => trim((string) ($target->primary_keyword ?: $target->title)),
                    'placement_type' => 'footer',
                    'placement_label' => 'Additional reading',
                    'rationale' => 'Relevant chained article kept as additional reading because inline space is limited.',
                    'confidence_score' => 0.55,
                    'placement_meta' => [
                        'tag' => 'section',
                        'context' => 'Supplementary chained links',
                    ],
                    'meta' => [
                        'target_url' => $this->targetUrl($target),
                    ],
                ];
            })
            ->values();

        return [
            'inline' => $inline,
            'footer' => $footer,
        ];
    }

    private function sourceHtml(Content $content): string
    {
        return trim((string) ($content->currentRevision?->content_html ?: $content->currentVersion?->body ?: ''));
    }

    /**
     * @return array<int,string>
     */
    private function phrasesForTarget(Content $target, array $genericTerms): array
    {
        return collect([
            trim((string) $target->primary_keyword),
            trim((string) $target->title),
        ])
            ->map(fn (string $phrase): string => trim(preg_replace('/\s+/u', ' ', $phrase) ?? ''))
            ->filter(fn (string $phrase): bool => mb_strlen($phrase) >= 5)
            ->reject(fn (string $phrase): bool => in_array(Str::lower($phrase), $genericTerms, true))
            ->unique()
            ->sortByDesc(fn (string $phrase): int => mb_strlen($phrase))
            ->values()
            ->all();
    }

    private function confidenceForPhrase(Content $source, Content $target, string $phrase, array $signals): float
    {
        $score = 0.45;
        $sourceIsPillar = (bool) ($source->seriesArticle?->is_pillar ?? false);
        $targetIsPillar = (bool) ($target->seriesArticle?->is_pillar ?? false);

        if ($target->series_id && (string) $target->series_id === (string) $source->series_id) {
            $score += 0.2;

            if (! $sourceIsPillar && $targetIsPillar) {
                $score += 0.12;
            } elseif ($sourceIsPillar && ! $targetIsPillar) {
                $score += 0.08;
            }
        }

        if (Str::lower(trim((string) $target->primary_keyword)) === Str::lower(trim($phrase))) {
            $score += 0.18;
        }

        if (Str::lower(trim((string) $target->title)) === Str::lower(trim($phrase))) {
            $score += 0.12;
        }

        if ((float) ($signals['source_score'] ?? 0.0) >= 70) {
            $score += 0.08;
        }

        return min(0.99, $score);
    }

    private function containsPhrase(string $haystack, string $phrase): bool
    {
        return preg_match('/(?<![\pL\pN])' . preg_quote($phrase, '/') . '(?![\pL\pN])/iu', $haystack) === 1;
    }

    private function containsExistingAnchor(string $html, string $phrase): bool
    {
        return preg_match('/<a\b[^>]*>\s*' . preg_quote($phrase, '/') . '\s*<\/a>/iu', $html) === 1;
    }

    /**
     * @param array<int,string> $allowedTags
     * @return array<int,array{tag:string,label:string,text:string,html:string}>
     */
    private function extractTextBlocks(DOMElement $body, array $allowedTags): array
    {
        $blocks = [];

        foreach ($body->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $tag = Str::lower($node->tagName);
            if (! in_array($tag, $allowedTags, true)) {
                continue;
            }

            $text = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');
            if ($text === '') {
                continue;
            }

            $blocks[] = [
                'tag' => $tag,
                'label' => strtoupper($tag),
                'text' => $text,
                'html' => $node->ownerDocument?->saveHTML($node) ?: '',
            ];
        }

        return $blocks;
    }

    /**
     * @return array<int,string>
     */
    private function existingLinkTargets(DOMElement $body): array
    {
        $targets = [];

        foreach ($body->getElementsByTagName('a') as $anchor) {
            $href = trim((string) $anchor->getAttribute('href'));
            if ($href !== '') {
                $targets[] = $href;
            }
        }

        return array_values(array_unique($targets));
    }

    private function targetUrl(Content $target): string
    {
        return trim((string) ($target->published_url ?? ''));
    }

    private function loadDocument(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }
}
