<?php

namespace App\Services\OnboardingScan;

use DOMDocument;
use DOMNode;
use DOMXPath;

class ContentExtractionService
{
    private const MAX_CONTENT_LENGTH = 50000;

    private const TECHNICAL_INDICATORS = [
        'wordpress' => ['wp-content', 'wp-includes', 'WordPress'],
        'shopify' => ['cdn.shopify.com', 'Shopify.theme'],
        'wix' => ['wix.com', 'wixstatic.com'],
        'squarespace' => ['squarespace.com', 'sqsp.net'],
        'webflow' => ['webflow.com', 'Webflow'],
        'react' => ['__REACT_DEVTOOLS', 'react-root', '_reactRootContainer'],
        'vue' => ['__VUE__', 'data-v-'],
        'angular' => ['ng-app', 'ng-controller', '_ngcontent'],
        'next' => ['__NEXT_DATA__', '_next/static'],
        'nuxt' => ['__NUXT__', '_nuxt'],
        'gatsby' => ['___gatsby', 'gatsby-'],
        'laravel' => ['Laravel', 'laravel_session'],
        'drupal' => ['Drupal', 'drupal-'],
        'joomla' => ['Joomla!', '/media/jui/'],
        'hubspot' => ['hs-scripts', 'hubspot'],
        'bootstrap' => ['bootstrap.min', 'bootstrap.css'],
        'tailwind' => ['tailwindcss', 'tw-'],
        'google_analytics' => ['gtag(', 'google-analytics', 'ga.js', 'analytics.js'],
        'google_tag_manager' => ['googletagmanager', 'GTM-'],
        'hotjar' => ['hotjar.com', 'hj.js'],
        'intercom' => ['intercom', 'widget.intercom.io'],
        'crisp' => ['crisp.chat', 'CRISP_WEBSITE_ID'],
        'cloudflare' => ['cloudflare', 'cf-ray'],
    ];

    /**
     * Extract content from multiple pages.
     *
     * @param  array<string, array{url: string, success: bool, html: string|null}>  $pages
     * @return array<string, array>
     */
    public function extract(array $pages): array
    {
        $results = [];

        foreach ($pages as $key => $page) {
            if (! ($page['success'] ?? false) || empty($page['html'])) {
                continue;
            }

            $results[$key] = $this->extractFromHtml($page['html'], $page['url']);
        }

        return $results;
    }

    /**
     * Extract structured content from a single HTML page.
     *
     * @return array{
     *     url: string,
     *     title: string|null,
     *     meta_description: string|null,
     *     meta_keywords: string|null,
     *     og_image: string|null,
     *     headings: array,
     *     main_content: string,
     *     word_count: int,
     *     detected_colors: array,
     *     detected_fonts: array,
     *     technical_indicators: array,
     *     links_count: int
     * }
     */
    public function extractFromHtml(string $html, string $url): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $title = $this->extractTitle($xpath);
        $metaDescription = $this->extractMetaContent($xpath, 'description');
        $metaKeywords = $this->extractMetaContent($xpath, 'keywords');
        $ogImage = $this->extractOgImage($xpath);
        $headings = $this->extractHeadings($xpath);

        // Remove non-content elements before extracting main content
        $this->stripNonContent($dom, $xpath);

        $mainContent = $this->extractMainContent($dom, $xpath);
        $wordCount = str_word_count($mainContent);

        $detectedColors = $this->detectColors($html);
        $detectedFonts = $this->detectFonts($html);
        $technicalIndicators = $this->detectTechnicalStack($html);
        $linksCount = $this->countLinks($xpath);

