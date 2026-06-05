<?php

namespace App\Services\Content;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

class ContentRenderer
{
    /**
     * @var array<int,string>
     */
    private const ALLOWED_TAGS = [
        'p',
        'h1',
        'h2',
        'h3',
        'h4',
        'ul',
        'ol',
        'li',
        'blockquote',
        'strong',
        'em',
        'a',
        'hr',
        'code',
        'pre',
        'table',
        'thead',
        'tbody',
        'tr',
        'th',
        'td',
        'br',
    ];

    /**
     * @var array<int,string>
     */
    private const DROP_WITH_CONTENT_TAGS = [
        'script',
        'style',
        'iframe',
        'object',
        'embed',
        'form',
        'input',
        'button',
        'textarea',
        'select',
        'meta',
        'link',
        'svg',
    ];

    private MarkdownConverter $markdownConverter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new AutolinkExtension());
        $environment->addExtension(new TableExtension());

        $this->markdownConverter = new MarkdownConverter($environment);
    }

    public function renderToHtml(?string $content): HtmlString
    {
        $normalized = $this->normalizeInput($content);
        if ($normalized === '') {
            return new HtmlString('');
        }

        if ($this->containsHtml($normalized) && ! $this->containsMarkdownSyntax($normalized)) {
            return new HtmlString($this->sanitizeHtml($normalized));
        }

        if ($this->looksLikePlainText($normalized)) {
            return new HtmlString($this->sanitizeHtml($this->paragraphizePlainText($normalized)));
        }

        $html = (string) $this->markdownConverter->convert($normalized);

        return new HtmlString($this->sanitizeHtml($html));
    }

    public function sanitizeHtmlFragment(?string $html): HtmlString
    {
        $normalized = $this->normalizeInput($html);
        if ($normalized === '') {
            return new HtmlString('');
        }

        return new HtmlString($this->sanitizeHtml($normalized));
    }

    private function normalizeInput(?string $content): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", (string) $content);
        $normalized = $this->repairCommonMojibake($normalized);

        return trim($normalized);
    }

    private function repairCommonMojibake(string $content): string
    {
        if (! str_contains($content, "\xC3\xA2\xC2\x80")) {
            return str_replace(["\xC3\x82\xC2\xA0", "\xC2\xA0"], ' ', $content);
        }

        $search = [
            "\xC3\xA2\xC2\x80\xC2\x98",
            "\xC3\xA2\xC2\x80\xC2\x99",
            "\xC3\xA2\xC2\x80\xC2\x9C",
            "\xC3\xA2\xC2\x80\xC2\x9D",
            "\xC3\xA2\xC2\x80\xC2\x93",
            "\xC3\xA2\xC2\x80\xC2\x94",
            "\xC3\xA2\xC2\x80\xC2\xA6",
            "\xC3\xA2\xC2\x80\xC2\xA2",
            "\xC3\x82\xC2\xA0",
            "\xC2\xA0",
        ];

        $replace = [
            "\xE2\x80\x98",
            "\xE2\x80\x99",
            "\xE2\x80\x9C",
            "\xE2\x80\x9D",
            "\xE2\x80\x93",
            "\xE2\x80\x94",
            "\xE2\x80\xA6",
            "\xE2\x80\xA2",
            ' ',
            ' ',
        ];

        return str_replace($search, $replace, $content);
    }

    private function containsHtml(string $content): bool
    {
        return (bool) preg_match('/<\s*\/?\s*[a-z][^>]*>/i', $content);
    }

    private function containsMarkdownSyntax(string $content): bool
    {
        return (bool) preg_match(
            '/(^\s{0,3}(#{1,6}\s+|[-*+]\s+|\d+\.\s+|>\s+|```|~~~|\|.*\|)|\[[^\]]+\]\([^)]+\)|(^|\W)(\*\*[^*\n]+\*\*|__[^_\n]+__|\*[^*\n]+\*|_[^_\n]+_)|^\s{0,3}(-{3,}|\*{3,}|_{3,})\s*$)/m',
            $content
        );
    }

    private function looksLikePlainText(string $content): bool
    {
        return ! $this->containsHtml($content) && ! $this->containsMarkdownSyntax($content);
    }

    private function paragraphizePlainText(string $content): string
    {
        $paragraphs = preg_split('/\n{2,}/', $content) ?: [];

        $html = collect($paragraphs)
            ->map(static function (string $paragraph): ?string {
                $trimmed = trim($paragraph);
                if ($trimmed === '') {
                    return null;
                }

                return '<p>' . nl2br(e($trimmed)) . '</p>';
            })
            ->filter()
            ->implode("\n");

        return $html;
    }

    private function sanitizeHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $document->getElementsByTagName('body')->item(0);
        if (! $body instanceof DOMElement) {
            return '';
        }

        $this->sanitizeChildNodes($body);

        return trim($this->innerHtml($body));
    }

    private function sanitizeChildNodes(DOMNode $parent): void
    {
        for ($node = $parent->firstChild; $node !== null; $node = $next) {
            $next = $node->nextSibling;

            if (! $node instanceof DOMElement) {
                continue;
            }

            $this->sanitizeElement($node);
        }
    }

    private function sanitizeElement(DOMElement $element): void
    {
        $tag = strtolower($element->tagName);

        if (in_array($tag, self::DROP_WITH_CONTENT_TAGS, true)) {
            $element->parentNode?->removeChild($element);

            return;
        }

        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            $this->sanitizeChildNodes($element);
            $this->unwrapElement($element);

            return;
        }

        $this->sanitizeAttributes($element, $tag);
        $this->sanitizeChildNodes($element);
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowedAttributes = $tag === 'a' ? ['href', 'title'] : [];

        $attributeNames = [];
        foreach ($element->attributes as $attribute) {
            $attributeNames[] = $attribute->name;
        }

        foreach ($attributeNames as $name) {
            $normalizedName = strtolower($name);

            if (str_starts_with($normalizedName, 'on') || $normalizedName === 'style') {
                $element->removeAttribute($name);

                continue;
            }

            if (! in_array($normalizedName, $allowedAttributes, true)) {
                $element->removeAttribute($name);
            }
        }

        if ($tag !== 'a') {
            return;
        }

        $href = trim((string) $element->getAttribute('href'));
        if ($href === '' || ! $this->isSafeHref($href)) {
            $element->removeAttribute('href');
            $element->removeAttribute('target');
            $element->removeAttribute('rel');

            return;
        }

        $element->setAttribute('href', $href);

        if ($this->isExternalHttpUrl($href)) {
            $element->setAttribute('target', '_blank');
            $element->setAttribute('rel', 'nofollow noopener noreferrer');
        } else {
            $element->removeAttribute('target');
            $element->removeAttribute('rel');
        }
    }

    private function isSafeHref(string $href): bool
    {
        $decoded = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($decoded === '') {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F]/u', $decoded)) {
            return false;
        }

        if (
            str_starts_with($decoded, '#')
            || str_starts_with($decoded, '/')
            || str_starts_with($decoded, './')
            || str_starts_with($decoded, '../')
        ) {
            return true;
        }

        $scheme = parse_url($decoded, PHP_URL_SCHEME);
        if ($scheme === null) {
            return ! str_contains($decoded, ':');
        }

        return in_array(strtolower($scheme), ['http', 'https', 'mailto', 'tel'], true);
    }

    private function isExternalHttpUrl(string $href): bool
    {
        $decoded = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (str_starts_with($decoded, '//')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($decoded, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) parse_url($decoded, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($appHost === '') {
            return true;
        }

        return $host !== $appHost;
    }

    private function unwrapElement(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if (! $parent) {
            return;
        }

        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private function innerHtml(DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $rendered = $element->ownerDocument?->saveHTML($child);
            if ($rendered !== false) {
                $html .= $rendered;
            }
        }

        return $html;
    }
}
