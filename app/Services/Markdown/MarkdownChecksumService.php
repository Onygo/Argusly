<?php

namespace App\Services\Markdown;

class MarkdownChecksumService
{
    public function generate(
        ?string $markdown,
        ?string $html = null,
        ?string $locale = null,
        int $version = 1
    ): ?string {
        $normalizedMarkdown = $this->normalize($markdown);
        $normalizedHtml = $this->normalize($html);

        if ($normalizedMarkdown === '' && $normalizedHtml === '') {
            return null;
        }

        $payload = [
            'markdown_version' => max(1, $version),
            'markdown_locale' => strtolower(trim((string) ($locale ?: 'en'))),
            'rendered_markdown' => $normalizedMarkdown,
            'rendered_html' => $normalizedHtml,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function normalize(?string $value): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", (string) $value);

        return trim($normalized);
    }
}
