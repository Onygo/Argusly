<?php

namespace App\Services\Seo;

use App\Models\Content;

class StructuredDataValidationService
{
    /**
     * @return array{items:list<array<string,mixed>>,summary:array<string,int>}
     */
    public function validate(): array
    {
        $items = [];

        foreach (Content::query()->where('type', 'article')->where('status', 'published')->where('publish_status', 'published')->limit(500)->get() as $content) {
            $missing = [];
            foreach (['title', 'publish_url_key', 'first_published_at'] as $field) {
                if (trim((string) ($content->{$field} ?? '')) === '') {
                    $missing[] = $field;
                }
            }

            if ($missing !== []) {
                $items[] = [
                    'type' => 'Article',
                    'id' => (string) $content->id,
                    'title' => (string) $content->title,
                    'missing' => $missing,
                ];
            }
        }

        return [
            'items' => $items,
            'summary' => [
                'checked' => Content::query()->where('type', 'article')->where('status', 'published')->where('publish_status', 'published')->count(),
                'with_issues' => count($items),
            ],
        ];
    }
}
