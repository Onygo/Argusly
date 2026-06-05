<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Symfony\Component\HttpFoundation\Response;

class RuntimeHtmlTranslator
{
    /**
     * @var array<int, string>
     */
    private const TRANSLATABLE_ATTRIBUTES = [
        'aria-label',
        'data-sidebar-title',
        'placeholder',
        'title',
    ];

    public function shouldTranslate(Response $response): bool
    {
        if (! method_exists($response, 'getContent')) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        return $contentType === '' || str_contains(strtolower($contentType), 'text/html');
    }

    /**
     * @param  array<string, string>  $translations
     */
    public function translateResponse(Response $response, array $translations): Response
    {
        if ($translations === []) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content) || trim($content) === '') {
            return $response;
        }

        $translated = $this->translateHtml($content, $translations);
        if ($translated !== null) {
            $response->setContent($translated);
        }

        return $response;
    }

    /**
     * @param  array<string, string>  $translations
     */
    private function translateHtml(string $html, array $translations): ?string
    {
        [$html, $protectedBlocks] = $this->protectRawTextBlocks($html);

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        if (! $loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return null;
        }

        foreach ($dom->childNodes as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $dom->removeChild($node);
                break;
            }
        }

        $xpath = new DOMXPath($dom);
        $textNodes = $xpath->query('//text()[not(ancestor::script) and not(ancestor::style)]');

        if ($textNodes !== false) {
            foreach ($textNodes as $node) {
                $node->nodeValue = $this->translateText((string) $node->nodeValue, $translations);
            }
        }

        $elements = $xpath->query('//*[@aria-label or @data-sidebar-title or @placeholder or @title or @onsubmit]');
        if ($elements !== false) {
            foreach ($elements as $element) {
                if (! $element instanceof DOMElement) {
                    continue;
                }

                foreach (self::TRANSLATABLE_ATTRIBUTES as $attribute) {
                    if ($element->hasAttribute($attribute)) {
                        $element->setAttribute($attribute, $this->translateText($element->getAttribute($attribute), $translations));
                    }
                }

                if ($element->hasAttribute('onsubmit')) {
                    $element->setAttribute('onsubmit', strtr($element->getAttribute('onsubmit'), $translations));
                }
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $translated = $dom->saveHTML() ?: null;

        return $translated !== null
            ? strtr($translated, $protectedBlocks)
            : null;
    }

    /**
     * DOMDocument's HTML parser can treat modern JavaScript template-literal HTML
     * as real markup. Keep raw text blocks byte-for-byte intact while translating.
     *
     * @return array{0:string,1:array<string,string>}
     */
    private function protectRawTextBlocks(string $html): array
    {
        $blocks = [];
        $protected = preg_replace_callback(
            '/<(script|style)\b[^>]*>.*?<\/\1>/is',
            function (array $matches) use (&$blocks): string {
                $token = 'PL_RUNTIME_TRANSLATOR_BLOCK_' . count($blocks) . '_' . bin2hex(random_bytes(6));
                $blocks[$token] = $matches[0];

                return $token;
            },
            $html
        );

        return [is_string($protected) ? $protected : $html, $blocks];
    }

    /**
     * @param  array<string, string>  $translations
     */
    private function translateText(string $text, array $translations): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return $text;
        }

        $translation = $translations[$trimmed] ?? $this->translatePattern($trimmed, $translations);
        if ($translation === null) {
            return $text;
        }

        $leading = substr($text, 0, strspn($text, " \t\n\r\0\x0B"));
        $trailingLength = strspn(strrev($text), " \t\n\r\0\x0B");
        $trailing = $trailingLength > 0 ? substr($text, -$trailingLength) : '';

        return $leading . $translation . $trailing;
    }

    /**
     * @param  array<string, string>  $translations
     */
    private function translatePattern(string $text, array $translations): ?string
    {
        $patterns = collect($translations)
            ->filter(fn (string $target, string $source): bool => str_contains($source, ':'))
            ->sortByDesc(fn (string $target, string $source): int => strlen($source));

        foreach ($patterns as $source => $target) {
            if (! str_contains($source, ':')) {
                continue;
            }

            $pattern = preg_quote($source, '/');
            $pattern = preg_replace('/\\\\?:([A-Za-z_][A-Za-z0-9_]*)/', '(?P<$1>.+?)', $pattern);

            if (! is_string($pattern) || ! preg_match('/^' . $pattern . '$/u', $text, $matches)) {
                continue;
            }

            $translated = $target;
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $translated = str_replace(':' . $key, (string) $value, $translated);
                }
            }

            return $translated;
        }

        return null;
    }
}
