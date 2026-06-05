<?php

namespace App\Services\Content;

use App\Enums\SupportedLanguage;
use App\Models\Content;
use App\Models\Draft;
use App\Support\LanguageDetector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Service for detecting and resolving locale mismatches in content.
 */
class LocaleMismatchService
{
    private const DUTCH_AUTO_FIX_CONFIDENCE = 0.6;

    public function __construct(
        private readonly LanguageDetector $detector,
        private readonly ContentLocalizationService $localizationService,
    ) {}

    /**
     * Analyze content for locale mismatch.
     *
     * @return array{
     *     has_mismatch: bool,
     *     declared_locale: string,
     *     detected_locale: string|null,
     *     confidence: float,
     *     can_auto_fix: bool,
     *     fix_action: string|null,
     *     analysis: array<string, mixed>
     * }
     */
    public function analyze(Content $content): array
    {
        $text = $this->extractTextForAnalysis($content);
        $declaredLocale = $content->localeCode();

        if (mb_strlen($text) < 100) {
            return [
                'has_mismatch' => false,
                'declared_locale' => $declaredLocale,
                'detected_locale' => null,
                'confidence' => 0.0,
                'can_auto_fix' => false,
                'fix_action' => null,
                'analysis' => ['reason' => 'insufficient_content'],
            ];
        }

        $detection = $this->detector->detectMismatch($text, $declaredLocale);

        $canAutoFix = false;
        $fixAction = null;
        $fixBlockReason = null;
        $conflictingContentId = null;
        $canSwapLocales = false;

        if ($detection['is_mismatched'] && $detection['suggested_locale'] !== null) {
            $suggestedLanguage = SupportedLanguage::tryFrom($detection['suggested_locale']);

            if ($suggestedLanguage !== null) {
                // Check if we can safely fix this
                $fixDetails = $this->canSafelyFixLocaleDetails($content, $suggestedLanguage);
                $canAutoFix = $fixDetails['can_fix'];
                $fixBlockReason = $fixDetails['reason'];
                $conflictingContentId = $fixDetails['conflicting_content_id'];
                $fixAction = $this->determineFixAction($content, $suggestedLanguage);

                // If blocked due to existing variant, check if we can swap locales
                if (! $canAutoFix && $conflictingContentId !== null && $content->is_source_locale) {
                    $canSwapLocales = true;
                    $fixAction = 'swap_inverted_locales';
                }
            }
        }

        return [
            'has_mismatch' => $detection['is_mismatched'],
            'declared_locale' => $declaredLocale,
            'detected_locale' => $detection['suggested_locale'],
            'confidence' => $detection['confidence'],
            'can_auto_fix' => $canAutoFix,
            'can_swap_locales' => $canSwapLocales,
            'fix_action' => $fixAction,
            'fix_block_reason' => $fixBlockReason,
            'conflicting_content_id' => $conflictingContentId,
            'analysis' => [
                'detected_language' => $detection['detected_language']?->value,
                'detection_confidence' => $detection['confidence'],
            ],
        ];
    }

    /**
     * Auto-correct a source row when it is stored as English but the content is Dutch.
     *
     * @return array{changed: bool, content: Content, reason: string|null}
     */
    public function autoCorrectSourceLocale(Content $content): array
    {
        $content->loadMissing(['currentVersion', 'brief', 'drafts', 'translationSourceContent']);

        $analysis = $this->analyze($content);
        $shouldAutoSwitch = $content->localeCode() === SupportedLanguage::EN->value
            && $this->detector->isDutch($this->extractTextForAnalysis($content), self::DUTCH_AUTO_FIX_CONFIDENCE);

        if (! $shouldAutoSwitch) {
            if ((bool) $content->is_source_locale) {
                $this->enforceSingleSourceForContent($content);
            }

            return [
                'changed' => false,
                'content' => $content,
                'reason' => null,
            ];
        }

        $fixDetails = $this->canSafelyFixLocaleDetails($content, SupportedLanguage::NL);

        if (! $fixDetails['can_fix']) {
            throw new RuntimeException(
                'Source content appears to be Dutch but is stored as English. Fix the locale before translating.'
            );
        }

        $result = $content->isTranslationVariant()
            ? $this->fixLocale($content, SupportedLanguage::NL)
            : $this->fixLocaleAndSetAsSource($content, SupportedLanguage::NL);

        if (! $result['success']) {
            throw new RuntimeException((string) $result['message']);
        }

        /** @var Content $fresh */
        $fresh = $content->fresh(['currentVersion', 'brief', 'drafts', 'translationSourceContent']) ?? $content;

        return [
            'changed' => true,
            'content' => $fresh,
            'reason' => 'english_content_detected_as_dutch',
        ];
    }

