<?php

namespace App\Services\Translation;

use App\Enums\SupportedLanguage;
use App\Models\Draft;
use App\Support\DescriptionSanitizer;
use App\Support\DutchTextCasingNormalizer;
use App\Support\KeywordSanitizer;
use App\Support\TitleSanitizer;
use Illuminate\Support\Str;

class SeoLocalizationService
{
    /**
     * @param  array<string, mixed>  $translatedSeo
     * @return array<string, mixed>
     */
    public function buildLocalizedSeoMetadata(
        Draft $sourceDraft,
        string $translatedTitle,
        SupportedLanguage $targetLanguage,
        array $translatedSeo = []
    ): array {
        $seoTitle = $this->normalizeTitleForLanguage((string) ($translatedSeo['seo_title'] ?? $translatedTitle), $targetLanguage);
        $metaDescription = DescriptionSanitizer::normalizeMetaDescription(
            (string) ($translatedSeo['seo_meta_description'] ?? $sourceDraft->seo_meta_description ?? '')
        );
        $metaDescription = $this->normalizeTextForLanguage($metaDescription, $targetLanguage);
        $h1 = $this->normalizeTitleForLanguage((string) ($translatedSeo['seo_h1'] ?? $translatedTitle), $targetLanguage);
        $ogTitle = $this->normalizeTitleForLanguage((string) ($translatedSeo['seo_og_title'] ?? $seoTitle), $targetLanguage);
        $ogDescription = DescriptionSanitizer::normalizeOgDescription(
            (string) ($translatedSeo['seo_og_description'] ?? $metaDescription)
        );
        $ogDescription = $this->normalizeTextForLanguage($ogDescription, $targetLanguage);
        $twitterTitle = $this->normalizeTitleForLanguage((string) ($translatedSeo['seo_twitter_title'] ?? $ogTitle), $targetLanguage);
        $twitterDescription = DescriptionSanitizer::normalizeTwitterDescription(
            (string) ($translatedSeo['seo_twitter_description'] ?? $ogDescription)
        );
        $twitterDescription = $this->normalizeTextForLanguage($twitterDescription, $targetLanguage);

        $slug = $this->generateLocalizedSlug($translatedTitle, $targetLanguage);

        $secondaryKeywords = collect((array) ($translatedSeo['secondary_keywords'] ?? []))
            ->map(fn (mixed $keyword): string => KeywordSanitizer::normalize($keyword))
            ->filter()
            ->values()
            ->all();

        $primaryKeyword = KeywordSanitizer::normalize(
            (string) ($translatedSeo['suggested_primary_keyword'] ?? $sourceDraft->brief?->primary_keyword ?? '')
        );

        return [
            'seo_title' => $seoTitle,
            'seo_meta_description' => $metaDescription,
            'seo_h1' => $h1,
            'seo_og_title' => $ogTitle,
            'seo_og_description' => $ogDescription,
            'seo_og_image' => $sourceDraft->seo_og_image,
            'seo_twitter_title' => $twitterTitle,
            'seo_twitter_description' => $twitterDescription,
            'seo_canonical' => DescriptionSanitizer::normalizeCanonicalUrl($sourceDraft->seo_canonical),
            'slug' => $slug,
            'primary_keyword' => $primaryKeyword !== '' ? $primaryKeyword : null,
            'secondary_keywords' => $secondaryKeywords,
            'needs_review' => $this->flagSeoFieldsNeedingReview(new Draft([
                'seo_title' => $seoTitle,
                'seo_meta_description' => $metaDescription,
            ])) !== [],
        ];
    }

