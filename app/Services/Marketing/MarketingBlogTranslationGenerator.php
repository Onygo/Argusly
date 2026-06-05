<?php

namespace App\Services\Marketing;

use App\Models\Content;
use App\Services\Llm\Data\LlmMessage;
use App\Services\Llm\Data\LlmRequest;
use App\Services\Llm\LlmManager;
use Illuminate\Support\Str;
use RuntimeException;

class MarketingBlogTranslationGenerator
{
    public function __construct(
        private readonly LlmManager $llmManager,
    ) {
    }

    /**
     * @return array{
     *   title:string,
     *   excerpt:string,
     *   body_html:string,
     *   seo_title:string,
     *   meta_description:string,
     *   seo_og_title:?string,
     *   seo_og_description:?string,
     *   slug:string,
     *   primary_keyword:?string,
     *   secondary_keywords:array<int,string>
     * }
     */
    public function generate(Content $source): array
    {
        $source->loadMissing('currentVersion');

        $body = trim((string) ($source->currentVersion?->body ?? ''));
        if ($body === '') {
            throw new RuntimeException('Marketing blog source content has no current body to translate.');
        }

        $meta = is_array($source->currentVersion?->meta) ? $source->currentVersion->meta : [];
        $excerpt = trim((string) data_get($meta, 'excerpt', ''));
        $seoTitle = trim((string) ($source->seo_title ?? $source->title ?? ''));
        $metaDescription = trim((string) ($source->seo_meta_description ?? ''));
        $ogTitle = trim((string) ($source->seo_og_title ?? ''));
        $ogDescription = trim((string) ($source->seo_og_description ?? ''));

        $request = new LlmRequest(
            messages: [
                new LlmMessage('system', $this->systemPrompt()),
                new LlmMessage('user', $this->userPrompt(
                    title: (string) ($source->title ?? ''),
                    excerpt: $excerpt,
                    bodyHtml: $body,
                    seoTitle: $seoTitle,
                    metaDescription: $metaDescription,
                    ogTitle: $ogTitle,
                    ogDescription: $ogDescription,
                )),
            ],
            model: (string) config('translation.default_model', 'gpt-4.1-mini'),
            temperature: 0.2,
            maxTokens: min(max((int) ceil(mb_strlen($body) / 3) + 1200, 2400), 12000),
            responseFormat: 'json',
            metadata: [
                'feature' => 'marketing_blog_translation',
                'source_content_id' => (string) $source->id,
                'source_locale' => 'nl',
                'target_locale' => 'en',
            ],
        );

        $response = $this->llmManager->generateJson($request);
        if (! is_array($response->json ?? null)) {
            throw new RuntimeException('Marketing blog translation did not return valid JSON.');
        }

        $payload = $response->json;
        $seoPayload = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];
        $secondaryKeywords = collect((array) ($seoPayload['secondary_keywords'] ?? []))
            ->map(fn (mixed $keyword): string => trim((string) $keyword))
            ->filter()
            ->values()
            ->all();

        $title = trim((string) ($payload['title'] ?? ''));
        $slug = Str::slug((string) ($seoPayload['slug'] ?? $title));

        return [
            'title' => $title !== '' ? $title : (string) $source->title,
            'excerpt' => trim((string) ($payload['excerpt'] ?? '')) ?: $excerpt,
            'body_html' => trim((string) ($payload['body_html'] ?? $payload['content_html'] ?? '')),
            'seo_title' => trim((string) ($seoPayload['seo_title'] ?? $title ?: $source->title)),
            'meta_description' => trim((string) ($seoPayload['seo_meta_description'] ?? $payload['meta_description'] ?? $metaDescription)),
            'seo_og_title' => trim((string) ($seoPayload['seo_og_title'] ?? $ogTitle)) ?: null,
            'seo_og_description' => trim((string) ($seoPayload['seo_og_description'] ?? $ogDescription)) ?: null,
            'slug' => $slug !== '' ? $slug : Str::slug((string) $source->title . '-en'),
            'primary_keyword' => trim((string) ($seoPayload['suggested_primary_keyword'] ?? '')) ?: null,
            'secondary_keywords' => $secondaryKeywords,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are translating a Dutch marketing blog article into idiomatic English.

Rules:
1. Preserve the factual meaning and the HTML structure.
2. Keep links and URLs intact.
3. Write natural English, not literal Dutch.
4. Translate title, excerpt, body, SEO title, meta description, OG title, and OG description.
5. Generate a clean English slug in lowercase with hyphens.
6. Do not invent claims.

Return JSON only:
{
  "title": "string",
  "excerpt": "string",
  "body_html": "string",
  "seo": {
    "seo_title": "string",
    "seo_meta_description": "string",
    "seo_og_title": "string",
    "seo_og_description": "string",
    "slug": "string",
    "suggested_primary_keyword": "string",
    "secondary_keywords": ["string"]
  }
}
PROMPT;
    }

    private function userPrompt(
        string $title,
        string $excerpt,
        string $bodyHtml,
        string $seoTitle,
        string $metaDescription,
        string $ogTitle,
        string $ogDescription,
    ): string {
        return <<<PROMPT
Translate this Dutch marketing blog article into English.

Original title:
{$title}

Original excerpt:
{$excerpt}

Original body HTML:
{$bodyHtml}

Original SEO title:
{$seoTitle}

Original meta description:
{$metaDescription}

Original OG title:
{$ogTitle}

Original OG description:
{$ogDescription}
PROMPT;
    }
}
