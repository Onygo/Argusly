<?php

namespace App\Services\Content;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ContentRenderNormalizer
{
    /**
     * @param array{inline_links_active?:bool,context?:string,content_id?:string} $options
     */
    public function normalize(string $html, array $options = []): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $this->debugSnapshot('before_enrichment', $html, $options);

        $document = $this->loadDocument($html);
        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return $html;
        }

        $inlineLinksActive = (bool) ($options['inline_links_active'] ?? $this->hasNonGeneratedArticleLinks($body));

        $this->cleanupWhitespace($body);
        $this->removeEmptyParagraphs($body);
        $this->normalizeLists($body);
        $this->mergeBrokenParagraphs($body);
        $this->removeLegacyPlaceholderResourceBlocks($body);
        $this->removeDuplicateResourceSections($body, $inlineLinksActive);
        $this->removeAnchorOnlyResourceParagraphs($body, $inlineLinksActive);
        $this->removeEmptyParagraphs($body);
        $this->cleanupWhitespace($body);

        $normalized = trim($this->innerHtml($body));
        $this->debugSnapshot('after_enrichment', $normalized, $options);

        $recovered = $this->validateAndRecover($normalized, $options);
        $this->debugSnapshot('after_purifier', $recovered, $options);

        return $recovered;
    }

    /**
     * @return array{html:string,changed:bool,removed_count:int}
     */
    public function removeLegacyPlaceholderResources(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [
                'html' => '',
                'changed' => false,
                'removed_count' => 0,
            ];
        }

        $document = $this->loadDocument($html);
        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return [
                'html' => $html,
                'changed' => false,
                'removed_count' => 0,
            ];
        }

        $removed = $this->removeLegacyPlaceholderResourceBlocks($body);
        $cleaned = trim($this->innerHtml($body));

        return [
            'html' => $cleaned,
            'changed' => $removed > 0 && $cleaned !== $html,
            'removed_count' => $removed,
        ];
    }

    public function cleanupWhitespace(DOMElement $root): void
    {
        for ($node = $root->firstChild; $node !== null; $node = $next) {
            $next = $node->nextSibling;

            if ($node instanceof DOMText && trim($node->nodeValue ?? '') === '') {
                $node->parentNode?->removeChild($node);
                continue;
            }

            if ($node instanceof DOMElement) {
                $this->cleanupWhitespace($node);
            }
        }
    }

    public function removeEmptyParagraphs(DOMElement $root): void
    {
        $changed = true;

        while ($changed) {
            $changed = false;
            foreach (['p', 'div', 'span'] as $tagName) {
                $nodes = [];
                foreach ($root->getElementsByTagName($tagName) as $node) {
                    if ($node instanceof DOMElement) {
                        $nodes[] = $node;
                    }
                }

                foreach ($nodes as $node) {
                    if ($node === $root || $this->hasMeaningfulEmbeddedContent($node)) {
                        continue;
                    }

                    if ($this->normalizedText($node->textContent) === '' && $node->parentNode instanceof DOMNode) {
                        $node->parentNode->removeChild($node);
                        $changed = true;
                    }
                }
            }
        }
    }

    public function mergeBrokenParagraphs(DOMElement $root): void
    {
        $children = $this->directElementChildren($root);
        $run = [];

        foreach ($children as $child) {
            if ($this->isKeywordDumpItem($child)) {
                $run[] = $child;
                continue;
            }

            $this->removeKeywordDumpRun($run);
            $run = [];

            if (in_array(Str::lower($child->tagName), ['div', 'section', 'aside'], true)) {
                $this->mergeBrokenParagraphs($child);
            }
        }

        $this->removeKeywordDumpRun($run);
    }

    public function normalizeLists(DOMElement $root): void
    {
        foreach (['ul', 'ol'] as $listTag) {
            $lists = [];
            foreach ($root->getElementsByTagName($listTag) as $list) {
                if ($list instanceof DOMElement) {
                    $lists[] = $list;
                }
            }

            foreach ($lists as $list) {
                foreach ($this->directElementChildren($list) as $child) {
                    if (Str::lower($child->tagName) === 'li') {
                        continue;
                    }

                    if (Str::lower($child->tagName) === 'p' && $this->normalizedText($child->textContent) !== '') {
                        $li = $list->ownerDocument?->createElement('li');
                        if (! $li instanceof DOMElement) {
                            continue;
                        }

                        while ($child->firstChild) {
                            $li->appendChild($child->firstChild);
                        }

                        $list->replaceChild($li, $child);
                    }
                }
            }
        }
    }

    public function removeDuplicateResourceSections(DOMElement $root, bool $inlineLinksActive = false): void
    {
        $seen = [];

        foreach ($this->directElementChildren($root) as $node) {
            if ($this->isGeneratedResourceBlock($node)) {
                $signature = $this->resourceSignature($node);
                if ($inlineLinksActive || isset($seen[$signature])) {
                    $node->parentNode?->removeChild($node);
                    continue;
                }

                $seen[$signature] = true;
            }

            if (in_array(Str::lower($node->tagName), ['div', 'section', 'aside'], true)) {
                $this->removeDuplicateResourceSections($node, $inlineLinksActive);
            }
        }
    }

    /**
     * @param array<string,mixed> $options
     */
    public function validateAndRecover(string $html, array $options = []): string
    {
        if (trim($html) === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            $this->debugSnapshot('validation_failed', $html, $options + ['error_count' => count($errors)]);

            return strip_tags($html, '<p><ul><ol><li><h2><h3><h4><blockquote><strong><em><b><i><a><table><thead><tbody><tr><th><td><br>');
        }

        $body = $document->getElementsByTagName('body')->item(0);

        return $body instanceof DOMElement ? trim($this->innerHtml($body)) : $html;
    }

    private function removeAnchorOnlyResourceParagraphs(DOMElement $root, bool $inlineLinksActive): void
    {
        if (! $inlineLinksActive) {
            return;
        }

        $paragraphs = [];
        foreach ($root->getElementsByTagName('p') as $paragraph) {
            if ($paragraph instanceof DOMElement) {
                $paragraphs[] = $paragraph;
            }
        }

        foreach ($paragraphs as $paragraph) {
            $anchors = $paragraph->getElementsByTagName('a');
            if ($anchors->length !== 1) {
                continue;
            }

            $text = $this->normalizedText($paragraph->textContent);
            $anchorText = $this->normalizedText($anchors->item(0)?->textContent);
            if ($text !== '' && $text === $anchorText && $paragraph->parentNode instanceof DOMNode) {
                $paragraph->parentNode->removeChild($paragraph);
            }
        }
    }

    private function removeLegacyPlaceholderResourceBlocks(DOMElement $root): int
    {
        $removed = 0;
        $children = $this->directElementChildren($root);

        foreach ($children as $index => $child) {
            if (in_array(Str::lower($child->tagName), ['div', 'section', 'aside'], true)) {
                $removed += $this->removeLegacyPlaceholderResourceBlocks($child);
            }

            if (! $this->isLegacyPlaceholderResourceBlock($child)) {
                continue;
            }

            $removed += $this->removePreviousLegacyResourceIntro($children, $index);
            $child->parentNode?->removeChild($child);
            $removed++;
        }

        return $removed;
    }

    /**
     * @param array<int,DOMElement> $siblings
     */
    private function removePreviousLegacyResourceIntro(array $siblings, int $index): int
    {
        if ($index < 1) {
            return 0;
        }

        $previous = $siblings[$index - 1] ?? null;
        if (! $previous instanceof DOMElement || Str::lower($previous->tagName) !== 'p') {
            return 0;
        }

        if ($this->isLegacyResourceIntro($previous)) {
            $previous->parentNode?->removeChild($previous);

            return 1;
        }

        return 0;
    }

    private function isLegacyPlaceholderResourceBlock(DOMElement $element): bool
    {
        if (! in_array(Str::lower($element->tagName), ['p', 'ul', 'ol', 'div', 'section', 'aside'], true)) {
            return false;
        }

        $anchors = [];
        foreach ($element->getElementsByTagName('a') as $anchor) {
            if ($anchor instanceof DOMElement && trim((string) $anchor->getAttribute('href')) !== '') {
                $anchors[] = $anchor;
            }
        }

        if ($anchors === []) {
            return false;
        }

        foreach ($anchors as $anchor) {
            if (! $this->isLegacyPlaceholderAnchor($anchor)) {
                return false;
            }
        }

        $text = $this->normalizedText($element->textContent);

        return preg_match('/\brelated article\s+\d+/i', $text) === 1;
    }

    private function isLegacyPlaceholderAnchor(DOMElement $anchor): bool
    {
        return preg_match('/^related article\s+\d+$/i', $this->normalizedText($anchor->textContent)) === 1;
    }

    private function isLegacyResourceIntro(DOMElement $element): bool
    {
        $text = $this->normalizedText($element->textContent);
        $lowerText = Str::lower($text);

        return $text !== ''
            && $element->getElementsByTagName('a')->length === 0
            && (
                str_contains($lowerText, 'to go deeper')
                || str_contains($lowerText, 'explore these resources')
                || str_contains($lowerText, 'bekijk dan ook onze aanvullende resources')
            );
    }

    private function hasNonGeneratedArticleLinks(DOMElement $body): bool
    {
        foreach ($body->getElementsByTagName('a') as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            if (trim((string) $anchor->getAttribute('href')) === '' || $this->normalizedText($anchor->textContent) === '') {
                continue;
            }

            if (! $this->hasGeneratedResourceAncestor($anchor)) {
                return true;
            }
        }

        return false;
    }

    private function hasGeneratedResourceAncestor(DOMElement $element): bool
    {
        for ($node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (Str::lower($node->tagName) === 'body') {
                return false;
            }

            if ($this->isGeneratedResourceBlock($node)) {
                return true;
            }
        }

        return false;
    }

    private function isGeneratedResourceBlock(DOMElement $element): bool
    {
        $text = $this->normalizedText($element->textContent);

        $lowerText = Str::lower($text);

        return $text !== ''
            && (
                str_contains($lowerText, 'aanvullende resources')
                || str_contains($lowerText, 'related reading')
                || str_contains($lowerText, 'explore resources like')
                || str_contains($lowerText, 'further reading')
                || preg_match('/related article \d+/i', $text) === 1
            );
    }

    private function isKeywordDumpItem(DOMElement $element): bool
    {
        if (Str::lower($element->tagName) !== 'p') {
            return false;
        }

        if ($element->getElementsByTagName('a')->length > 0 || $this->hasMeaningfulEmbeddedContent($element)) {
            return false;
        }

        $text = $this->normalizedText($element->textContent);
        if ($text === '') {
            return false;
        }

        $wordCount = str_word_count($text, 0, "’'-.0123456789");

        return $wordCount <= 4
            && mb_strlen($text) <= 60
            && preg_match('/[.!?;:]/u', $text) !== 1;
    }

    /**
     * @param array<int,DOMElement> $run
     */
    private function removeKeywordDumpRun(array $run): void
    {
        if (count($run) < 4) {
            return;
        }

        foreach ($run as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function resourceSignature(DOMElement $element): string
    {
        $hrefs = [];
        foreach ($element->getElementsByTagName('a') as $anchor) {
            if ($anchor instanceof DOMElement) {
                $hrefs[] = trim((string) $anchor->getAttribute('href'));
            }
        }

        return sha1($this->normalizedText($element->textContent) . '|' . implode('|', $hrefs));
    }

    private function hasMeaningfulEmbeddedContent(DOMElement $element): bool
    {
        foreach (['img', 'iframe', 'video', 'table', 'svg'] as $tag) {
            if ($element->getElementsByTagName($tag)->length > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,DOMElement>
     */
    private function directElementChildren(DOMElement $root): array
    {
        $children = [];

        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private function normalizedText(?string $value): string
    {
        $value = html_entity_decode(trim((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
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

    /**
     * @param array<string,mixed> $options
     */
    private function debugSnapshot(string $stage, string $html, array $options): void
    {
        if (! (bool) config('app.debug', false) && ! app()->environment('local')) {
            return;
        }

        Log::debug('content_render_normalizer.snapshot', [
            'stage' => $stage,
            'context' => (string) ($options['context'] ?? ''),
            'content_id' => (string) ($options['content_id'] ?? ''),
            'length' => strlen($html),
            'hash' => sha1($html),
            'preview' => Str::limit(strip_tags($html), 240, ''),
            'error_count' => (int) ($options['error_count'] ?? 0),
        ]);
    }
}
