<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SafeMarkdownRenderer
{
    public function render(?string $markdown): string
    {
        $source = trim((string) $markdown);
        if ($source === '') {
            return '';
        }

        try {
            $html = (string) Str::markdown($source, [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]);
        } catch (Throwable $exception) {
            Log::warning('markdown.render_failed', [
                'message' => $exception->getMessage(),
            ]);

            $html = '<p>' . e($source) . '</p>';
        }

        return $this->sanitizeHtml($html);
    }

    private function sanitizeHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $html = preg_replace('/<\s*(script|style|iframe|object|embed|form|input|button|textarea|select|meta|link)\b[^>]*>.*?<\s*\/\s*\\1\s*>/is', '', $html) ?? '';
        $html = preg_replace('/<\s*(script|style|iframe|object|embed|form|input|button|textarea|select|meta|link)\b[^>]*\/?>/is', '', $html) ?? '';
        $html = preg_replace('/\son\w+\s*=\s*(\"[^\"]*\"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s(href|src)\s*=\s*([\'"])\s*javascript:[^\'"]*\\2/i', '', $html) ?? '';

        $allowed = '<p><h1><h2><h3><h4><h5><h6><ul><ol><li><blockquote><pre><code><strong><em><b><i><a><hr><br><table><thead><tbody><tr><th><td>';
        $sanitized = strip_tags($html, $allowed);

        return trim($sanitized);
    }
}