    public function copySeoFromSource(Draft $sourceDraft, Draft $targetDraft): void
    {
        $targetDraft->seo_title = $sourceDraft->seo_title;
        $targetDraft->seo_meta_description = $sourceDraft->seo_meta_description;
        $targetDraft->seo_h1 = $sourceDraft->seo_h1;
        $targetDraft->seo_canonical = $sourceDraft->seo_canonical;
        $targetDraft->seo_og_title = $sourceDraft->seo_og_title;
        $targetDraft->seo_og_description = $sourceDraft->seo_og_description;
        $targetDraft->seo_og_image = $sourceDraft->seo_og_image;
        $targetDraft->seo_twitter_title = $sourceDraft->seo_twitter_title;
        $targetDraft->seo_twitter_description = $sourceDraft->seo_twitter_description;
        $targetDraft->robots_index = $sourceDraft->robots_index;
        $targetDraft->robots_follow = $sourceDraft->robots_follow;
        $targetDraft->schema_type = $sourceDraft->schema_type;
        $targetDraft->save();
    }

    public function applySeoFromTranslation(Draft $draft, array $seoData): void
    {
        // Note: Draft model has attribute mutators that automatically sanitize these fields
        if (isset($seoData['seo_title'])) {
            $draft->seo_title = $seoData['seo_title'];
        }

        if (isset($seoData['seo_meta_description'])) {
            $draft->seo_meta_description = $seoData['seo_meta_description'];
        }

        if (isset($seoData['seo_h1'])) {
            $draft->seo_h1 = $seoData['seo_h1'];
        }

        if (isset($seoData['seo_og_title'])) {
            $draft->seo_og_title = $seoData['seo_og_title'];
        }

        if (isset($seoData['seo_og_description'])) {
            $draft->seo_og_description = $seoData['seo_og_description'];
        }

        if (isset($seoData['seo_twitter_title'])) {
            $draft->seo_twitter_title = $seoData['seo_twitter_title'];
        }

        if (isset($seoData['seo_twitter_description'])) {
            $draft->seo_twitter_description = $seoData['seo_twitter_description'];
        }

        if (isset($seoData['seo_canonical'])) {
            $draft->seo_canonical = $seoData['seo_canonical'];
        }

        $draft->save();
    }

    public function generateLocalizedSlug(string $title, SupportedLanguage $language): string
    {
        $slug = Str::slug($title);

        if (empty($slug)) {
            $slug = Str::slug(Str::ascii($title));
        }

        if (empty($slug)) {
            $slug = 'article-' . Str::random(8);
        }

        return $slug;
    }

    public function flagSeoFieldsNeedingReview(Draft $draft): array
    {
        $warnings = [];

        $seoTitle = $draft->seo_title ?? '';
        if (mb_strlen($seoTitle) > 60) {
            $warnings[] = [
                'field' => 'seo_title',
                'issue' => 'exceeds_length',
                'message' => 'SEO title exceeds 60 characters',
                'current_length' => mb_strlen($seoTitle),
            ];
        }

        $metaDescription = $draft->seo_meta_description ?? '';
        if (mb_strlen($metaDescription) > 160) {
            $warnings[] = [
                'field' => 'seo_meta_description',
                'issue' => 'exceeds_length',
                'message' => 'Meta description exceeds 160 characters',
                'current_length' => mb_strlen($metaDescription),
            ];
        }

        if (empty($draft->seo_title)) {
            $warnings[] = [
                'field' => 'seo_title',
                'issue' => 'missing',
                'message' => 'SEO title is missing',
            ];
        }

        if (empty($draft->seo_meta_description)) {
            $warnings[] = [
                'field' => 'seo_meta_description',
                'issue' => 'missing',
                'message' => 'Meta description is missing',
            ];
        }

        return $warnings;
    }

    private function normalizeTitleForLanguage(string $title, SupportedLanguage $language): string
    {
        $normalized = TitleSanitizer::normalize($title);

        return $this->normalizeTextForLanguage($normalized, $language);
    }

    private function normalizeTextForLanguage(string $text, SupportedLanguage $language): string
    {
        return $language === SupportedLanguage::NL
            ? DutchTextCasingNormalizer::normalizeText($text)
            : $text;
    }

}
