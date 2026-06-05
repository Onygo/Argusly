<?php

namespace App\Services\Markdown;

use App\Models\Content;
use App\Services\Content\AnswerBlockInjectorService;
use App\Services\Content\AnswerBlockSchemaService;
use App\Services\Content\ContentRenderer;
use App\Support\SeoMetadata;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class MarkdownRenderer
{
    /**
     * @var array<int, string>
     */
    private const REMOVABLE_TAGS = [
        'script',
        'style',
        'iframe',
        'noscript',
        'form',
        'button',
        'input',
        'textarea',
        'select',
        'nav',
        'aside',
        'footer',
    ];

    /**
     * @var array<int, string>
     */
    private const UI_KEYWORDS = [
        'nav',
        'menu',
        'cookie',
        'consent',
        'sidebar',
        'related',
        'widget',
        'newsletter',
        'signup',
        'tracking',
        'admin',
        'toolbar',
        'breadcrumb',
        'share',
        'social',
        'pagination',
    ];

    public function __construct(
        private readonly ContentRenderer $contentRenderer,
        private readonly MarkdownEligibilityService $eligibility,
        private readonly AnswerBlockInjectorService $answerBlockInjector,
        private readonly AnswerBlockSchemaService $answerBlockSchema,
    ) {}

    /**
     * @return array{
     *     locale:string,
     *     source:string,
     *     rendered_html:string,
     *     rendered_markdown:string,
     *     excerpt:?string,
     *     meta:array<string,mixed>
     * }
     */
    public function render(Content $content, ?string $locale = null): array
    {
        $content->loadMissing(['workspace', 'currentRevision', 'currentVersion', 'seo', 'teamMember', 'answerBlocks']);

        $resolvedLocale = $this->eligibility->resolveLocale($content, $locale);
        $source = $this->resolveSourceSnapshot($content);
        $bodyHtml = $this->normalizeBodyHtml(
            $source['html'],
            trim((string) $content->title)
        );
        $bodyMarkdown = $this->convertBodyHtmlToMarkdown($bodyHtml);
        $excerpt = $this->resolveExcerpt($content, $bodyMarkdown, $bodyHtml);
        $answerSection = $this->buildAnswerSection($content);
        $keyQuestionsSection = $this->buildKeyQuestionsSection($content);
        $metadataSection = $this->buildMetadataSection($content, $resolvedLocale);
        $faqSection = $this->buildFaqSection($content, $bodyMarkdown);
        $ctaSection = $this->buildCtaSection($content, $bodyMarkdown);
        $seoSection = $this->buildSeoSection($content);

        $sections = array_values(array_filter([
            '# ' . $this->sanitizeInlineText((string) ($content->title ?: 'Untitled')),
            $excerpt,
            $answerSection,
            $keyQuestionsSection,
            $metadataSection,
            $bodyMarkdown,
            $faqSection,
            $ctaSection,
            $seoSection,
        ], static fn (?string $section) => trim((string) $section) !== ''));

        $markdown = $this->normalizeMarkdown(implode("\n\n", $sections));
        $html = $this->answerBlockInjector->inject($bodyHtml, $content);

        return [
            'locale' => $resolvedLocale,
            'source' => $source['source'],
            'rendered_html' => trim($html),
            'rendered_markdown' => $markdown,
            'excerpt' => $excerpt,
            'meta' => [
                'body_source' => $source['source'],
                'has_faq' => $faqSection !== '',
                'has_cta' => $ctaSection !== '',
                'publish_date' => $this->resolvePublishDate($content)?->toDateString(),
                'author' => $this->resolveAuthorLine($content),
                'faq_schema' => $this->answerBlockSchema->forContent($content),
            ],
        ];
    }

    /**
     * @return array{html:string,source:string}
     */
    private function resolveSourceSnapshot(Content $content): array
    {
        $revisionHtml = trim((string) optional($content->currentRevision)->content_html);
        if ($revisionHtml !== '') {
            return [
                'html' => $revisionHtml,
                'source' => 'current_revision',
            ];
        }

        $versionHtml = trim((string) optional($content->currentVersion)->body);

        return [
            'html' => $versionHtml,
            'source' => $versionHtml !== '' ? 'current_version' : 'rebuild',
        ];
    }

    private function normalizeBodyHtml(?string $html, string $title): string
    {
        $normalized = trim((string) $html);
        if ($normalized === '') {
            return '';
        }

        $document = $this->loadDocument($normalized);
        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return '';
        }

        $this->removeUiArtifacts($body);

        /** @var HtmlString $sanitized */
        $sanitized = $this->contentRenderer->sanitizeHtmlFragment($this->innerHtml($body));

        $sanitizedDocument = $this->loadDocument((string) $sanitized);
        $sanitizedBody = $sanitizedDocument->getElementsByTagName('body')->item(0);

        if (! $sanitizedBody instanceof DOMElement) {
            return '';
        }

        $normalizedTitle = $this->normalizeComparableText($title);

        for ($node = $sanitizedBody->firstChild; $node !== null; $node = $next) {
            $next = $node->nextSibling;

            if (! $node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if ($tag === 'h1' && $normalizedTitle !== '' && $this->normalizeComparableText($node->textContent) === $normalizedTitle) {
                $sanitizedBody->removeChild($node);

                continue;
            }
        }

        $hasBodyH1 = $sanitizedDocument->getElementsByTagName('h1')->length > 0;
        if ($hasBodyH1) {
            for ($node = $sanitizedBody->firstChild; $node !== null; $node = $next) {
                $next = $node->nextSibling;

                if (! $node instanceof DOMElement) {
                    continue;
                }

                $tag = strtolower($node->tagName);
                if (preg_match('/^h([1-6])$/', $tag, $matches) !== 1) {
                    continue;
                }

                $level = min(6, ((int) $matches[1]) + 1);
                if ($level === (int) $matches[1]) {
                    continue;
                }

                $renamed = $sanitizedDocument->createElement('h' . $level);
                while ($node->firstChild) {
                    $renamed->appendChild($node->firstChild);
                }
                $sanitizedBody->replaceChild($renamed, $node);
            }
        }

        return trim($this->innerHtml($sanitizedBody));
    }

    private function removeUiArtifacts(DOMNode $parent): void
    {
        for ($node = $parent->firstChild; $node !== null; $node = $next) {
            $next = $node->nextSibling;

            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($this->shouldRemoveElement($node)) {
                $parent->removeChild($node);

                continue;
            }

            $this->removeUiArtifacts($node);
        }
    }

    private function shouldRemoveElement(DOMElement $element): bool
    {
        $tag = strtolower($element->tagName);

        if (in_array($tag, self::REMOVABLE_TAGS, true)) {
            return true;
        }

        $attributes = collect([
            $element->getAttribute('class'),
            $element->getAttribute('id'),
            $element->getAttribute('role'),
            $element->getAttribute('aria-label'),
            $element->getAttribute('data-testid'),
            $element->getAttribute('data-component'),
        ])
            ->filter(fn (string $value) => trim($value) !== '')
            ->implode(' ');

        if ($attributes === '') {
            return false;
        }

        $haystack = Str::lower($attributes);

        foreach (self::UI_KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function buildAnswerSection(Content $content): string
    {
        $firstBlock = $content->answerBlocks->first();
        if (! $firstBlock) {
            return '';
        }

        return implode("\n\n", [
            '## Answer',
            trim((string) $firstBlock->answer),
        ]);
    }

    private function buildKeyQuestionsSection(Content $content): string
    {
        if ($content->answerBlocks->isEmpty()) {
            return '';
        }

        $lines = ['## Key Questions'];

        foreach ($content->answerBlocks as $block) {
            $lines[] = '### ' . $this->sanitizeInlineText((string) $block->question);
            $lines[] = trim((string) $block->answer);
        }

        return implode("\n\n", $lines);
    }

    private function convertBodyHtmlToMarkdown(string $html): string
    {
        $normalized = trim($html);
        if ($normalized === '') {
            return '';
        }

        $document = $this->loadDocument($normalized);
        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return '';
        }

        $markdown = $this->renderBlockChildren($body);

        return $this->normalizeMarkdown($markdown);
    }

    private function resolveExcerpt(Content $content, string $bodyMarkdown, string $bodyHtml): ?string
    {
        $metaExcerpt = SeoMetadata::firstNonEmpty([
            data_get($content->currentRevision?->meta, 'excerpt'),
            data_get($content->currentVersion?->meta, 'excerpt'),
            $content->seo_meta_description,
            data_get($content->seo, 'meta_description'),
        ]);

        $candidate = $metaExcerpt ?: $this->firstParagraph($bodyMarkdown, $bodyHtml);
        if ($candidate === null) {
            return null;
        }

        $normalizedCandidate = trim($candidate);
        if ($normalizedCandidate === '') {
            return null;
        }

        $title = trim((string) $content->title);
        if ($title !== '' && $this->normalizeComparableText($normalizedCandidate) === $this->normalizeComparableText($title)) {
            return null;
        }

        return Str::limit($normalizedCandidate, 320, '');
    }

    private function firstParagraph(string $bodyMarkdown, string $bodyHtml): ?string
    {
        foreach (preg_split("/\n{2,}/", trim($bodyMarkdown)) ?: [] as $block) {
            $trimmed = trim((string) $block);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || str_contains($trimmed, '|')) {
                continue;
            }

            return trim(preg_replace('/\s+/u', ' ', $trimmed) ?? '');
        }

        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($bodyHtml)) ?? '');

        return $text !== '' ? Str::limit($text, 320, '') : null;
    }

    private function buildMetadataSection(Content $content, string $locale): string
    {
        $lines = [
            '- Locale: ' . $locale,
        ];

        $publishDate = $this->resolvePublishDate($content);
        if ($publishDate) {
            $lines[] = '- Published: ' . $publishDate->toDateString();
        }

        $author = $this->resolveAuthorLine($content);
        if ($author !== null) {
            $lines[] = '- Author: ' . $author;
        }

        return implode("\n", $lines);
    }

    private function buildFaqSection(Content $content, string $bodyMarkdown): string
    {
        $faqItems = $this->extractFaqItems($content);
        if ($faqItems === []) {
            return '';
        }

        $bodyFingerprint = $this->normalizeComparableText($bodyMarkdown);
        $sections = ['## Frequently Asked Questions'];

        foreach ($faqItems as $item) {
            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));

            if ($question === '' || $answer === '') {
                continue;
            }

            if (str_contains($bodyFingerprint, $this->normalizeComparableText($question))) {
                continue;
            }

            $sections[] = '### ' . $this->sanitizeInlineText($question);
            $sections[] = $this->convertFragmentToMarkdown($answer);
        }

        return count($sections) > 1 ? implode("\n\n", $sections) : '';
    }

    private function buildCtaSection(Content $content, string $bodyMarkdown): string
    {
        $cta = $this->extractCta($content);
        if ($cta === null) {
            return '';
        }

        $title = trim((string) ($cta['title'] ?? 'Call to Action'));
        $body = trim((string) ($cta['body'] ?? ''));
        $label = trim((string) ($cta['label'] ?? ''));
        $url = trim((string) ($cta['url'] ?? ''));

        if ($body === '' && $label === '' && $url === '') {
            return '';
        }

        if ($body !== '' && str_contains($this->normalizeComparableText($bodyMarkdown), $this->normalizeComparableText($body))) {
            return '';
        }

        $lines = ['## ' . $this->sanitizeInlineText($title)];

        if ($body !== '') {
            $lines[] = $this->convertFragmentToMarkdown($body);
        }

        if ($label !== '' && $url !== '') {
            $lines[] = '[' . $this->sanitizeInlineText($label) . '](' . $url . ')';
        } elseif ($label !== '') {
            $lines[] = $this->sanitizeInlineText($label);
        } elseif ($url !== '') {
            $lines[] = $url;
        }

        return implode("\n\n", array_filter($lines, static fn (string $line) => trim($line) !== ''));
    }

    private function buildSeoSection(Content $content): string
    {
        $seo = SeoMetadata::resolveForContentContext($content);
        $lines = [];

        if (! empty($seo['seo_title'])) {
            $lines[] = '- SEO title: ' . $this->sanitizeInlineText((string) $seo['seo_title']);
        }

        if (! empty($seo['seo_meta_description'])) {
            $lines[] = '- Meta description: ' . $this->sanitizeInlineText((string) $seo['seo_meta_description']);
        }

        if (! empty($seo['primary_keyword'])) {
            $lines[] = '- Primary keyword: ' . $this->sanitizeInlineText((string) $seo['primary_keyword']);
        }

        if ($lines === []) {
            return '';
        }

        array_unshift($lines, '## SEO Metadata');

        return implode("\n", $lines);
    }

    /**
     * @return array<int, array{question:string,answer:string}>
     */
    private function extractFaqItems(Content $content): array
    {
        $sources = [
            data_get($content->currentRevision?->meta, 'faq'),
            data_get($content->currentRevision?->meta, 'faqs'),
            data_get($content->currentVersion?->meta, 'faq'),
            data_get($content->currentVersion?->meta, 'faqs'),
            data_get($content->currentVersion?->meta, 'faq_items'),
            data_get($content->currentVersion?->meta, 'questions'),
        ];

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $items = collect($source)
                ->map(function ($item): ?array {
                    if (! is_array($item)) {
                        return null;
                    }

                    $question = SeoMetadata::firstNonEmpty([
                        $item['question'] ?? null,
                        $item['q'] ?? null,
                        $item['title'] ?? null,
                    ]);
                    $answer = SeoMetadata::firstNonEmpty([
                        $item['answer'] ?? null,
                        $item['a'] ?? null,
                        $item['body'] ?? null,
                        $item['text'] ?? null,
                    ]);

                    if ($question === null || $answer === null) {
                        return null;
                    }

                    return [
                        'question' => $question,
                        'answer' => $answer,
                    ];
                })
                ->filter()
                ->values()
                ->all();

            if ($items !== []) {
                return $items;
            }
        }

        return [];
    }

    /**
     * @return array{title:?string,body:?string,label:?string,url:?string}|null
     */
    private function extractCta(Content $content): ?array
    {
        $sources = [
            data_get($content->currentRevision?->meta, 'call_to_action'),
            data_get($content->currentRevision?->meta, 'cta'),
            data_get($content->currentVersion?->meta, 'call_to_action'),
            data_get($content->currentVersion?->meta, 'cta'),
        ];

        foreach ($sources as $source) {
            if (is_string($source) && trim($source) !== '') {
                return [
                    'title' => 'Call to Action',
                    'body' => trim($source),
                    'label' => null,
                    'url' => null,
                ];
            }

            if (! is_array($source)) {
                continue;
            }

            $body = SeoMetadata::firstNonEmpty([
                $source['body'] ?? null,
                $source['text'] ?? null,
                $source['description'] ?? null,
            ]);
            $label = SeoMetadata::firstNonEmpty([
                $source['label'] ?? null,
                $source['cta_label'] ?? null,
                $source['button_text'] ?? null,
            ]);
            $url = SeoMetadata::firstNonEmpty([
                $source['url'] ?? null,
                $source['cta_url'] ?? null,
                $source['href'] ?? null,
            ]);

            if ($body === null && $label === null && $url === null) {
                continue;
            }

            return [
                'title' => SeoMetadata::firstNonEmpty([$source['title'] ?? null, $source['heading'] ?? null]) ?? 'Call to Action',
                'body' => $body,
                'label' => $label,
                'url' => $url,
            ];
        }

        return null;
    }

    private function resolvePublishDate(Content $content): ?Carbon
    {
        $candidates = [
            data_get($content->currentRevision?->meta, 'published_at'),
            data_get($content->currentVersion?->meta, 'published_at'),
            $content->scheduled_publish_at,
            ($content->publish_status === 'published') ? $content->updated_at : null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate instanceof Carbon) {
                return $candidate;
            }

            $value = trim((string) $candidate);
            if ($value === '') {
                continue;
            }

            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function resolveAuthorLine(Content $content): ?string
    {
        if ($content->teamMember) {
            $parts = array_filter([
                trim((string) $content->teamMember->name),
                trim((string) $content->teamMember->role),
            ]);

            return $parts !== [] ? implode(', ', $parts) : null;
        }

        return SeoMetadata::firstNonEmpty([
            data_get($content->currentRevision?->meta, 'author.name'),
            data_get($content->currentVersion?->meta, 'author.name'),
            data_get($content->currentRevision?->meta, 'author'),
            data_get($content->currentVersion?->meta, 'author'),
        ]);
    }

    private function convertFragmentToMarkdown(string $html): string
    {
        $sanitized = (string) $this->contentRenderer->sanitizeHtmlFragment($html);

        return $this->convertBodyHtmlToMarkdown($sanitized);
    }

    private function renderBlockChildren(DOMNode $parent, int $listDepth = 0): string
    {
        $output = '';

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text = $this->sanitizeInlineText($child->textContent ?? '');
                if ($text !== '') {
                    $output .= $text . "\n\n";
                }

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            $output .= match ($tag) {
                'p' => $this->renderInlineChildren($child) . "\n\n",
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => str_repeat('#', (int) substr($tag, 1)) . ' ' . $this->renderInlineChildren($child) . "\n\n",
                'ul' => $this->renderList($child, false, $listDepth),
                'ol' => $this->renderList($child, true, $listDepth),
                'blockquote' => $this->renderBlockquote($child),
                'pre' => $this->renderPreformatted($child),
                'table' => $this->renderTable($child),
                'hr' => "---\n\n",
                'br' => "\n",
                default => $this->renderFallbackElement($child, $listDepth),
            };
        }

        return $output;
    }

    private function renderInlineChildren(DOMNode $parent): string
    {
        $parts = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text = $this->sanitizeInlineText($child->textContent ?? '');
                if ($text !== '') {
                    $parts[] = $text;
                }

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            $content = match ($tag) {
                'strong', 'b' => '**' . $this->renderInlineChildren($child) . '**',
                'em', 'i' => '*' . $this->renderInlineChildren($child) . '*',
                'code' => '`' . trim($child->textContent ?? '') . '`',
                'a' => $this->renderLink($child),
                'br' => "\n",
                default => $this->renderInlineChildren($child),
            };

            if (trim($content) !== '') {
                $parts[] = $content;
            }
        }

        $joined = implode(' ', $parts);
        $joined = preg_replace('/ +\n/u', "\n", $joined) ?? $joined;
        $joined = preg_replace('/\n +/u', "\n", $joined) ?? $joined;

        return trim(preg_replace('/[ \t]{2,}/u', ' ', $joined) ?? $joined);
    }

    private function renderLink(DOMElement $element): string
    {
        $label = $this->renderInlineChildren($element);
        $href = trim((string) $element->getAttribute('href'));

        if ($href === '') {
            return $label;
        }

        return '[' . ($label !== '' ? $label : $href) . '](' . $href . ')';
    }

    private function renderList(DOMElement $list, bool $ordered, int $depth): string
    {
        $lines = [];
        $index = 1;

        foreach ($list->childNodes as $child) {
            if (! $child instanceof DOMElement || strtolower($child->tagName) !== 'li') {
                continue;
            }

            $inlineSegments = [];
            $nestedSegments = [];

            foreach ($child->childNodes as $liChild) {
                if ($liChild instanceof DOMText) {
                    $text = $this->sanitizeInlineText($liChild->textContent ?? '');
                    if ($text !== '') {
                        $inlineSegments[] = $text;
                    }

                    continue;
                }

                if (! $liChild instanceof DOMElement) {
                    continue;
                }

                $tag = strtolower($liChild->tagName);
                if (in_array($tag, ['ul', 'ol'], true)) {
                    $nestedSegments[] = rtrim($this->renderList($liChild, $tag === 'ol', $depth + 1));

                    continue;
                }

                if (in_array($tag, ['p', 'div'], true)) {
                    $inline = $this->renderInlineChildren($liChild);
                    if ($inline !== '') {
                        $inlineSegments[] = $inline;
                    }

                    continue;
                }

                $inline = $this->renderInlineChildren($liChild);
                if ($inline !== '') {
                    $inlineSegments[] = $inline;
                }
            }

            $prefix = $ordered ? $index . '. ' : '- ';
            $indent = str_repeat('    ', $depth);
            $line = $indent . $prefix . trim(implode(' ', $inlineSegments));
            $line = rtrim($line);

            if ($line !== $indent . $prefix) {
                $lines[] = $line;
            }

            foreach ($nestedSegments as $nested) {
                if ($nested !== '') {
                    $lines[] = $nested;
                }
            }

            $index++;
        }

        return implode("\n", $lines) . "\n\n";
    }

    private function renderBlockquote(DOMElement $blockquote): string
    {
        $content = trim($this->renderBlockChildren($blockquote));
        if ($content === '') {
            return '';
        }

        $lines = collect(explode("\n", $content))
            ->map(fn (string $line) => $line !== '' ? '> ' . $line : '>')
            ->implode("\n");

        return $lines . "\n\n";
    }

    private function renderPreformatted(DOMElement $pre): string
    {
        $content = rtrim((string) $pre->textContent);

        return $content === '' ? '' : "```\n{$content}\n```\n\n";
    }

    private function renderTable(DOMElement $table): string
    {
        $rows = [];

        foreach ($table->getElementsByTagName('tr') as $row) {
            $cells = [];
            foreach ($row->childNodes as $cell) {
                if (! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), ['th', 'td'], true)) {
                    continue;
                }

                $cells[] = str_replace('|', '\|', $this->renderInlineChildren($cell));
            }

            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return '';
        }

        $header = $rows[0];
        $bodyRows = array_slice($rows, 1);

        if ($bodyRows === []) {
            $bodyRows = [$header];
        }

        $lines = [
            '| ' . implode(' | ', $header) . ' |',
            '| ' . implode(' | ', array_fill(0, count($header), '---')) . ' |',
        ];

        foreach ($bodyRows as $row) {
            if (count($row) < count($header)) {
                $row = array_pad($row, count($header), '');
            }

            $lines[] = '| ' . implode(' | ', array_slice($row, 0, count($header))) . ' |';
        }

        return implode("\n", $lines) . "\n\n";
    }

    private function renderFallbackElement(DOMElement $element, int $listDepth): string
    {
        $tag = strtolower($element->tagName);

        if (in_array($tag, ['div', 'section', 'article', 'main', 'thead', 'tbody', 'tr', 'td', 'th'], true)) {
            return $this->renderBlockChildren($element, $listDepth);
        }

        $inline = $this->renderInlineChildren($element);

        return $inline !== '' ? $inline . "\n\n" : '';
    }

    private function normalizeMarkdown(string $markdown): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($markdown));
        $normalized = preg_replace('/[ \t]+$/m', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/^\* /m', '- ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\n{3,}/', "\n\n", $normalized) ?? $normalized;
        $normalized = preg_replace('/^(#{1,6}[^\n]*)(?=\S)/m', "$1", $normalized) ?? $normalized;

        $lines = collect(explode("\n", $normalized))
            ->map(function (string $line): string {
                if (preg_match('/^(#{1,6})\s*(.+)$/', $line, $matches) === 1) {
                    return $matches[1] . ' ' . trim($matches[2]);
                }

                if (preg_match('/^-\s+/', $line) === 1) {
                    return '- ' . trim(substr($line, 1));
                }

                return rtrim($line);
            })
            ->all();

        return trim(implode("\n", $lines));
    }

    private function sanitizeInlineText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
    }

    private function normalizeComparableText(?string $text): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)) ?? ''));
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

    private function innerHtml(DOMNode $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?: '';
        }

        return $html;
    }
}
