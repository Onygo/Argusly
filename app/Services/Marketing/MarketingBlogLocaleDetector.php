<?php

namespace App\Services\Marketing;

use App\Enums\SupportedLanguage;
use App\Models\Content;
use Illuminate\Support\Str;

class MarketingBlogLocaleDetector
{
    public function __construct(
        private readonly MarketingBlogRedirectService $redirects,
    ) {
    }

    /**
     * @return array{
     *   slug:string,
     *   stored_locale:?string,
     *   route_locale:?string,
     *   text_locale:string,
     *   confidence:string,
     *   dutch_score:int,
     *   english_score:int,
     *   is_on_en_surface:bool,
     *   is_candidate_misplaced_en:bool,
     *   should_normalize_to_nl:bool,
     *   needs_review:bool,
     *   reason:string
     * }
     */
    public function detect(Content $content): array
    {
        $meta = is_array($content->currentVersion?->meta) ? $content->currentVersion->meta : [];
        $title = trim((string) ($content->title ?? ''));
        $excerpt = trim((string) data_get($meta, 'excerpt', ''));
        $body = trim((string) ($content->currentVersion?->body ?? ''));
        $slug = $this->resolveSlug($content, $meta, $title);

        $storedLocale = $this->normalizeLocale($content->getRawOriginal('language'));
        $routeLocale = $this->redirects->resolveBlogRouteLocale((string) ($content->published_url ?? ''), $slug)
            ?? $this->redirects->resolveBlogRouteLocale((string) ($content->seo_canonical ?? ''), $slug);

        $textSignals = $this->detectTextLocale($title, $excerpt, $body, $storedLocale ?? 'nl');
        $onEnSurface = $storedLocale === 'en' || $routeLocale === 'en';
        $isCandidateMisplacedEn = $onEnSurface && $textSignals['locale'] === 'nl';

        $needsReview = false;
        $reason = $isCandidateMisplacedEn ? 'Dutch content detected on English surface.' : 'No locale repair needed.';

        if ($content->isTranslationVariant() && $isCandidateMisplacedEn) {
            $needsReview = true;
            $reason = 'Existing translation-linked variant needs manual review before locale repair.';
        } elseif ($isCandidateMisplacedEn && $textSignals['confidence'] === 'low') {
            $needsReview = true;
            $reason = 'Locale signals are too weak for safe automatic repair.';
        } elseif ($storedLocale === null && $routeLocale === null && $textSignals['confidence'] === 'low') {
            $needsReview = true;
            $reason = 'Locale is missing and text signals are inconclusive.';
        }

        $shouldNormalizeToNl = ! $needsReview
            && $textSignals['locale'] === 'nl'
            && (
                $isCandidateMisplacedEn
                || $storedLocale === null
                || $storedLocale !== 'nl'
                || $routeLocale === 'en'
            );

        return [
            'slug' => $slug,
            'stored_locale' => $storedLocale,
            'route_locale' => $routeLocale,
            'text_locale' => $textSignals['locale'],
            'confidence' => $textSignals['confidence'],
            'dutch_score' => $textSignals['dutch_score'],
            'english_score' => $textSignals['english_score'],
            'is_on_en_surface' => $onEnSurface,
            'is_candidate_misplaced_en' => $isCandidateMisplacedEn,
            'should_normalize_to_nl' => $shouldNormalizeToNl,
            'needs_review' => $needsReview,
            'reason' => $reason,
        ];
    }

    /**
     * @return array{locale:string,confidence:string,dutch_score:int,english_score:int}
     */
    private function detectTextLocale(string $title, string $excerpt, string $body, string $fallbackLocale): array
    {
        $text = mb_strtolower(trim(strip_tags(implode(' ', array_filter([$title, $excerpt, $body])))));
        $text = preg_replace('/[^\p{L}\s]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        $dutchTerms = [
            'de', 'het', 'een', 'en', 'voor', 'van', 'met', 'op', 'dit', 'deze',
            'hoe', 'zo', 'je', 'jouw', 'niet', 'welke', 'waarom', 'ontwerp', 'schaalbaar',
            'uitleg', 'artikel', 'contentplatform', 'nederlandse',
        ];
        $englishTerms = [
            'the', 'and', 'for', 'with', 'this', 'that', 'how', 'your', 'why',
            'design', 'scalable', 'architecture', 'guide', 'build', 'content',
            'article', 'platform', 'translation', 'english',
        ];

        $dutchScore = $this->scoreTerms($text, $dutchTerms);
        $englishScore = $this->scoreTerms($text, $englishTerms);

        if ($dutchScore >= max(2, $englishScore + 2)) {
            return ['locale' => 'nl', 'confidence' => 'high', 'dutch_score' => $dutchScore, 'english_score' => $englishScore];
        }

        if ($englishScore >= max(2, $dutchScore + 2)) {
            return ['locale' => 'en', 'confidence' => 'high', 'dutch_score' => $dutchScore, 'english_score' => $englishScore];
        }

        if ($dutchScore > $englishScore) {
            return ['locale' => 'nl', 'confidence' => 'medium', 'dutch_score' => $dutchScore, 'english_score' => $englishScore];
        }

        if ($englishScore > $dutchScore) {
            return ['locale' => 'en', 'confidence' => 'medium', 'dutch_score' => $dutchScore, 'english_score' => $englishScore];
        }

        return ['locale' => $fallbackLocale ?: 'nl', 'confidence' => 'low', 'dutch_score' => $dutchScore, 'english_score' => $englishScore];
    }

    private function scoreTerms(string $text, array $terms): int
    {
        $score = 0;

        foreach ($terms as $term) {
            $score += preg_match_all('/\b' . preg_quote($term, '/') . '\b/u', $text);
        }

        return $score;
    }

    private function resolveSlug(Content $content, array $meta, string $title): string
    {
        $fallback = collect([
            (string) data_get($meta, 'slug', ''),
            (string) data_get($meta, 'seo.slug', ''),
            (string) ($content->publish_url_key ?? ''),
            $this->slugFromUrl((string) ($content->published_url ?? '')),
            $title,
            (string) $content->id,
        ])->map(fn (string $candidate): string => $this->normalizeSlugCandidate($candidate))
            ->first(fn (string $candidate): bool => $candidate !== '');

        return (string) $fallback;
    }

    private function slugFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return trim((string) basename($path), '/');
    }

    private function normalizeSlugCandidate(string $candidate): string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return '';
        }

        if (filter_var($candidate, FILTER_VALIDATE_URL)) {
            $candidate = $this->slugFromUrl($candidate);
        } elseif (str_contains($candidate, '/')) {
            $candidate = trim((string) basename($candidate), '/');
        }

        return (string) Str::slug($candidate);
    }

    private function normalizeLocale(?string $locale): ?string
    {
        return SupportedLanguage::tryFromString($locale)?->value;
    }
}
