<?php

namespace App\Support;

/**
 * SEO Metadata Resolution Service.
 *
 * ## Phase 1 Refactor: Content is the Single Source of Truth
 *
 * This class centralizes all SEO field resolution with a clear precedence order.
 * The architecture is transitional, moving from scattered storage to unified Content ownership.
 *
 * ### Canonical Write Path
 * Draft SEO fields → Content SEO fields (on approval via Content::syncSeoFromDraft)
 *
 * ### Read Resolution Order
 *
 * **Content Context** (via resolveForContentContext):
 * 1. Content typed columns (seo_title, seo_meta_description, etc.) - CANONICAL
 * 2. ContentSeo legacy table - backwards compatibility fallback
 * 3. Additional fallback sources if provided
 *
 * **Draft Context** (via resolveForDraftContext):
 * 1. Draft typed columns - current editing state
 * 2. Content typed columns - canonical source
 * 3. ContentSeo legacy table - backwards compatibility
 * 4. Draft.meta JSON - legacy nested data
 * 5. Brief.primary_keyword - keyword inheritance
 * 6. Payload meta - external sources
 *
 * ### Migration Status
 * - Content typed columns: ✅ Canonical (use for writes)
 * - Draft typed columns: ✅ Transitional (for editing, syncs to Content)
 * - ContentSeo table: ⚠️ Deprecated (read-only fallback)
 * - Draft.meta SEO fields: ⚠️ Legacy (read-only fallback)
 *
 * @see \App\Models\Content for canonical SEO storage
 * @see \App\Models\ContentSeo (deprecated legacy table)
 */
class SeoMetadata
{
    /**
     * Canonical SEO strategy (transitional):
     * - Canonical writes happen on typed SEO columns in `drafts` and `contents`.
     * - `content_seo` remains a legacy compatibility mirror and read-through fallback.
     * - Read precedence is centralized here:
     *   draft context: draft typed -> content typed -> content_seo legacy -> draft/meta fallbacks
     *   content context: content typed -> content_seo legacy -> extra fallbacks
     *
     * Migration intent:
     * - Keep legacy reads safe while progressively removing `content_seo` coupling.
     * - New feature work should prefer typed columns as the source of truth.
     */

    /**
     * @var array<int,string>
     */
    private const BOOLEAN_KEYS = [
        'robots_index',
        'robots_follow',
    ];

    /**
     * @param array<int,array<string,mixed>> $fallbackSources
     * @return array<string,mixed>
     */
    public static function resolveForContentContext(mixed $content, array ...$fallbackSources): array
    {
        return self::merge(
            self::contentTypedSource($content),
            self::legacyContentSeoSource($content),
            ...$fallbackSources,
        );
    }