    /**
     * Auto-correct original source content when a generated or saved draft is Dutch but stored as English.
     *
     * @return array{changed: bool, content: ?Content, reason: string|null}
     */
    public function autoCorrectFromDraft(Draft $draft): array
    {
        $draft->loadMissing(['content.currentVersion', 'content.translationSourceContent', 'brief']);

        $content = $draft->content;
        if (! $content instanceof Content) {
            return [
                'changed' => false,
                'content' => null,
                'reason' => null,
            ];
        }

        if ($content->isTranslationVariant()) {
            return [
                'changed' => false,
                'content' => $content,
                'reason' => null,
            ];
        }

        $draftLocale = SupportedLanguage::fromStringOrDefault((string) $draft->getRawOriginal('language'))->value;
        if ($content->localeCode() !== SupportedLanguage::EN->value && $draftLocale !== SupportedLanguage::EN->value) {
            return [
                'changed' => false,
                'content' => $content,
                'reason' => null,
            ];
        }

        $text = $this->extractTextForDraftAnalysis($draft, $content);
        if (! $this->detector->isDutch($text, self::DUTCH_AUTO_FIX_CONFIDENCE)) {
            return [
                'changed' => false,
                'content' => $content,
                'reason' => null,
            ];
        }

        try {
            $result = $this->autoCorrectSourceLocale($content);
        } catch (RuntimeException $exception) {
            Log::warning('content.locale_autocorrect_blocked', [
                'content_id' => (string) $content->id,
                'draft_id' => (string) $draft->id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'changed' => false,
                'content' => $content,
                'reason' => 'autocorrect_blocked',
            ];
        }

        return [
            'changed' => $result['changed'],
            'content' => $result['content'],
            'reason' => $result['reason'],
        ];
    }

    /**
     * Fix locale mismatch for a single content item.
     *
     * @return array{success: bool, message: string, old_locale: string, new_locale: string|null}
     */
    public function fixLocale(Content $content, SupportedLanguage $correctLocale): array
    {
        $oldLocale = $content->localeCode();

        if ($oldLocale === $correctLocale->value) {
            return [
                'success' => true,
                'message' => 'Locale already correct',
                'old_locale' => $oldLocale,
                'new_locale' => $correctLocale->value,
            ];
        }

        // Check if this would create a duplicate in the family
        if (! $this->canSafelyFixLocale($content, $correctLocale)) {
            return [
                'success' => false,
                'message' => 'Cannot fix locale: would create duplicate in translation family',
                'old_locale' => $oldLocale,
                'new_locale' => null,
            ];
        }

        return DB::transaction(function () use ($content, $correctLocale, $oldLocale): array {
            $isTranslationVariant = $content->translation_source_content_id !== null;

            $content->language = $correctLocale;

            if ($isTranslationVariant) {
                $content->is_source_locale = false;
                $content->translation_source_locale = $content->translationSourceContent?->localeCode()
                    ?? $content->translation_source_locale;
            } else {
                $content->translation_source_content_id = null;
                $content->translation_source_version_id = null;
                $content->translation_source_locale = null;
                $content->is_source_locale = true;

                if (Content::supportsFamilyId()) {
                    $content->family_id = $content->id;
                }
            }

            $content->save();

            $this->syncLinkedLocales($content, $correctLocale);

            if (! $isTranslationVariant) {
                $this->enforceSingleSourceForContent($content);
            }

            Log::info('Fixed content locale mismatch', [
                'content_id' => $content->id,
                'old_locale' => $oldLocale,
                'new_locale' => $correctLocale->value,
            ]);

            return [
                'success' => true,
                'message' => "Locale changed from {$oldLocale} to {$correctLocale->value}",
                'old_locale' => $oldLocale,
                'new_locale' => $correctLocale->value,
            ];
        });
    }

    /**
     * Fix locale and mark as source, updating family structure.
     *
     * @return array{success: bool, message: string, changes: array<string, mixed>}
     */
    public function fixLocaleAndSetAsSource(Content $content, SupportedLanguage $correctLocale): array
    {
        $fixDetails = $this->canSafelyFixLocaleDetails($content, $correctLocale);

        if (! $fixDetails['can_fix']) {
            return [
                'success' => false,
                'message' => (string) ($fixDetails['reason'] ?? 'Locale fix is blocked.'),
                'changes' => [],
            ];
        }

        return DB::transaction(function () use ($content, $correctLocale): array {
            $changes = [];
            $oldLocale = $content->localeCode();
            $previousFamilyId = trim((string) ($content->family_id ?? ''));
            $previousSourceId = trim((string) ($content->translation_source_content_id ?? ''));

            // First, fix the locale
            if ($oldLocale !== $correctLocale->value) {
                $content->language = $correctLocale;
                $changes['locale'] = ['from' => $oldLocale, 'to' => $correctLocale->value];
            }

            // Clear any incorrect source references
            if ($content->translation_source_content_id !== null) {
                $changes['cleared_source_reference'] = $content->translation_source_content_id;
                $content->translation_source_content_id = null;
                $content->translation_source_version_id = null;
                $content->translation_source_locale = null;
            }

            // Mark as source
            if (! $content->is_source_locale) {
                $changes['set_as_source'] = true;
                $content->is_source_locale = true;
            }

            // Ensure family_id is set correctly (to self for source)
            if ($content->family_id !== $content->id) {
                $changes['family_id'] = ['from' => $content->family_id, 'to' => $content->id];
                $content->family_id = $content->id;
            }

            $content->save();

            $this->syncLinkedLocales($content, $correctLocale);
            $this->updateFamilyReferences($content, $previousFamilyId, $previousSourceId);
            $this->enforceSingleSourceForContent($content);

            Log::info('Fixed locale and set as source', [
                'content_id' => $content->id,
                'changes' => $changes,
            ]);

            return [
                'success' => true,
                'message' => 'Locale fixed and content set as source',
                'changes' => $changes,
            ];
        });
    }

    /**
     * Find all content with potential locale mismatches.
     *
     * @return Collection<int, array{content: Content, analysis: array<string, mixed>}>
     */
    public function findMismatches(?string $siteId = null, int $limit = 100): Collection
    {
        $query = Content::query()
            ->with(['currentVersion', 'clientSite'])
            ->whereNotNull('language')
            ->where('status', '!=', 'archived')
            ->orderByDesc('updated_at')
            ->limit($limit);

        if ($siteId !== null) {
            $query->where('client_site_id', $siteId);
        }

        return $query->get()
            ->map(function (Content $content): ?array {
                $analysis = $this->analyze($content);

                if (! $analysis['has_mismatch']) {
                    return null;
                }

                return [
                    'content' => $content,
                    'analysis' => $analysis,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Validate source locale before translation.
     *
     * Used by ContentTranslationCoordinator to ensure source is correct.
     *
     * @return array{valid: bool, issues: array<int, string>, suggested_fix: string|null}
     */
    public function validateSourceForTranslation(Content $sourceContent): array
    {
        $issues = [];
        $suggestedFix = null;

        $analysis = $this->analyze($sourceContent);

        if ($analysis['has_mismatch']) {
            $issues[] = sprintf(
                'Content locale (%s) does not match detected language (%s) with %.0f%% confidence',
                $analysis['declared_locale'],
                $analysis['detected_locale'],
                $analysis['confidence'] * 100
            );

            if ($this->shouldAutoSwitchEnglishToDutch($analysis) && $analysis['can_auto_fix']) {
                $suggestedFix = "fix_locale_to_{$analysis['detected_locale']}";
            }
        }

        // Check if this content is incorrectly marked as source
        if (! $sourceContent->is_source_locale && $sourceContent->translation_source_content_id === null) {
            $issues[] = 'Content is not marked as source but has no translation source reference';
        }

        // Warn if content appears to be English but claims to be Dutch source
        if ($analysis['declared_locale'] === 'nl' && $analysis['detected_locale'] === 'en') {
            $issues[] = 'Dutch source appears to contain English content - translations may be inverted';
        }

        if ($this->shouldAutoSwitchEnglishToDutch($analysis)) {
            $issues[] = 'English cannot be used as the translation source because the stored content is Dutch.';
        }

        return [
            'valid' => count($issues) === 0,
            'issues' => $issues,
            'suggested_fix' => $suggestedFix,
        ];
    }

    /**
     * Enforce single source locale per content chain.
     *
     * @return array{fixed: int, errors: array<int, string>}
     */
    public function enforceSingleSourcePerFamily(string $familyId): array
    {
        $fixed = 0;
        $errors = [];

        $familyMembers = Content::query()
            ->where('family_id', $familyId)
            ->orderBy('created_at')
            ->get();

        if ($familyMembers->isEmpty()) {
            return ['fixed' => 0, 'errors' => ['No content found for family']];
        }

        $sources = $familyMembers->filter(fn (Content $c) => $c->is_source_locale);

        if ($sources->count() <= 1) {
            return ['fixed' => 0, 'errors' => []];
        }

        // Multiple sources found - keep the oldest one.
        $trueSource = $sources->sortBy('created_at')->first();

        DB::transaction(function () use ($trueSource, &$fixed) {
            $fixed = $this->enforceSingleSourceForContent($trueSource);
        });

        Log::info('Enforced single source per family', [
            'family_id' => $familyId,
            'true_source_id' => $trueSource->id,
            'fixed_count' => $fixed,
        ]);

        return ['fixed' => $fixed, 'errors' => $errors];
    }

    public function enforceSingleSourceForContent(Content $sourceContent): int
    {
        if (! (bool) $sourceContent->is_source_locale) {
            return 0;
        }

        $sourceContent->loadMissing('translationSourceContent');

        $familyIds = collect([
            (string) ($sourceContent->family_id ?? ''),
            (string) ($sourceContent->translation_source_content_id ?? ''),
            (string) $sourceContent->id,
        ])->filter()->unique()->values();

        if ($familyIds->isEmpty()) {
            return 0;
        }

        $query = Content::query()
            ->whereKeyNot((string) $sourceContent->id)
            ->where(function ($familyQuery) use ($familyIds): void {
                if (Content::supportsFamilyId()) {
                    $familyQuery->whereIn('family_id', $familyIds->all())
                        ->orWhereIn('translation_source_content_id', $familyIds->all());

                    return;
                }

                $familyQuery->whereIn('translation_source_content_id', $familyIds->all());
            });

        $updated = 0;

        foreach ($query->get() as $member) {
            $attributes = [
                'translation_source_content_id' => (string) $sourceContent->id,
                'translation_source_version_id' => $sourceContent->current_version_id ?: $member->translation_source_version_id,
                'translation_source_locale' => $sourceContent->localeCode(),
                'is_source_locale' => false,
            ];

            if (Content::supportsFamilyId()) {
                $attributes['family_id'] = (string) $sourceContent->id;
            }

            $changes = array_filter(
                $attributes,
                fn ($value, string $key): bool => (string) data_get($member, $key) !== (string) $value || ($key === 'is_source_locale' && (bool) $member->is_source_locale !== false),
                ARRAY_FILTER_USE_BOTH
            );

            if ($changes === []) {
                continue;
            }

            $member->forceFill($attributes)->saveQuietly();
            $updated++;
        }

        return $updated;
    }

    /**
     * Swap locales between two content items in a family (for inverted translations).
     *
     * @return array{success: bool, message: string, changes: array<string, mixed>}
     */
    public function swapInvertedLocales(Content $sourceContent, Content $variantContent): array
    {
        $sourceLocale = $sourceContent->localeCode();
        $variantLocale = $variantContent->localeCode();

        if ($sourceLocale === $variantLocale) {
            return [
                'success' => false,
                'message' => 'Cannot swap - both content items have the same locale',
                'changes' => [],
            ];
        }

        return DB::transaction(function () use ($sourceContent, $variantContent, $sourceLocale, $variantLocale): array {
            $changes = [];

            // Swap the locales
            $sourceContent->language = SupportedLanguage::from($variantLocale);
            $variantContent->language = SupportedLanguage::from($sourceLocale);

            // Update source/translation relationships
            // The old source becomes a translation of the old variant
            $sourceContent->is_source_locale = false;
            $sourceContent->translation_source_content_id = $variantContent->id;
            $sourceContent->translation_source_locale = $variantLocale;

            // The old variant becomes the source
            $variantContent->is_source_locale = true;
            $variantContent->translation_source_content_id = null;
            $variantContent->translation_source_locale = null;

            // Update family_id to point to the new source
            $sourceContent->family_id = $variantContent->id;
            $variantContent->family_id = $variantContent->id;

            $sourceContent->saveQuietly();
            $variantContent->saveQuietly();

            // Update any other family members to point to the new source
            Content::query()
                ->where('family_id', $sourceContent->id)
                ->where('id', '!=', $sourceContent->id)
                ->where('id', '!=', $variantContent->id)
                ->update([
                    'family_id' => $variantContent->id,
                    'translation_source_content_id' => $variantContent->id,
                    'translation_source_locale' => $variantLocale,
                ]);

            $changes['swapped'] = [
                'old_source' => ['id' => $sourceContent->id, 'old_locale' => $sourceLocale, 'new_locale' => $variantLocale],
                'new_source' => ['id' => $variantContent->id, 'old_locale' => $variantLocale, 'new_locale' => $sourceLocale],
            ];

            Log::info('Swapped inverted locales', [
                'old_source_id' => $sourceContent->id,
                'new_source_id' => $variantContent->id,
                'changes' => $changes,
            ]);

            return [
                'success' => true,
                'message' => "Locales swapped: source is now {$sourceLocale}, variant is now {$variantLocale}",
                'changes' => $changes,
            ];
        });
    }

    /**
     * Check if we can safely fix the locale without creating conflicts.
     *
     * @return array{can_fix: bool, reason: string|null, conflicting_content_id: string|null}
     */
    private function canSafelyFixLocaleDetails(Content $content, SupportedLanguage $newLocale): array
    {
        // Check if another content in the same family already has this locale
        if ($content->family_id !== null) {
            $conflictingContent = Content::query()
                ->where('family_id', $content->family_id)
                ->where('id', '!=', $content->id)
                ->where('language', $newLocale->value)
                ->where('status', '!=', 'archived')
                ->first(['id', 'title', 'language']);

            if ($conflictingContent) {
                return [
                    'can_fix' => false,
                    'reason' => "Family already has {$newLocale->englishLabel()} content",
                    'conflicting_content_id' => $conflictingContent->id,
                ];
            }
        }

        return [
            'can_fix' => true,
            'reason' => null,
            'conflicting_content_id' => null,
        ];
    }

    /**
     * Check if we can safely fix the locale without creating conflicts.
     */
    private function canSafelyFixLocale(Content $content, SupportedLanguage $newLocale): bool
    {
        return $this->canSafelyFixLocaleDetails($content, $newLocale)['can_fix'];
    }

    /**
     * Determine the appropriate fix action for a mismatch.
     */
    private function determineFixAction(Content $content, SupportedLanguage $suggestedLocale): string
    {
        if ($content->is_source_locale) {
            return 'fix_source_locale';
        }

        if ($content->translation_source_content_id !== null) {
            return 'fix_translation_locale';
        }

        return 'fix_locale';
    }

    /**
     * Extract text content for language detection.
     */
    private function extractTextForAnalysis(Content $content): string
    {
        $parts = [];

        // Title
        if ($content->title) {
            $parts[] = $content->title;
        }

        // Body from current version
        $version = $content->currentVersion;
        if ($version !== null && ! empty($version->body)) {
            $parts[] = strip_tags((string) $version->body);
        }

        // SEO metadata
        if ($content->seo_meta_description) {
            $parts[] = $content->seo_meta_description;
        }

        return implode(' ', $parts);
    }

    private function extractTextForDraftAnalysis(Draft $draft, Content $content): string
    {
        return trim(implode(' ', array_filter([
            (string) $draft->title,
            strip_tags((string) ($draft->content_html ?? '')),
            (string) $content->title,
            (string) $content->seo_meta_description,
        ])));
    }

    /**
     * Update family references when a content becomes the source.
     */
    private function updateFamilyReferences(
        Content $sourceContent,
        ?string $previousFamilyId = null,
        ?string $previousSourceId = null,
    ): void
    {
        $familyIds = collect([
            $previousFamilyId,
            $previousSourceId,
            (string) ($sourceContent->family_id ?? ''),
            (string) $sourceContent->id,
        ])->filter()->unique()->values();

        Content::query()
            ->whereKeyNot((string) $sourceContent->id)
            ->where(function ($query) use ($familyIds): void {
                if (Content::supportsFamilyId()) {
                    $query->whereIn('family_id', $familyIds->all())
                        ->orWhereIn('translation_source_content_id', $familyIds->all());

                    return;
                }

                $query->whereIn('translation_source_content_id', $familyIds->all());
            })
            ->get()
            ->each(function (Content $member) use ($sourceContent): void {
                $member->forceFill([
                    'family_id' => Content::supportsFamilyId() ? (string) $sourceContent->id : $member->family_id,
                    'translation_source_content_id' => (string) $sourceContent->id,
                    'translation_source_version_id' => $sourceContent->current_version_id ?: $member->translation_source_version_id,
                    'translation_source_locale' => $sourceContent->localeCode(),
                    'is_source_locale' => false,
                ])->saveQuietly();
            });
    }

    private function syncLinkedLocales(Content $content, SupportedLanguage $locale): void
    {
        $content->loadMissing(['brief', 'drafts']);

        if ($content->brief !== null && SupportedLanguage::fromStringOrDefault((string) $content->brief->language)->value !== $locale->value) {
            $content->brief->forceFill([
                'language' => $locale->value,
            ])->saveQuietly();
        }

        foreach ($content->drafts as $draft) {
            $meta = is_array($draft->meta) ? $draft->meta : [];
            $meta['language'] = $locale->value;

            $draft->forceFill([
                'language' => $locale->value,
                'meta' => $meta,
            ])->saveQuietly();
        }
    }

    /**
     * @param  array<string,mixed>  $analysis
     */
    private function shouldAutoSwitchEnglishToDutch(array $analysis): bool
    {
        return (string) ($analysis['declared_locale'] ?? '') === SupportedLanguage::EN->value
            && (string) ($analysis['detected_locale'] ?? '') === SupportedLanguage::NL->value
            && (float) ($analysis['confidence'] ?? 0.0) >= self::DUTCH_AUTO_FIX_CONFIDENCE;
    }
}
