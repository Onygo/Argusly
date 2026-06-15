<?php

namespace App\Services\ContentChain;

use App\Jobs\RebuildContentMarkdownArtifactJob;
use App\Models\Content;
use App\Models\ContentChainSuggestion;
use App\Models\Draft;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\Content\ContentRenderNormalizer;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ContextualLinkInsertionService
{
    public function __construct(
        private readonly ContentCacheInvalidationService $cacheInvalidation,
        private readonly ContentRenderNormalizer $normalizer,
    ) {
    }

    /**
     * @return array{applied_inline:int,applied_footer:int}
     */
    public function applyApprovedSuggestions(Content $content, bool $autoMode = false): array
    {
        $content->loadMissing(['currentRevision', 'currentVersion', 'outboundChainSuggestions.targetContent']);

        $suggestions = $content->outboundChainSuggestions
            ->filter(function (ContentChainSuggestion $suggestion): bool {
                return in_array($suggestion->status, [
                    ContentChainSuggestion::STATUS_APPROVED,
                    ContentChainSuggestion::STATUS_AUTO_APPLIED,
                ], true);
            })
            ->sortByDesc('score')
            ->values();

        $html = trim((string) ($content->currentRevision?->content_html ?: $content->currentVersion?->body ?: ''));
        if ($html === '' || $suggestions->isEmpty()) {
            return ['applied_inline' => 0, 'applied_footer' => 0];
        }

        $document = $this->loadDocument($html);
        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return ['applied_inline' => 0, 'applied_footer' => 0];
        }

        $this->removeGeneratedFooterSection($body);

        $inlineSuggestions = $suggestions
            ->where('suggestion_kind', ContentChainSuggestion::KIND_INLINE_LINK)
            ->values();
        $usedTargets = [];
        $appliedInline = 0;
        $appliedInlineLinks = [];

        foreach ($inlineSuggestions as $suggestion) {
            if ($this->applyInlineSuggestion($document, $body, $suggestion, $usedTargets)) {
                $appliedInline++;
                $appliedInlineLinks[] = $this->linkMeta($suggestion);
                $this->markApplied($suggestion, $autoMode);
            }
        }

        $appliedFooter = 0;

        $updatedHtml = $this->normalizer->normalize(trim($this->innerHtml($body)), [
            'context' => 'content_chain_links',
            'content_id' => (string) $content->id,
            'inline_links_active' => $appliedInline > 0,
        ]);

        if ($updatedHtml !== '') {
            $meta = [
                'inline_links_applied' => $appliedInline > 0,
                'internal_links_applied_at' => now()->toIso8601String(),
                'inserted_inline_links' => $appliedInlineLinks,
            ];

            $latestDraft = Draft::query()
                ->where('content_id', (string) $content->id)
                ->latest('created_at')
                ->first();

            if ($latestDraft instanceof Draft) {
                $latestDraft->update([
                    'content_html' => $updatedHtml,
                    'meta' => array_merge((array) ($latestDraft->meta ?? []), $meta),
                ]);
            }

            if ($content->currentRevision) {
                $content->currentRevision->update([
                    'content_html' => $updatedHtml,
                    'meta' => array_merge((array) ($content->currentRevision->meta ?? []), $meta),
                ]);
            }

            if ($content->currentVersion) {
                $content->currentVersion->update([
                    'body' => $updatedHtml,
                    'meta' => array_merge((array) ($content->currentVersion->meta ?? []), $meta),
                ]);
            }

            $content->forceFill([
                'internal_links_meta' => array_merge((array) ($content->internal_links_meta ?? []), $meta),
            ])->save();

            RebuildContentMarkdownArtifactJob::dispatch((string) $content->id, force: true)->afterCommit();
            $this->cacheInvalidation->invalidateContent($content->fresh(), 'content.contextual_links_applied');
        }

        return [
            'applied_inline' => $appliedInline,
            'applied_footer' => $appliedFooter,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function linkMeta(ContentChainSuggestion $suggestion): array
    {
        return [
            'target_content_id' => (string) ($suggestion->target_content_id ?? ''),
            'target_url' => trim((string) data_get($suggestion->meta, 'target_url', $suggestion->targetContent?->published_url)),
            'anchor_text' => trim((string) $suggestion->anchor_text),
            'title' => trim((string) ($suggestion->targetContent?->title ?: $suggestion->title)),
        ];
    }

    /**
     * @param array<int,string> $usedTargets
     */
    private function applyInlineSuggestion(
        DOMDocument $document,
        DOMElement $body,
        ContentChainSuggestion $suggestion,
        array &$usedTargets
    ): bool {
        $anchorText = trim((string) $suggestion->anchor_text);
        $url = trim((string) data_get($suggestion->meta, 'target_url', $suggestion->targetContent?->published_url));
        $targetId = (string) ($suggestion->target_content_id ?? '');

        if ($anchorText === '' || $url === '' || in_array($targetId, $usedTargets, true)) {
            return false;
        }

        $textNodes = [];
        $this->collectEligibleTextNodes($body, $textNodes);

        foreach ($textNodes as $textNode) {
            $text = (string) $textNode->nodeValue;
            if (preg_match('/(?<![\pL\pN])' . preg_quote($anchorText, '/') . '(?![\pL\pN])/iu', $text, $matches, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $matchText = (string) ($matches[0][0] ?? '');
            $offset = (int) ($matches[0][1] ?? -1);
            if ($matchText === '' || $offset < 0) {
                continue;
            }

            $before = mb_substr($text, 0, $offset);
            $after = mb_substr($text, $offset + mb_strlen($matchText));
            $fragment = $document->createDocumentFragment();

            if ($before !== '') {
                $fragment->appendChild(new DOMText($before));
            }

            $anchor = $document->createElement('a');
            $anchor->setAttribute('href', $url);
            $anchor->appendChild(new DOMText($matchText));
            $fragment->appendChild($anchor);

            if ($after !== '') {
                $fragment->appendChild(new DOMText($after));
            }

            $textNode->parentNode?->replaceChild($fragment, $textNode);
            $usedTargets[] = $targetId;

            return true;
        }

        return false;
    }

    /**
     * @param array<int,string> $usedTargets
     */
    private function appendFooterSection(
        DOMDocument $document,
        DOMElement $body,
        Collection $suggestions,
        array &$usedTargets,
        bool $autoMode
    ): int {
        if ($suggestions->isEmpty()) {
            return 0;
        }

        $heading = (string) config('content_chain.inline_links.footer_heading', 'Further reading');
        $section = $document->createElement('section');
        $section->setAttribute('data-content-chain-links', '1');
        $title = $document->createElement('h3', $heading);
        $list = $document->createElement('ul');
        $count = 0;

        foreach ($suggestions as $suggestion) {
            $url = trim((string) data_get($suggestion->meta, 'target_url', $suggestion->targetContent?->published_url));
            $label = trim((string) ($suggestion->targetContent?->title ?: $suggestion->title));
            $targetId = (string) ($suggestion->target_content_id ?? '');

            if ($url === '' || $label === '' || in_array($targetId, $usedTargets, true)) {
                continue;
            }

            $item = $document->createElement('li');
            $anchor = $document->createElement('a', $label);
            $anchor->setAttribute('href', $url);
            $item->appendChild($anchor);
            $list->appendChild($item);

            $usedTargets[] = $targetId;
            $count++;
            $this->markApplied($suggestion, $autoMode);
        }

        if ($count === 0) {
            return 0;
        }

        $section->appendChild($title);
        $section->appendChild($list);
        $body->appendChild($section);

        return $count;
    }

    private function markApplied(ContentChainSuggestion $suggestion, bool $autoMode): void
    {
        $attributes = [
            'applied_at' => now(),
        ];

        if ($autoMode) {
            $attributes['status'] = ContentChainSuggestion::STATUS_AUTO_APPLIED;
        }

        $suggestion->update($attributes);
    }

    private function removeGeneratedFooterSection(DOMElement $body): void
    {
        for ($node = $body->firstChild; $node !== null; $node = $next) {
            $next = $node->nextSibling;

            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($node->getAttribute('data-content-chain-links') === '1') {
                $body->removeChild($node);
            }
        }
    }

    /**
     * @param array<int,DOMText> $nodes
     */
    private function collectEligibleTextNodes(DOMNode $node, array &$nodes): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && Str::lower($child->tagName) === 'a') {
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
            $html .= $element->ownerDocument?->saveHTML($child) ?? '';
        }

        return $html;
    }
}