        return [
            'url' => $url,
            'title' => $title,
            'meta_description' => $metaDescription,
            'meta_keywords' => $metaKeywords,
            'og_image' => $ogImage,
            'headings' => $headings,
            'main_content' => mb_substr($mainContent, 0, self::MAX_CONTENT_LENGTH),
            'word_count' => $wordCount,
            'detected_colors' => $detectedColors,
            'detected_fonts' => $detectedFonts,
            'technical_indicators' => $technicalIndicators,
            'links_count' => $linksCount,
        ];
    }

    private function extractTitle(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query('//title');

        if ($nodes && $nodes->length > 0) {
            $title = trim($nodes->item(0)?->textContent ?? '');

            return $title !== '' ? $title : null;
        }

        // Fallback to og:title
        return $this->extractMetaProperty($xpath, 'og:title');
    }

    private function extractMetaContent(DOMXPath $xpath, string $name): ?string
    {
        $nodes = $xpath->query("//meta[@name='{$name}']/@content");

        if ($nodes && $nodes->length > 0) {
            $content = trim($nodes->item(0)?->nodeValue ?? '');

            return $content !== '' ? $content : null;
        }

        return null;
    }

    private function extractMetaProperty(DOMXPath $xpath, string $property): ?string
    {
        $nodes = $xpath->query("//meta[@property='{$property}']/@content");

        if ($nodes && $nodes->length > 0) {
            $content = trim($nodes->item(0)?->nodeValue ?? '');

            return $content !== '' ? $content : null;
        }

        return null;
    }

    private function extractOgImage(DOMXPath $xpath): ?string
    {
        return $this->extractMetaProperty($xpath, 'og:image');
    }

    /**
     * Extract headings hierarchy from the page.
     *
     * @return array<int, array{level: int, text: string}>
     */
    private function extractHeadings(DOMXPath $xpath): array
    {
        $headings = [];

        for ($level = 1; $level <= 3; $level++) {
            $nodes = $xpath->query("//h{$level}");

            if (! $nodes) {
                continue;
            }

            foreach ($nodes as $node) {
                $text = trim($node->textContent ?? '');

                if ($text !== '' && mb_strlen($text) < 500) {
                    $headings[] = [
                        'level' => $level,
                        'text' => $text,
                    ];
                }
            }
        }

        return array_slice($headings, 0, 50);
    }

    /**
     * Remove script, style, nav, footer, and other non-content elements.
     */
    private function stripNonContent(DOMDocument $dom, DOMXPath $xpath): void
    {
        $selectorsToRemove = [
            '//script',
            '//style',
            '//noscript',
            '//nav',
            '//header',
            '//footer',
            '//aside',
            '//form',
            '//iframe',
            '//svg',
            '//*[contains(@class, "nav")]',
            '//*[contains(@class, "menu")]',
            '//*[contains(@class, "sidebar")]',
            '//*[contains(@class, "footer")]',
            '//*[contains(@class, "header")]',
            '//*[contains(@class, "cookie")]',
            '//*[contains(@class, "popup")]',
            '//*[contains(@class, "modal")]',
            '//*[contains(@class, "advertisement")]',
            '//*[contains(@class, "ad-")]',
            '//*[contains(@id, "cookie")]',
            '//*[contains(@id, "popup")]',
            '//comment()',
        ];

        foreach ($selectorsToRemove as $selector) {
            $nodes = $xpath->query($selector);

            if (! $nodes) {
                continue;
            }

            $nodesToRemove = [];
            foreach ($nodes as $node) {
                $nodesToRemove[] = $node;
            }

            foreach ($nodesToRemove as $node) {
                if ($node->parentNode) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
    }

    /**
     * Extract main content text from the page.
     */
    private function extractMainContent(DOMDocument $dom, DOMXPath $xpath): string
    {
        // Try to find main content area
        $mainSelectors = [
            '//main',
            '//article',
            '//*[@role="main"]',
            '//*[contains(@class, "content")]',
            '//*[contains(@class, "main")]',
            '//body',
        ];

        foreach ($mainSelectors as $selector) {
            $nodes = $xpath->query($selector);

            if ($nodes && $nodes->length > 0) {
                $text = $this->extractTextFromNode($nodes->item(0));

                if (str_word_count($text) > 50) {
                    return $this->cleanText($text);
                }
            }
        }

        // Fallback to body
        $body = $xpath->query('//body');

        if ($body && $body->length > 0) {
            return $this->cleanText($this->extractTextFromNode($body->item(0)));
        }

        return '';
    }

    private function extractTextFromNode(?DOMNode $node): string
    {
        if (! $node) {
            return '';
        }

        return $node->textContent ?? '';
    }

    private function cleanText(string $text): string
    {
        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        // Remove excessive line breaks
        $text = preg_replace('/(\r?\n)+/', "\n", $text) ?? $text;
        // Trim
        $text = trim($text);

        return $text;
    }

    /**
     * Detect colors mentioned in inline styles and CSS.
     *
     * @return array<string, int>
     */
    private function detectColors(string $html): array
    {
        $colors = [];

        // Match hex colors
        if (preg_match_all('/#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})\b/', $html, $matches)) {
            foreach ($matches[0] as $color) {
                $normalized = strtolower($color);
                // Expand 3-digit hex to 6-digit
                if (strlen($normalized) === 4) {
                    $normalized = '#' . $normalized[1] . $normalized[1] . $normalized[2] . $normalized[2] . $normalized[3] . $normalized[3];
                }
                $colors[$normalized] = ($colors[$normalized] ?? 0) + 1;
            }
        }

        // Match rgb/rgba colors
        if (preg_match_all('/rgba?\s*\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $hex = sprintf('#%02x%02x%02x', (int) $match[1], (int) $match[2], (int) $match[3]);
                $colors[$hex] = ($colors[$hex] ?? 0) + 1;
            }
        }

        // Filter out common CSS defaults and sort by frequency
        $filtered = array_filter($colors, function ($count, $color) {
            // Ignore very common defaults
            return ! in_array($color, ['#000000', '#ffffff', '#fff', '#000'], true);
        }, ARRAY_FILTER_USE_BOTH);

        arsort($filtered);

        return array_slice($filtered, 0, 10, true);
    }

    /**
     * Detect font families mentioned in CSS.
     *
     * @return array<int, string>
     */
    private function detectFonts(string $html): array
    {
        $fonts = [];

        // Match font-family declarations
        if (preg_match_all('/font-family\s*:\s*([^;}]+)/i', $html, $matches)) {
            foreach ($matches[1] as $fontStack) {
                // Extract individual fonts from the stack
                $fontList = preg_split('/\s*,\s*/', $fontStack);

                if (! is_array($fontList)) {
                    continue;
                }

                foreach ($fontList as $font) {
                    $font = trim($font, " \t\n\r\0\x0B'\"");
                    $font = trim($font);

                    // Skip generic font families
                    if (in_array(strtolower($font), ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'inherit', 'initial'], true)) {
                        continue;
                    }

                    if ($font !== '' && mb_strlen($font) < 100) {
                        $fonts[$font] = ($fonts[$font] ?? 0) + 1;
                    }
                }
            }
        }

        // Also detect Google Fonts
        if (preg_match_all('/fonts\.googleapis\.com\/css[^"\']*family=([^&"\']+)/i', $html, $matches)) {
            foreach ($matches[1] as $fontParam) {
                $fontName = urldecode(explode(':', $fontParam)[0]);
                $fontName = str_replace('+', ' ', $fontName);
                $fonts[$fontName] = ($fonts[$fontName] ?? 0) + 10; // Boost Google Fonts
            }
        }

        arsort($fonts);

        return array_slice(array_keys($fonts), 0, 5);
    }

    /**
     * Detect technical stack indicators from the HTML.
     *
     * @return array<int, string>
     */
    private function detectTechnicalStack(string $html): array
    {
        $detected = [];

        foreach (self::TECHNICAL_INDICATORS as $tech => $patterns) {
            foreach ($patterns as $pattern) {
                if (stripos($html, $pattern) !== false) {
                    $detected[] = $tech;
                    break;
                }
            }
        }

        return array_values(array_unique($detected));
    }

    private function countLinks(DOMXPath $xpath): int
    {
        $nodes = $xpath->query('//a[@href]');

        return $nodes ? $nodes->length : 0;
    }
}
