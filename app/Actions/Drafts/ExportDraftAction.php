<?php

namespace App\Actions\Drafts;

use App\Models\Draft;
use Illuminate\Support\Str;

class ExportDraftAction
{
    /**
     * @return array<string, mixed>|string
     */
    public function execute(Draft $draft, string $format = 'json'): array|string
    {
        $format = strtolower(trim($format));
        if ($format === '') {
            $format = 'json';
        }

        $html = (string) ($draft->content_html ?? '');
        $plainText = $this->toPlainText($html);
        $markdown = $this->toMarkdown($html, $plainText);

        if ($format === 'html') {
            return $html;
        }

        if ($format === 'markdown') {
            return $markdown;
        }

        if ($format === 'text') {
            return $plainText;
        }

        return [
            'id' => (string) $draft->id,
            'title' => (string) $draft->title,
            'language' => (string) ($draft->language?->value ?? $draft->language),
            'status' => (string) $draft->status,
            'content' => [
                'html' => $html,
                'markdown' => $markdown,
                'plain_text' => $plainText,
            ],
            'seo' => [
                'slug' => data_get($draft->meta, 'slug'),
                'meta_title' => $draft->seo_title,
                'meta_description' => $draft->seo_meta_description,
                'canonical_url' => $draft->seo_canonical,
            ],
            'summary' => [
                'excerpt' => data_get($draft->meta, 'excerpt', Str::limit($plainText, 280, '')),
                'key_takeaways' => (array) data_get($draft->meta, 'key_takeaways', []),
            ],
            'cta' => [
                'text' => data_get($draft->meta, 'call_to_action', data_get($draft->meta, 'cta.text')),
                'url' => data_get($draft->meta, 'cta.url'),
            ],
            'usage' => [
                'credits_used' => (int) ($draft->credit_cost ?? 0),
            ],
            'timestamps' => [
                'created_at' => $draft->created_at?->toIso8601String(),
                'updated_at' => $draft->updated_at?->toIso8601String(),
            ],
        ];
    }

    private function toPlainText(string $html): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));

        return $text;
    }

    private function toMarkdown(string $html, string $fallback): string
    {
        $md = trim(str_replace(["\r\n", "\r"], "\n", strip_tags($html)));
        if ($md !== '') {
            return $md;
        }

        return $fallback;
    }
}
