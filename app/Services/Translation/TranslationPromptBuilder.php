<?php

namespace App\Services\Translation;

use App\Enums\SupportedLanguage;
use App\Models\Draft;

class TranslationPromptBuilder
{
    public const PROMPT_VERSION = 'draft-translation.accuracy.v2';

    public function buildSystemPrompt(SupportedLanguage $targetLanguage): string
    {
        $languageName = $targetLanguage->englishLabel();

        return <<<PROMPT
You are an expert multilingual translator specializing in content translation for digital publishing. Your task is to translate content into {$languageName}.

## Core Translation Requirements

1. **Preserve Semantic Structure**: Preserve the same argument, source meaning, heading hierarchy level, and content organization. You may improve headings, sentence rhythm, paragraph flow, local idiom, and overly literal phrasing so the target-language article reads editorially natural.

2. **Preserve Formatting Integrity**: Keep HTML valid and preserve links, lists, tables, schema-compatible markup, and important formatting. Do not remove required sections, but you may split or combine paragraphs when the target language needs better flow.

3. **Preserve Meaning**: Translate the factual meaning accurately. Do not add, remove, or invent any claims or information.

4. **Preserve Intent**: Maintain the same tone, style, and persuasive elements. Keep calls-to-action compelling in the target language.

5. **Natural Editorial Language**: Use natural, fluent {$languageName} that reads as if it was originally written in that language. Adapt idioms and expressions to their natural equivalents rather than literal translations. Do not preserve AI-like rhythm, weak generic headings, or awkward source-language structure when a more natural target-language version preserves the same meaning.

6. **Preserve Links**: Keep all URLs, internal links, and external links exactly as they are. Only translate link text, not the URLs themselves.

7. **Preserve Technical Terms**: Keep technical terms, product names, brand names, and proper nouns unchanged unless they have an official localized version.

8. **SEO Awareness**: Maintain keyword relevance where possible. If the original content targets specific keywords, try to use their natural equivalents in the target language.

9. **Dutch Casing**: When translating into Dutch, use normal Dutch sentence case for titles, headings, SEO fields, and body copy. Do not use English Title Case. Keep only proper nouns, brand names, and acronyms uppercase.

10. **Dutch-to-English Connector Naturalization**: When translating Dutch "Van X naar Y" titles, headings, or SEO fields into English, use "From X to Y". Never leave the Dutch connector "Van" at the start of an English title or heading unless it is part of a proper noun.

11. **Editorial Naturalization Boundaries**: You may improve heading naturalness, rhythm, paragraph flow, local idiom, and overly literal phrasing. You may not change facts, entities, CTA intent, source meaning, SEO intent, internal link URLs, or product/brand names.

## Output Format

Return a valid JSON object with the following structure:

```json
{
  "title": "Translated title",
  "content_html": "Translated HTML content",
  "seo": {
    "seo_title": "Translated SEO title (max 60 characters)",
    "seo_meta_description": "Translated meta description (max 160 characters)",
    "seo_h1": "Translated H1 heading",
    "seo_og_title": "Translated OG title",
    "seo_og_description": "Translated OG description",
    "slug": "localized-slug",
    "suggested_primary_keyword": "Localized focus keyword",
    "secondary_keywords": ["Localized secondary keyword"]
  },
  "translation_notes": "Notes about any challenges or decisions made during translation, or an empty string when none"
}
```

IMPORTANT: Return ONLY the JSON object. No markdown code blocks, no commentary before or after.
PROMPT;
    }

    public function buildUserPrompt(
        Draft $sourceDraft,
        SupportedLanguage $sourceLanguage,
        SupportedLanguage $targetLanguage
    ): string {
        $sourceLanguageName = $sourceLanguage->englishLabel();
        $targetLanguageName = $targetLanguage->englishLabel();

        $title = $sourceDraft->title ?? '';
        $contentHtml = $sourceDraft->content_html ?? '';

        $seoFields = $this->extractSeoFields($sourceDraft);

        return <<<PROMPT
Translate the following content from {$sourceLanguageName} to {$targetLanguageName}.

## Original Title
{$title}

## Original Content (HTML)
{$contentHtml}

## Original SEO Fields
- SEO Title: {$seoFields['seo_title']}
- Meta Description: {$seoFields['seo_meta_description']}
- H1: {$seoFields['seo_h1']}
- OG Title: {$seoFields['seo_og_title']}
- OG Description: {$seoFields['seo_og_description']}

## Instructions
1. Translate all content naturally into {$targetLanguageName}
2. Preserve valid HTML, link URLs, entities, facts, CTA intent, and SEO meaning
3. Generate appropriate SEO fields in {$targetLanguageName}
4. Suggest a localized slug using lowercase words separated by hyphens
5. Suggest a localized primary keyword and localized secondary keywords when possible
6. If the target language is Dutch, use normal Dutch sentence case for titles, headings, SEO fields, and body copy; do not use English Title Case
7. If translating Dutch "Van X naar Y" into English, write "From X to Y"; do not leave "Van" as the first word unless it is part of a proper noun
8. Improve heading naturalness, sentence rhythm, paragraph flow, local idiom, overly literal phrasing, and AI-like source structure where the meaning remains intact
9. Return the result as a JSON object with the required structure
PROMPT;
    }

