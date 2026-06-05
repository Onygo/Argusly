<?php

namespace App\Services\AiDiscovery;

class PublicBlogMarkdownService
{
    /**
     * @param array<string, mixed> $post
     */
    public function render(array $post): string
    {
        $title = trim((string) ($post['title'] ?? 'Untitled'));
        $excerpt = trim((string) ($post['excerpt'] ?? ''));
        $body = $this->resolveBodyMarkdown($post);

        $lines = [];

        if (! $this->bodyStartsWithTitle($body, $title)) {
            $lines[] = '# ' . $title;
        }

        if ($excerpt !== '' && ! $this->bodyContainsSnippet($body, $excerpt)) {
            $lines[] = $excerpt;
        }

        if ($body !== '') {
            $lines[] = $body;
        }

        return trim(implode("\n\n", array_filter($lines)));
    }

    /**
     * @param array<string, mixed> $post
     */
    private function resolveBodyMarkdown(array $post): string
    {
        $markdown = trim((string) ($post['content_markdown'] ?? ''));
        if ($markdown !== '') {
            return $this->normalizeWhitespace($markdown);
        }

        $html = trim((string) ($post['content_html'] ?? ''));
        if ($html !== '') {
            return $this->normalizeWhitespace($this->convertHtmlToMarkdown($html));
        }

        $raw = trim((string) ($post['content_raw'] ?? ''));
        if ($raw !== '') {
            $format = strtolower(trim((string) ($post['content_format'] ?? 'html')));

            return $this->normalizeWhitespace(
                $format === 'markdown' ? $raw : $this->convertHtmlToMarkdown($raw)
            );
        }

        return '';
    }

    private function bodyStartsWithTitle(string $body, string $title): bool
    {
        $body = ltrim($body);
        $title = trim($title);

        return $title !== '' && str_starts_with(strtolower($body), '# ' . strtolower($title));
    }

    private function bodyContainsSnippet(string $body, string $excerpt): bool
    {
        $excerptSnippet = strtolower(trim(substr($excerpt, 0, 80)));

        return $excerptSnippet !== '' && str_contains(strtolower($body), $excerptSnippet);
    }

    private function normalizeWhitespace(string $value): string
    {
        $value = preg_replace("/\r\n?/", "\n", $value) ?? $value;
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? $value;

        return trim((string) $value);
    }

    private function convertHtmlToMarkdown(string $html): string
    {
        $html = preg_replace('/<\s*(script|style|iframe|form|nav|aside|footer|header)\b[^>]*>.*?<\s*\/\s*\\1\s*>/is', '', $html) ?? $html;

        foreach ([1, 2, 3, 4, 5, 6] as $level) {
            $html = preg_replace_callback(
                sprintf('/<h%d\b[^>]*>(.*?)<\/h%d>/is', $level, $level),
                static fn (array $matches): string => str_repeat('#', $level) . ' ' . trim(strip_tags($matches[1])) . "\n\n",
                $html
            ) ?? $html;
        }

        $html = preg_replace_callback('/<a\b[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>(.*?)<\/a>/is', static function (array $matches): string {
            $label = trim(strip_tags($matches[2]));
            $href = trim($matches[1]);

            return $label !== '' ? '[' . $label . '](' . $href . ')' : $href;
        }, $html) ?? $html;

        $html = preg_replace('/<(strong|b)>(.*?)<\/\\1>/is', '**$2**', $html) ?? $html;
        $html = preg_replace('/<(em|i)>(.*?)<\/\\1>/is', '*$2*', $html) ?? $html;
        $html = preg_replace_callback('/<li\b[^>]*>(.*?)<\/li>/is', static fn (array $matches): string => '- ' . trim(strip_tags($matches[1])) . "\n", $html) ?? $html;
        $html = preg_replace('/<\/(ul|ol)>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $html = preg_replace_callback('/<p\b[^>]*>(.*?)<\/p>/is', static fn (array $matches): string => trim(strip_tags($matches[1])) . "\n\n", $html) ?? $html;

        return strip_tags($html);
    }
}
