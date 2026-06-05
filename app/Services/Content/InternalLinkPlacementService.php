<?php

namespace App\Services\Content;

use App\Data\InternalLinkSuggestion;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class InternalLinkPlacementService
{
    private ContentRenderNormalizer $normalizer;

    public function __construct(?ContentRenderNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? app(ContentRenderNormalizer::class);
    }

    /**
     * @var array<int,string>
     */
    private array $genericAnchorTexts = [
        'click here',
        'read more',
        'learn more',
        'related article',
        'related article 1',
        'related article 2',
        'related article 3',
        'related article 4',
        'related article 5',
        'related reading',
        'this article',
        'article',
        'post',
        'guide',
        'more',
    ];

    /**
     * @param Collection<int,InternalLinkSuggestion|array<string,mixed>>|array<int,InternalLinkSuggestion|array<string,mixed>> $links
     * @return array{
     *   updated_html:string,
     *   inline_links:array<int,array<string,string>>,
     *   fallback_links:array<int,array<string,string>>,
     *   removed_duplicate_count:int,
     *   removed_placeholder_count:int
     * }
     */
    public function placeIntoHtml(string $html, Collection|array $links): array
    {
        $html = trim($html);
        $entries = $this->normalizeEntries($links);

        if ($html === '') {
            return [
                'updated_html' => $html,
                'inline_links' => [],
                'fallback_links' => [],
                'removed_duplicate_count' => 0,
                'removed_placeholder_count' => 0,
            ];
        }

        $document = $this->loadDocument($html);
        $body = $document->getElementsByTagName('body')->item(0);

        if (! $body instanceof DOMElement) {
            return [
                'updated_html' => $html,
                'inline_links' => [],
                'fallback_links' => [],
                'removed_duplicate_count' => 0,
                'removed_placeholder_count' => 0,
            ];
        }

        $removedPlaceholderCount = $this->removeGeneratedRelatedBlocks($body);
        $usedUrls = $this->collectExistingHrefMap($body);
        $paragraphs = $this->paragraphs($body);
        $maxLinksPerArticle = min(5, max(3, (int) config('internal_linking.max_links_per_article', 4)));
        $inlineLinks = [];

        foreach ($entries as $entry) {
            if (count($inlineLinks) >= $maxLinksPerArticle) {
                break;
            }

            $normalizedUrl = $this->normalizeUrl($entry['target_url']);
            if ($normalizedUrl === '' || isset($usedUrls[$normalizedUrl])) {
                continue;
            }

            foreach ($paragraphs as $paragraph) {
                if ($this->paragraphHasAnchor($paragraph)) {
                    continue;
                }

                if ($this->injectEntryIntoParagraph($document, $paragraph, $entry)) {
                    $usedUrls[$normalizedUrl] = true;
                    $inlineLinks[] = $entry;
                    break;
                }
            }
        }

        $remaining = collect($entries)
            ->reject(fn (array $entry): bool => isset($usedUrls[$this->normalizeUrl($entry['target_url'])]))
            ->take(2)
            ->values()
            ->all();

        $fallbackLinks = [];

        if ($inlineLinks === [] && $remaining !== [] && count($remaining) <= 2) {
            $fallbackLinks = $this->appendFallbackBlock($document, $body, $remaining, $usedUrls);
        }

        $inlineLinksActive = $inlineLinks !== [] || $this->hasNonGeneratedArticleLinks($body);
        $updatedHtml = $this->normalizer->normalize(trim($this->innerHtml($body)), [
            'context' => 'internal_link_placement',
            'inline_links_active' => $inlineLinksActive,
        ]);

        return [
            'updated_html' => $updatedHtml,
            'inline_links' => $inlineLinks,
            'fallback_links' => $fallbackLinks,
            'removed_duplicate_count' => $removedPlaceholderCount,
            'removed_placeholder_count' => $removedPlaceholderCount,
        ];
    }

    /**
     * @param Collection<int,InternalLinkSuggestion|array<string,mixed>>|array<int,InternalLinkSuggestion|array<string,mixed>> $links
     * @return array<int,array<string,string>>
     */
    private function normalizeEntries(Collection|array $links): array
    {
        return collect($links)
            ->map(function (InternalLinkSuggestion|array $entry): ?array {
                if ($entry instanceof InternalLinkSuggestion) {
                    $normalized = $entry->toArray();
                    $normalized['title'] = $entry->anchorText;

                    return $normalized;
                }

                if (! is_array($entry)) {
                    return null;
                }

                $targetUrl = trim((string) ($entry['target_url'] ?? $entry['href'] ?? ''));
                $targetContentId = trim((string) ($entry['target_content_id'] ?? $entry['source_publishlayer_id'] ?? $entry['id'] ?? ''));
                $anchorText = trim((string) ($entry['anchor_text'] ?? $entry['anchor'] ?? $entry['title'] ?? ''));
                $title = trim((string) ($entry['title'] ?? $anchorText));
                $reason = trim((string) ($entry['reason'] ?? ''));

                if ($targetUrl === '' || $this->normalizeUrl($targetUrl) === '') {
                    return null;
                }

                return [
                    'target_content_id' => $targetContentId,
                    'target_url' => $targetUrl,
                    'anchor_text' => $anchorText,
                    'title' => $title,
                    'reason' => $reason,
                ];
            })
            ->filter()
            ->unique(fn (array $entry): string => $this->normalizeUrl($entry['target_url']))
            ->values()
            ->all();
    }

    /**
     * @return array<string,bool>
     */
    private function collectExistingHrefMap(DOMElement $body): array
    {
        $map = [];

        foreach ($body->getElementsByTagName('a') as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $normalized = $this->normalizeUrl((string) $anchor->getAttribute('href'));
            if ($normalized !== '') {
                $map[$normalized] = true;
            }
        }

        return $map;
    }

    /**
     * @return array<int,DOMElement>
     */
    private function paragraphs(DOMElement $body): array
    {
        $paragraphs = [];

        foreach ($body->getElementsByTagName('p') as $paragraph) {
            if ($paragraph instanceof DOMElement) {
                $paragraphs[] = $paragraph;
            }
        }

        return $paragraphs;
    }

    private function paragraphHasAnchor(DOMElement $paragraph): bool
    {
        foreach ($paragraph->getElementsByTagName('a') as $anchor) {
            if ($anchor instanceof DOMElement) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,string> $entry
     */
    private function injectEntryIntoParagraph(DOMDocument $document, DOMElement $paragraph, array $entry): bool
    {
        $anchorCandidates = array_values(array_unique(array_filter([
            trim((string) ($entry['anchor_text'] ?? '')),
            trim((string) ($entry['title'] ?? '')),
        ], fn (string $value): bool => $this->isUsableAnchorText($value))));

        if ($anchorCandidates === []) {
            return false;
        }

        $textNodes = [];
        $this->collectEligibleTextNodes($paragraph, $textNodes);

        foreach ($anchorCandidates as $anchorCandidate) {
            foreach ($textNodes as $textNode) {
                $text = (string) $textNode->nodeValue;
                if (preg_match('/(?<![\pL\pN-])' . preg_quote($anchorCandidate, '/') . '(?![\pL\pN-])/iu', $text, $matches, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                $match = (string) ($matches[0][0] ?? '');
                $offset = (int) ($matches[0][1] ?? -1);
                if ($match === '' || $offset < 0) {
                    continue;
                }

                $before = substr($text, 0, $offset);
                $after = substr($text, $offset + strlen($match));
                $fragment = $document->createDocumentFragment();

                if ($before !== '') {
                    $fragment->appendChild(new DOMText($before));
                }

                $anchor = $document->createElement('a');
                $anchor->setAttribute('href', (string) $entry['target_url']);
                $anchor->appendChild(new DOMText($match));
                $fragment->appendChild($anchor);

                if ($after !== '') {
                    $fragment->appendChild(new DOMText($after));
                }

                $textNode->parentNode?->replaceChild($fragment, $textNode);

                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,DOMText> $nodes
     */
    private function collectEligibleTextNodes(DOMNode $node, array &$nodes): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(Str::lower($child->tagName), ['a', 'script', 'style'], true)) {
                continue;
            }

            if ($child instanceof DOMText && trim((string) $child->nodeValue) !== '') {
                $nodes[] = $child;
                continue;
            }

            if ($child instanceof DOMElement) {
                $this->collectEligibleTextNodes($child, $nodes);
            }
        }
    }

    /**
     * @param array<int,array<string,string>> $entries
     * @param array<string,bool> $usedUrls
     * @return array<int,array<string,string>>
     */
    private function appendFallbackBlock(DOMDocument $document, DOMElement $body, array $entries, array &$usedUrls): array
    {
        $items = [];
        $applied = [];

        foreach ($entries as $entry) {
            $normalizedUrl = $this->normalizeUrl($entry['target_url']);
            if ($normalizedUrl === '' || isset($usedUrls[$normalizedUrl])) {
                continue;
            }

            $label = $this->fallbackLabel($entry);
            if ($label === '') {
                continue;
            }

            $anchor = $document->createElement('a');
            $anchor->setAttribute('href', (string) $entry['target_url']);
            $anchor->appendChild(new DOMText($label));
            $items[] = $anchor;
            $applied[] = $entry;
            $usedUrls[$normalizedUrl] = true;
        }

        if ($items === []) {
            return [];
        }

        $paragraph = $document->createElement('p');
        $strong = $document->createElement('strong', 'Related reading:');
        $paragraph->appendChild($strong);
        $paragraph->appendChild(new DOMText(' '));

        foreach ($items as $index => $item) {
            if ($index > 0) {
                $separator = $index === count($items) - 1 ? ' and ' : ', ';
                $paragraph->appendChild(new DOMText($separator));
            }

            $paragraph->appendChild($item);
        }

        $paragraph->appendChild(new DOMText('.'));
        $body->appendChild($paragraph);

        return $applied;
    }

    private function fallbackLabel(array $entry): string
    {
        $title = trim((string) ($entry['title'] ?? ''));
        if ($this->isUsableAnchorText($title)) {
            return $title;
        }

        $anchorText = trim((string) ($entry['anchor_text'] ?? ''));

        return $this->isUsableAnchorText($anchorText) ? $anchorText : '';
    }

    private function isUsableAnchorText(string $value): bool
    {
        $normalized = $this->normalizeComparableText($value);

        return $normalized !== ''
            && ! in_array($normalized, $this->genericAnchorTexts, true)
            && ! preg_match('/^related article \d+$/', $normalized)
            && mb_strlen($normalized) >= 4;
    }

    private function removeGeneratedRelatedBlocks(DOMElement $body): int
    {
        $removed = 0;

        for ($node = $body->firstChild; $node !== null; $node = $next) {
            $next = $node->nextSibling;

            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($this->isGeneratedRelatedContainer($node)) {
                $body->removeChild($node);
                $removed++;
            }
        }

        return $removed;
    }

    private function hasNonGeneratedArticleLinks(DOMElement $body): bool
    {
        foreach ($body->getElementsByTagName('a') as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            if (trim((string) $anchor->getAttribute('href')) === '') {
                continue;
            }

            if (! $this->isGeneratedRelatedContainer($anchor->parentNode instanceof DOMElement ? $anchor->parentNode : $body)) {
                return true;
            }
        }

        return false;
    }

    private function isGeneratedRelatedContainer(DOMElement $element): bool
    {
        $text = $this->normalizeComparableText($element->textContent);

        if ($text === '') {
            return false;
        }

        if (
            str_contains($text, 'related reading')
            || str_contains($text, 'explore resources like')
            || str_contains($text, 'aanvullende resources')
            || preg_match('/related article \d+/i', $text) === 1
        ) {
            return true;
        }

        if (! in_array(Str::lower($element->tagName), ['p', 'div', 'section', 'aside', 'ul', 'ol'], true)) {
            return false;
        }

        $anchors = $element->getElementsByTagName('a');
        if ($anchors->length === 0) {
            return false;
        }

        foreach ($anchors as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $label = $this->normalizeComparableText($anchor->textContent);
            if ($label !== '' && ! preg_match('/^related article \d+$/', $label) && $label !== 'read more' && $label !== 'related reading') {
                return false;
            }
        }

        return true;
    }

    private function normalizeComparableText(?string $value): string
    {
        $value = html_entity_decode(trim((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::lower(trim($value));
    }

    private function normalizeUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return Str::lower(rtrim($url, '/'));
        }

        $scheme = isset($parts['scheme']) ? Str::lower((string) $parts['scheme']) . '://' : '';
        $host = isset($parts['host']) ? Str::lower((string) $parts['host']) : '';
        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        if ($scheme === '' && $host === '') {
            return Str::lower($path . $query . $fragment);
        }

        return $scheme . $host . $path . $query . $fragment;
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

    private function innerHtml(DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument?->saveHTML($child) ?: '';
        }

        return $html;
    }
}
