<?php

namespace App\Services\Marketing;

use App\Models\Content;
use App\Models\MarketingBlogRedirect;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\PublicBlog\MarketingBlogSourceScope;
use App\Enums\SupportedLanguage;
use App\Support\LocalizedMarketingUrl;
use App\Support\MarketingRouteSegments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class MarketingBlogLocaleNormalizationService
{
    public function __construct(
        private readonly MarketingBlogSourceScope $sourceScope,
        private readonly ContentCacheInvalidationService $cacheInvalidation,
        private readonly MarketingRouteSegments $segments,
    ) {
    }

    /**
     * @return array{
     *   mode:string,
     *   scope:array{mode:string,id:string},
     *   found:int,
     *   normalized_to_nl_source:int,
     *   redirects_created:int,
     *   unchanged:int,
     *   manual_review:int,
     *   changed:array<int,array<string,mixed>>,
     *   review_items:array<int,array<string,mixed>>
     * }
     */
    public function run(bool $dryRun = true): array
    {
        $scope = $this->sourceScope->resolve();
        if (! $scope) {
            throw new RuntimeException('Marketing blog source is not configured.');
        }

        $scopeColumn = $this->sourceScope->localColumnForMode($scope['mode']);
        if (! $scopeColumn) {
            throw new RuntimeException('Unsupported marketing blog source scope.');
        }

        $contents = Content::query()
            ->with(['currentVersion'])
            ->where($scopeColumn, $scope['id'])
            ->where('type', 'article')
            ->whereNotNull('current_version_id')
            ->orderBy('created_at')
            ->get();

        $report = [
            'mode' => $dryRun ? 'dry-run' : 'apply',
            'scope' => $scope,
            'found' => $contents->count(),
            'normalized_to_nl_source' => 0,
            'redirects_created' => 0,
            'unchanged' => 0,
            'manual_review' => 0,
            'changed' => [],
            'review_items' => [],
        ];

        foreach ($contents as $content) {
            $analysis = $this->analyzeContent($content);

            if ($analysis['needs_manual_review']) {
                $report['manual_review']++;
                $report['review_items'][] = $analysis['summary'];
                Log::warning('marketing_blog_locale_normalization.manual_review', $analysis['summary']);
                continue;
            }

            if (! $analysis['should_apply']) {
                $report['unchanged']++;
                continue;
            }

            if (($analysis['updates']['language'] ?? null) === 'nl') {
                $report['normalized_to_nl_source']++;
            }

            if ($analysis['redirect'] !== null) {
                $report['redirects_created']++;
            }

            $report['changed'][] = $analysis['summary'];

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($content, $analysis): void {
                Content::query()
                    ->whereKey($content->id)
                    ->update($analysis['updates']);

                if ($analysis['redirect'] !== null) {
                    MarketingBlogRedirect::query()->updateOrCreate(
                        ['source_path' => $analysis['redirect']['source_path']],
                        $analysis['redirect']
                    );
                }
            });

            Log::info('marketing_blog_locale_normalization.applied', $analysis['summary']);
        }

        if (! $dryRun) {
            $this->cacheInvalidation->invalidatePublicContent('marketing.blog_locale_normalization');
        }

        return $report;
    }

    /**
     * @return array{
     *   should_apply:bool,
     *   needs_manual_review:bool,
     *   updates:array<string,mixed>,
     *   redirect:array<string,mixed>|null,
     *   summary:array<string,mixed>
     * }
     */
    private function analyzeContent(Content $content): array
    {
        $meta = is_array($content->currentVersion?->meta) ? $content->currentVersion->meta : [];
        $title = trim((string) ($content->title ?? ''));
        $excerpt = trim((string) data_get($meta, 'excerpt', ''));
        $body = trim((string) ($content->currentVersion?->body ?? ''));
        $slug = $this->resolveSlug($content, $meta, $title);
        $storedLocale = $this->normalizeLocale($content->getRawOriginal('language')) ?? $content->localeCode();
        $detected = $this->detectLocale($title, $excerpt, $body, $storedLocale);

        $summary = [
            'content_id' => (string) $content->id,
            'title' => $title,
            'slug' => $slug,
            'stored_locale' => $storedLocale,
            'detected_locale' => $detected['locale'],
            'confidence' => $detected['confidence'],
            'dutch_score' => $detected['dutch_score'],
            'english_score' => $detected['english_score'],
        ];

        if ($content->isTranslationVariant() && $detected['locale'] !== $storedLocale) {
            return [
                'should_apply' => false,
                'needs_manual_review' => true,
                'updates' => [],
                'redirect' => null,
                'summary' => $summary + ['reason' => 'Existing translation variant has mismatched locale signals.'],
            ];
        }

        if ($detected['confidence'] === 'low' && $detected['locale'] !== $storedLocale) {
            return [
                'should_apply' => false,
                'needs_manual_review' => true,
                'updates' => [],
                'redirect' => null,
                'summary' => $summary + ['reason' => 'Locale signals are too weak for automatic normalization.'],
            ];
        }

        $targetLocale = $detected['locale'];
        $publishedUrlLocale = $this->blogUrlLocale((string) ($content->published_url ?? ''), $slug);
        $canonicalUrlLocale = $this->blogUrlLocale((string) ($content->seo_canonical ?? ''), $slug);
        $legacyRouteLocale = $publishedUrlLocale ?? $canonicalUrlLocale ?? $storedLocale;
        $updates = [
            'language' => $targetLocale,
            'translation_source_locale' => $targetLocale,
            'is_source_locale' => true,
            'translation_source_content_id' => null,
            'translation_source_version_id' => null,
            'translation_generated_at' => null,
            'translation_source_updated_at' => null,
            'updated_at' => Carbon::now(),
        ];

        $normalizedPublishedUrl = $this->retargetBlogUrl((string) ($content->published_url ?? ''), $targetLocale, $slug);
        if ($normalizedPublishedUrl !== null) {
            $updates['published_url'] = $normalizedPublishedUrl;
        }

        $normalizedCanonical = $this->retargetBlogUrl((string) ($content->seo_canonical ?? ''), $targetLocale, $slug);
        if ($normalizedCanonical !== null) {
            $updates['seo_canonical'] = $normalizedCanonical;
        }

        $redirect = null;
        if ($this->shouldCreateLegacyLocaleRedirect($content, $legacyRouteLocale, $targetLocale)) {
            $redirect = [
                'source_path' => $this->localizedBlogPath($legacyRouteLocale, $slug),
                'source_locale' => $legacyRouteLocale,
                'source_slug' => $slug,
                'target_path' => $this->localizedBlogPath($targetLocale, $slug),
                'target_locale' => $targetLocale,
                'target_slug' => $slug,
                'target_content_id' => (string) $content->id,
                'redirect_kind' => 'legacy_locale_mismatch',
                'is_active' => true,
                'meta' => [
                    'reason' => 'normalized_marketing_blog_locale',
                    'stored_locale' => $storedLocale,
                    'legacy_route_locale' => $legacyRouteLocale,
                    'detected_locale' => $targetLocale,
                    'confidence' => $detected['confidence'],
                ],
            ];
        }

        $currentComparable = [
            'language' => $storedLocale,
            'translation_source_locale' => trim((string) ($content->translation_source_locale ?? '')),
            'is_source_locale' => (bool) ($content->is_source_locale ?? false),
            'translation_source_content_id' => $content->translation_source_content_id ? (string) $content->translation_source_content_id : null,
            'translation_source_version_id' => $content->translation_source_version_id ? (string) $content->translation_source_version_id : null,
            'translation_generated_at' => $content->translation_generated_at?->toIso8601String(),
            'translation_source_updated_at' => $content->translation_source_updated_at?->toIso8601String(),
            'published_url' => (string) ($content->published_url ?? ''),
            'seo_canonical' => (string) ($content->seo_canonical ?? ''),
        ];

        $proposedComparable = [
            'language' => $updates['language'],
            'translation_source_locale' => $updates['translation_source_locale'],
            'is_source_locale' => $updates['is_source_locale'],
            'translation_source_content_id' => $updates['translation_source_content_id'],
            'translation_source_version_id' => $updates['translation_source_version_id'],
            'translation_generated_at' => $updates['translation_generated_at'],
            'translation_source_updated_at' => $updates['translation_source_updated_at'],
            'published_url' => $updates['published_url'] ?? (string) ($content->published_url ?? ''),
            'seo_canonical' => $updates['seo_canonical'] ?? (string) ($content->seo_canonical ?? ''),
        ];

        $shouldApply = $currentComparable !== $proposedComparable || $redirect !== null;

        return [
            'should_apply' => $shouldApply,
            'needs_manual_review' => false,
            'updates' => $updates,
            'redirect' => $redirect,
            'summary' => $summary + [
                'target_locale' => $targetLocale,
                'redirect_source' => $redirect['source_path'] ?? null,
                'redirect_target' => $redirect['target_path'] ?? null,
            ],
        ];
    }

    /**
     * @return array{locale:string,confidence:string,dutch_score:int,english_score:int}
     */
    private function detectLocale(string $title, string $excerpt, string $body, string $storedLocale): array
    {
        $text = mb_strtolower(trim(strip_tags(implode(' ', array_filter([$title, $excerpt, $body])))));
        $text = preg_replace('/[^\p{L}\s]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        $dutchTerms = [
            'de', 'het', 'een', 'en', 'voor', 'van', 'met', 'op', 'dit', 'deze',
            'hoe', 'zo', 'je', 'jouw', 'niet', 'welke', 'waarom', 'ontwerp', 'schaalbaar',
        ];
        $englishTerms = [
            'the', 'and', 'for', 'with', 'this', 'that', 'how', 'your', 'why',
            'design', 'scalable', 'architecture', 'guide', 'build', 'content',
        ];

        $dutchScore = $this->scoreTerms($text, $dutchTerms);
        $englishScore = $this->scoreTerms($text, $englishTerms);

        if ($dutchScore >= max(2, $englishScore + 2)) {
            return [
                'locale' => 'nl',
                'confidence' => 'high',
                'dutch_score' => $dutchScore,
                'english_score' => $englishScore,
            ];
        }

        if ($englishScore >= max(2, $dutchScore + 2)) {
            return [
                'locale' => 'en',
                'confidence' => 'high',
                'dutch_score' => $dutchScore,
                'english_score' => $englishScore,
            ];
        }

        if ($dutchScore > $englishScore) {
            return [
                'locale' => 'nl',
                'confidence' => 'medium',
                'dutch_score' => $dutchScore,
                'english_score' => $englishScore,
            ];
        }

        if ($englishScore > $dutchScore) {
            return [
                'locale' => 'en',
                'confidence' => 'medium',
                'dutch_score' => $dutchScore,
                'english_score' => $englishScore,
            ];
        }

        return [
            'locale' => $storedLocale !== '' ? $storedLocale : 'nl',
            'confidence' => 'low',
            'dutch_score' => $dutchScore,
            'english_score' => $englishScore,
        ];
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
            (string) ($content->publish_url_key ?? ''),
            $this->slugFromUrl((string) ($content->published_url ?? '')),
            $title,
            (string) $content->id,
        ])->map(fn (string $value): string => $this->normalizeSlugCandidate($value))
            ->first(fn (string $value): bool => $value !== '');

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
        $resolved = SupportedLanguage::tryFromString($locale)?->value;

        return $resolved !== null && $this->segments->isSupportedLocale($resolved)
            ? $resolved
            : null;
    }

    private function shouldCreateLegacyLocaleRedirect(Content $content, string $legacyRouteLocale, string $targetLocale): bool
    {
        if ($legacyRouteLocale === $targetLocale) {
            return false;
        }

        if ((string) $content->status !== 'published' || (string) ($content->publish_status ?? '') !== 'published') {
            return false;
        }

        return $this->segments->isSupportedLocale($legacyRouteLocale)
            && $this->segments->isSupportedLocale($targetLocale);
    }

    private function localizedBlogPath(string $locale, string $slug): string
    {
        return LocalizedMarketingUrl::route('public.blog.show', ['slug' => $slug], $locale, false);
    }

    private function retargetBlogUrl(string $url, string $targetLocale, string $slug): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $currentLocale = $this->blogUrlLocale($url, $slug);
        if ($currentLocale === null || $currentLocale === $targetLocale) {
            return null;
        }

        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $authority = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        return $authority . $this->localizedBlogPath($targetLocale, $slug);
    }

    private function blogUrlLocale(string $url, string $slug): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return null;
        }

        foreach ($this->segments->locales() as $locale) {
            if ($path === $this->localizedBlogPath($locale, $slug)) {
                return $locale;
            }
        }

        if ($path === '/' . trim($this->segments->segment('blog', $this->segments->defaultLocale()), '/') . '/' . $slug) {
            return $this->segments->defaultLocale();
        }

        return null;
    }

}