    public function buildSeoOnlyPrompt(
        Draft $sourceDraft,
        SupportedLanguage $sourceLanguage,
        SupportedLanguage $targetLanguage
    ): string {
        $sourceLanguageName = $sourceLanguage->englishLabel();
        $targetLanguageName = $targetLanguage->englishLabel();

        $seoFields = $this->extractSeoFields($sourceDraft);
        $title = $sourceDraft->title ?? '';

        return <<<PROMPT
Translate and localize the following SEO metadata from {$sourceLanguageName} to {$targetLanguageName}.

## Original Article Title
{$title}

## Original SEO Fields
- SEO Title: {$seoFields['seo_title']}
- Meta Description: {$seoFields['seo_meta_description']}
- H1: {$seoFields['seo_h1']}
- OG Title: {$seoFields['seo_og_title']}
- OG Description: {$seoFields['seo_og_description']}
- Primary Keyword: {$seoFields['primary_keyword']}

## Instructions
1. Translate SEO fields naturally into {$targetLanguageName}
2. Ensure SEO title is under 60 characters
3. Ensure meta description is under 160 characters
4. Suggest an equivalent primary keyword in the target language if applicable
5. Keep brand names and proper nouns unchanged
6. If the target language is Dutch, use normal Dutch sentence case, not English Title Case

Return a JSON object:
```json
{
  "seo_title": "...",
  "seo_meta_description": "...",
  "seo_h1": "...",
  "seo_og_title": "...",
  "seo_og_description": "...",
  "slug": "...",
  "suggested_primary_keyword": "...",
  "secondary_keywords": ["..."]
}
```

Return ONLY the JSON object.
PROMPT;
    }

    private function extractSeoFields(Draft $draft): array
    {
        return [
            'seo_title' => $draft->seo_title ?? $draft->title ?? '',
            'seo_meta_description' => $draft->seo_meta_description ?? '',
            'seo_h1' => $draft->seo_h1 ?? $draft->title ?? '',
            'seo_og_title' => $draft->seo_og_title ?? $draft->seo_title ?? $draft->title ?? '',
            'seo_og_description' => $draft->seo_og_description ?? $draft->seo_meta_description ?? '',
            'primary_keyword' => $draft->brief?->primary_keyword ?? '',
        ];
    }

    public function getMaxOutputTokens(Draft $sourceDraft): int
    {
        $contentLength = mb_strlen($sourceDraft->content_html ?? '');
        $estimatedTokens = (int) ceil($contentLength / 3);

        $buffer = (int) ceil($estimatedTokens * 0.3);
        $total = $estimatedTokens + $buffer + 500;

        return min(max($total, 2000), 12000);
    }

    /**
     * @return array<string,mixed>
     */
    public function responseSchema(): array
    {
        return [
            'name' => 'draft_translation',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'title' => ['type' => 'string'],
                    'content_html' => ['type' => 'string'],
                    'seo' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'seo_title' => ['type' => 'string'],
                            'seo_meta_description' => ['type' => 'string'],
                            'seo_h1' => ['type' => 'string'],
                            'seo_og_title' => ['type' => 'string'],
                            'seo_og_description' => ['type' => 'string'],
                            'slug' => ['type' => 'string'],
                            'suggested_primary_keyword' => ['type' => 'string'],
                            'secondary_keywords' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => [
                            'seo_title',
                            'seo_meta_description',
                            'seo_h1',
                            'seo_og_title',
                            'seo_og_description',
                            'slug',
                            'suggested_primary_keyword',
                            'secondary_keywords',
                        ],
                    ],
                    'translation_notes' => ['type' => 'string'],
                ],
                'required' => [
                    'title',
                    'content_html',
                    'seo',
                    'translation_notes',
                ],
            ],
        ];
    }
}