    /**
     * @param array<string,mixed> $payloadMeta
     * @param array<int,array<string,mixed>> $fallbackSources
     * @return array<string,mixed>
     */
    public static function resolveForDraftContext(mixed $draft, array $payloadMeta = [], array ...$fallbackSources): array
    {
        $content = data_get($draft, 'content');

        return self::merge(
            self::draftTypedSource($draft),
            self::contentTypedSource($content),
            self::legacyContentSeoSource($content),
            self::draftMetaSource($draft),
            [
                'primary_keyword' => data_get($draft, 'brief.primary_keyword'),
            ],
            $payloadMeta,
            ...$fallbackSources,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function normalize(array $input): array
    {
        return [
            'primary_keyword' => self::firstNonEmpty([
                $input['primary_keyword'] ?? null,
                $input['focus_keyword'] ?? null,
                $input['focus_keyphrase'] ?? null,
                $input['seo_focus_keyword'] ?? null,
                $input['seo_primary_keyword'] ?? null,
                $input['_yoast_wpseo_focuskw'] ?? null,
                $input['rank_math_focus_keyword'] ?? null,
                $input['_aioseo_focus_keyphrase'] ?? null,
                data_get($input, 'seo.primary_keyword'),
                data_get($input, 'seo.focus_keyword'),
                data_get($input, 'seo.focus_keyphrase'),
                data_get($input, 'meta.primary_keyword'),
                data_get($input, 'meta.focus_keyword'),
            ]),
            'robots_index' => self::firstNonNullBoolean([
                $input['robots_index'] ?? null,
                $input['seo_robots_index'] ?? null,
                data_get($input, 'seo.robots_index'),
                data_get($input, 'robots.index'),
                self::robotsDirectiveValue($input['robots'] ?? null, 'index'),
                self::robotsDirectiveValue($input['robots_meta'] ?? null, 'index'),
                self::robotsDirectiveValue(data_get($input, 'seo.robots'), 'index'),
            ]),
            'robots_follow' => self::firstNonNullBoolean([
                $input['robots_follow'] ?? null,
                $input['seo_robots_follow'] ?? null,
                data_get($input, 'seo.robots_follow'),
                data_get($input, 'robots.follow'),
                self::robotsDirectiveValue($input['robots'] ?? null, 'follow'),
                self::robotsDirectiveValue($input['robots_meta'] ?? null, 'follow'),
                self::robotsDirectiveValue(data_get($input, 'seo.robots'), 'follow'),
            ]),
            'schema_type' => self::firstNonEmpty([
                $input['schema_type'] ?? null,
                $input['schema'] ?? null,
                data_get($input, 'schema.type'),
                data_get($input, 'schema.@type'),
                data_get($input, 'seo.schema_type'),
                data_get($input, 'seo.schema.type'),
                data_get($input, 'seo.schema.@type'),
            ]),
            'seo_title' => self::firstNonEmpty([
                $input['seo_title'] ?? null,
                $input['meta_title'] ?? null,
                $input['title'] ?? null,
                data_get($input, 'seo.title'),
            ]),
            'seo_meta_description' => self::firstNonEmpty([
                $input['seo_meta_description'] ?? null,
                $input['meta_description'] ?? null,
                $input['description'] ?? null,
                data_get($input, 'meta.description'),
                data_get($input, 'seo.meta_description'),
            ]),
            'seo_h1' => self::firstNonEmpty([
                $input['seo_h1'] ?? null,
                $input['h1'] ?? null,
                data_get($input, 'seo.h1'),
            ]),
            'seo_canonical' => self::firstNonEmpty([
                $input['seo_canonical'] ?? null,
                $input['canonical'] ?? null,
                $input['canonical_url'] ?? null,
                data_get($input, 'seo.canonical'),
            ]),
            'seo_og_title' => self::firstNonEmpty([
                $input['seo_og_title'] ?? null,
                $input['og_title'] ?? null,
                data_get($input, 'og.title'),
            ]),
            'seo_og_description' => self::firstNonEmpty([
                $input['seo_og_description'] ?? null,
                $input['og_description'] ?? null,
                data_get($input, 'og.description'),
            ]),
            'seo_og_image' => self::firstNonEmpty([
                $input['seo_og_image'] ?? null,
                $input['og_image'] ?? null,
                $input['og_image_url'] ?? null,
                data_get($input, 'og.image'),
            ]),
            'seo_twitter_title' => self::firstNonEmpty([
                $input['seo_twitter_title'] ?? null,
                $input['twitter_title'] ?? null,
                data_get($input, 'twitter.title'),
            ]),
            'seo_twitter_description' => self::firstNonEmpty([
                $input['seo_twitter_description'] ?? null,
                $input['twitter_description'] ?? null,
                data_get($input, 'twitter.description'),
            ]),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $sources
     * @return array<string,mixed>
     */
    public static function merge(array ...$sources): array
    {
        $merged = [];

        foreach ($sources as $source) {
            $normalized = self::normalize($source);
            foreach ($normalized as $key => $value) {
                if (self::hasResolvedValue($key, $merged[$key] ?? null)) {
                    continue;
                }

                if (self::hasResolvedValue($key, $value)) {
                    $merged[$key] = self::isBooleanKey($key) ? self::toNullableBoolean($value) : trim((string) $value);
                }
            }
        }

        foreach (array_keys(self::normalize([])) as $key) {
            if (! array_key_exists($key, $merged)) {
                $merged[$key] = null;
            }
        }

        return $merged;
    }

    /**
     * @param array<int,mixed> $values
     */
    public static function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                continue;
            }
            if (is_object($value) && ! method_exists($value, '__toString')) {
                continue;
            }

            $candidate = trim((string) $value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<int,mixed> $values
     */
    private static function firstNonNullBoolean(array $values): ?bool
    {
        foreach ($values as $value) {
            $candidate = self::toNullableBoolean($value);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private static function robotsDirectiveValue(mixed $value, string $directive): ?bool
    {
        $tokens = [];
        if (is_string($value)) {
            $tokens = preg_split('/[\s,;]+/', mb_strtolower(trim($value))) ?: [];
        } elseif (is_array($value)) {
            $tokens = collect($value)
                ->map(fn ($token) => mb_strtolower(trim((string) $token)))
                ->filter()
                ->values()
                ->all();
        }

        if ($tokens === []) {
            return null;
        }

        if ($directive === 'index') {
            if (in_array('noindex', $tokens, true)) {
                return false;
            }
            if (in_array('index', $tokens, true)) {
                return true;
            }

            return null;
        }

        if (in_array('nofollow', $tokens, true)) {
            return false;
        }
        if (in_array('follow', $tokens, true)) {
            return true;
        }

        return null;
    }

    private static function isBooleanKey(string $key): bool
    {
        return in_array($key, self::BOOLEAN_KEYS, true);
    }

    private static function hasResolvedValue(string $key, mixed $value): bool
    {
        if (self::isBooleanKey($key)) {
            return self::toNullableBoolean($value) !== null;
        }

        return trim((string) $value) !== '';
    }

    private static function toNullableBoolean(mixed $value): ?bool
    {
        if (is_array($value)) {
            return null;
        }

        if (is_object($value) && ! method_exists($value, '__toString')) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            $normalized = (int) $value;
            if ($normalized === 1) {
                return true;
            }
            if ($normalized === 0) {
                return false;
            }

            return null;
        }

        $token = mb_strtolower(trim((string) $value));
        if ($token === '') {
            return null;
        }

        if (in_array($token, ['1', 'true', 'yes', 'on', 'index', 'follow'], true)) {
            return true;
        }

        if (in_array($token, ['0', 'false', 'no', 'off', 'noindex', 'nofollow'], true)) {
            return false;
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function draftTypedSource(mixed $draft): array
    {
        return [
            'seo_title' => data_get($draft, 'seo_title'),
            'seo_meta_description' => data_get($draft, 'seo_meta_description'),
            'seo_h1' => data_get($draft, 'seo_h1'),
            'seo_canonical' => data_get($draft, 'seo_canonical'),
            'seo_og_title' => data_get($draft, 'seo_og_title'),
            'seo_og_description' => data_get($draft, 'seo_og_description'),
            'seo_og_image' => data_get($draft, 'seo_og_image'),
            'seo_twitter_title' => data_get($draft, 'seo_twitter_title'),
            'seo_twitter_description' => data_get($draft, 'seo_twitter_description'),
            'robots_index' => data_get($draft, 'robots_index'),
            'robots_follow' => data_get($draft, 'robots_follow'),
            'schema_type' => data_get($draft, 'schema_type'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function contentTypedSource(mixed $content): array
    {
        return [
            'primary_keyword' => data_get($content, 'primary_keyword'),
            'seo_title' => data_get($content, 'seo_title'),
            'seo_meta_description' => data_get($content, 'seo_meta_description'),
            'seo_h1' => data_get($content, 'seo_h1'),
            'seo_canonical' => data_get($content, 'seo_canonical'),
            'seo_og_title' => data_get($content, 'seo_og_title'),
            'seo_og_description' => data_get($content, 'seo_og_description'),
            'seo_og_image' => data_get($content, 'seo_og_image'),
            'seo_twitter_title' => data_get($content, 'seo_twitter_title'),
            'seo_twitter_description' => data_get($content, 'seo_twitter_description'),
            'robots_index' => data_get($content, 'robots_index'),
            'robots_follow' => data_get($content, 'robots_follow'),
            'schema_type' => data_get($content, 'schema_type'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function legacyContentSeoSource(mixed $contentOrSeo): array
    {
        $seo = self::extractLegacySeoNode($contentOrSeo);

        return [
            'primary_keyword' => data_get($seo, 'primary_keyword'),
            'seo_title' => data_get($seo, 'meta_title'),
            'seo_meta_description' => data_get($seo, 'meta_description'),
            'robots_index' => data_get($seo, 'robots_index'),
            'robots_follow' => data_get($seo, 'robots_follow'),
            'schema_type' => data_get($seo, 'schema_type'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function draftMetaSource(mixed $draft): array
    {
        $meta = data_get($draft, 'meta');

        return is_array($meta) ? $meta : [];
    }

    private static function extractLegacySeoNode(mixed $contentOrSeo): mixed
    {
        $directMetaTitle = trim((string) data_get($contentOrSeo, 'meta_title', ''));
        $directMetaDescription = trim((string) data_get($contentOrSeo, 'meta_description', ''));
        if ($directMetaTitle !== '' || $directMetaDescription !== '') {
            return $contentOrSeo;
        }

        return data_get($contentOrSeo, 'seo');
    }

    // =========================================================================
    // Canonical Field Definitions (Phase 1 Refactor)
    // =========================================================================

    /**
     * Get the list of canonical SEO field names on Content model.
     *
     * These are the authoritative fields for SEO metadata storage.
     *
     * @return array<int, string>
     */
    public static function canonicalFields(): array
    {
        return [
            'seo_title',
            'seo_meta_description',
            'seo_h1',
            'seo_canonical',
            'seo_og_title',
            'seo_og_description',
            'seo_og_image',
            'seo_twitter_title',
            'seo_twitter_description',
            'robots_index',
            'robots_follow',
            'schema_type',
            'primary_keyword',
        ];
    }

    /**
     * Get the list of SEO fields that should be synced from Draft to Content.
     *
     * Excludes primary_keyword which comes from Brief.
     *
     * @return array<int, string>
     */
    public static function syncableFields(): array
    {
        return [
            'seo_title',
            'seo_meta_description',
            'seo_h1',
            'seo_canonical',
            'seo_og_title',
            'seo_og_description',
            'seo_og_image',
            'seo_twitter_title',
            'seo_twitter_description',
            'robots_index',
            'robots_follow',
            'schema_type',
        ];
    }

    /**
     * Map legacy ContentSeo field names to canonical Content field names.
     *
     * @return array<string, string> Legacy name => Canonical name
     */
    public static function legacyFieldMapping(): array
    {
        return [
            'meta_title' => 'seo_title',
            'meta_description' => 'seo_meta_description',
            'primary_keyword' => 'primary_keyword',
            'robots_index' => 'robots_index',
            'robots_follow' => 'robots_follow',
            'schema_type' => 'schema_type',
        ];
    }
}
