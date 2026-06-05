<?php

namespace App\Agents\Localization;

use App\Enums\SupportedLanguage;
use App\Models\Content;
use App\Models\Draft;
use DateTimeInterface;

class LocalizationConsistencyChecker
{
    /**
     * @param array<string,mixed> $input
     * @return array<int,array<string,mixed>>
     */
    public function check(array $input): array
    {
        $issues = [];

        if (($input['resource_type'] ?? '') === 'draft') {
            $this->checkDraft($input, $issues);
        } else {
            $this->checkContent($input, $issues);
        }

        return collect($issues)
            ->sortBy(fn (array $issue): int => $this->severityWeight((string) ($issue['severity'] ?? 'low')))
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $input
     * @param array<int,array<string,mixed>> $issues
     */
    private function checkDraft(array $input, array &$issues): void
    {
        /** @var Draft $draft */
        $draft = $input['draft'];
        $declaredLocale = (string) ($input['declared_locale'] ?? 'en');
        $detected = $this->detectTextLocale(
            (string) ($input['title'] ?? ''),
            (string) ($input['plain_text'] ?? ''),
            $declaredLocale,
        );

        if ($detected['confidence'] !== 'low' && $detected['locale'] !== $declaredLocale) {
            $issues[] = [
                'key' => 'draft_locale_mismatch',
                'severity' => 'high',
                'title' => 'Draft locale label may be wrong',
                'description' => sprintf(
                    'This draft is labeled %s, but the visible text looks %s.',
                    $this->localeLabel($declaredLocale),
                    $this->localeLabel($detected['locale'])
                ),
                'actions' => $this->draftReviewActions($draft),
            ];
        }

        $linkedContentLocale = $input['linked_content_locale'] ?? null;
        if (is_string($linkedContentLocale) && $linkedContentLocale !== '' && $linkedContentLocale !== $declaredLocale) {
            $issues[] = [
                'key' => 'draft_content_locale_mismatch',
                'severity' => 'medium',
                'title' => 'Draft and linked content use different locales',
                'description' => sprintf(
                    'The draft is marked %s, while the linked content record is %s.',
                    $this->localeLabel($declaredLocale),
                    $this->localeLabel($linkedContentLocale)
                ),
                'actions' => $this->draftReviewActions($draft),
            ];
        }

        foreach ((array) ($input['translation_targets'] ?? []) as $target) {
            $targetLocale = SupportedLanguage::normalizeLocale((string) ($target['locale'] ?? ''));
            if (! $targetLocale) {
                continue;
            }

            $issues[] = [
                'key' => 'missing_translation_opportunity',
                'severity' => 'low',
                'title' => sprintf('%s translation opportunity', $this->localeLabel($targetLocale)),
                'description' => sprintf(
                    'This source draft does not have a %s translation yet.',
                    $this->localeLabel($targetLocale)
                ),
                'actions' => [[
                    'type' => 'translate_draft_locale',
                    'label' => sprintf('Create %s translation', strtoupper($targetLocale)),
                    'target_locale' => $targetLocale,
                ]],
            ];
        }

        $sourceDraft = $input['source_draft'] ?? null;
        $sourceDraftLocale = (string) ($input['source_draft_locale'] ?? '');
        if ($sourceDraft instanceof Draft && $draft->isTranslation()) {
            $sourceUpdatedAt = $input['source_draft_updated_at'] ?? null;
            if ($sourceUpdatedAt instanceof DateTimeInterface && $draft->updated_at instanceof DateTimeInterface && $sourceUpdatedAt > $draft->updated_at) {
                $issues[] = [
                    'key' => 'translation_draft_out_of_sync',
                    'severity' => 'medium',
                    'title' => 'Translation draft may need a refresh',
                    'description' => sprintf(
                        'The %s source draft was updated more recently than this translation draft.',
                        strtoupper($sourceDraftLocale)
                    ),
                    'actions' => [[
                        'type' => 'open_draft',
                        'label' => sprintf('Open %s source draft', strtoupper($sourceDraftLocale)),
                        'draft_id' => (string) $sourceDraft->id,
                    ]],
                ];
            }

            $translationSourceLanguage = SupportedLanguage::normalizeLocale((string) ($draft->translation_source_language ?? ''));
            if ($translationSourceLanguage && $sourceDraftLocale !== '' && $translationSourceLanguage !== $sourceDraftLocale) {
                $issues[] = [
                    'key' => 'translation_source_language_mismatch',
                    'severity' => 'medium',
                    'title' => 'Translation source metadata looks inconsistent',
                    'description' => sprintf(
                        'The draft metadata points to %s as the source locale, but the linked source draft is %s.',
                        $this->localeLabel($translationSourceLanguage),
                        $this->localeLabel($sourceDraftLocale)
                    ),
                    'actions' => [[
                        'type' => 'open_draft',
                        'label' => sprintf('Open %s source draft', strtoupper($sourceDraftLocale)),
                        'draft_id' => (string) $sourceDraft->id,
                    ]],
                ];
            }

            $sourceMissingFields = (array) ($input['source_missing_fields'] ?? []);
            $missingFields = (array) ($input['missing_fields'] ?? []);
            $extraMissing = array_values(array_diff($missingFields, $sourceMissingFields));
            if ($extraMissing !== []) {
                $issues[] = [
                    'key' => 'translation_draft_metadata_completeness',
                    'severity' => 'medium',
                    'title' => 'Localized draft metadata is less complete than the source',
                    'description' => 'Missing fields: ' . $this->fieldLabels($extraMissing) . '.',
                    'actions' => [[
                        'type' => 'open_draft',
                        'label' => sprintf('Open %s source draft', strtoupper($sourceDraftLocale)),
                        'draft_id' => (string) $sourceDraft->id,
                    ]],
                ];
            }
        }
    }

    /**
     * @param array<string,mixed> $input
     * @param array<int,array<string,mixed>> $issues
     */
    private function checkContent(array $input, array &$issues): void
    {
        /** @var Content $content */
        $content = $input['content'];
        /** @var Content $sourceContent */
        $sourceContent = $input['source_content'];
        $declaredLocale = (string) ($input['declared_locale'] ?? 'en');
        $sourceLocale = $sourceContent->localeCode();
        $detected = $this->detectTextLocale(
            (string) ($input['title'] ?? ''),
            (string) ($input['plain_text'] ?? ''),
            $declaredLocale,
        );

        if ($detected['confidence'] !== 'low' && $detected['locale'] !== $declaredLocale) {
            $issues[] = [
                'key' => 'content_locale_mismatch',
                'severity' => 'high',
                'title' => 'Content locale label may be wrong',
                'description' => sprintf(
                    'This content is labeled %s, but the visible text looks %s.',
                    $this->localeLabel($declaredLocale),
                    $this->localeLabel($detected['locale'])
                ),
                'actions' => $this->contentReviewActions($content, $sourceContent),
            ];
        }

        foreach ((array) ($input['translation_targets'] ?? []) as $target) {
            $targetLocale = SupportedLanguage::normalizeLocale((string) ($target['locale'] ?? ''));
            if (! $targetLocale) {
                continue;
            }

            if ((string) ($target['action'] ?? 'translate') !== 'translate') {
                continue;
            }

            $issues[] = [
                'key' => 'missing_translation',
                'severity' => 'low',
                'title' => sprintf('%s translation opportunity', $this->localeLabel($targetLocale)),
                'description' => sprintf(
                    'This source article does not have a %s locale version yet.',
                    $this->localeLabel($targetLocale)
                ),
                'actions' => [[
                    'type' => 'translate_content_locale',
                    'label' => sprintf('Create %s translation', strtoupper($targetLocale)),
                    'target_locale' => $targetLocale,
                ]],
            ];
        }

        foreach ((array) ($input['family_matrix'] ?? []) as $variant) {
            /** @var Content|null $variantContent */
            $variantContent = $variant['content'] ?? null;
            if (! $variantContent instanceof Content || (bool) ($variant['is_source'] ?? false)) {
                continue;
            }

            $variantLocale = (string) ($variant['locale'] ?? '');
            if ((bool) ($variant['is_outdated'] ?? false)) {
                $issues[] = [
                    'key' => 'translation_out_of_sync',
                    'severity' => 'medium',
                    'title' => sprintf('%s translation looks out of sync', $this->localeLabel($variantLocale)),
                    'description' => sprintf(
                        'The %s locale version appears older than the source content and may need a refresh.',
                        $this->localeLabel($variantLocale)
                    ),
                    'actions' => [
                        [
                            'type' => 'refresh_content_locale',
                            'label' => sprintf('Refresh %s translation', strtoupper($variantLocale)),
                            'target_locale' => $variantLocale,
                        ],
                        [
                            'type' => 'open_content',
                            'label' => sprintf('Open %s version', strtoupper($variantLocale)),
                            'content_id' => (string) $variantContent->id,
                        ],
                    ],
                ];
            }

            $translationSourceLocale = (string) ($variant['translation_source_locale'] ?? '');
            if ($translationSourceLocale !== '' && $translationSourceLocale !== $sourceLocale) {
                $issues[] = [
                    'key' => 'translation_source_locale_mismatch',
                    'severity' => 'medium',
                    'title' => sprintf('%s source locale metadata looks inconsistent', $this->localeLabel($variantLocale)),
                    'description' => sprintf(
                        'The %s locale version points to %s as its source locale, but the family source is %s.',
                        $this->localeLabel($variantLocale),
                        $this->localeLabel($translationSourceLocale),
                        $this->localeLabel($sourceLocale)
                    ),
                    'actions' => [[
                        'type' => 'open_content',
                        'label' => sprintf('Open %s version', strtoupper($variantLocale)),
                        'content_id' => (string) $variantContent->id,
                    ]],
                ];
            }

            $sourceMissingFields = (array) ($input['source_missing_fields'] ?? []);
            $missingFields = (array) ($variant['missing_fields'] ?? []);
            $extraMissing = array_values(array_diff($missingFields, $sourceMissingFields));
            if ($extraMissing !== []) {
                $issues[] = [
                    'key' => 'localized_metadata_completeness',
                    'severity' => 'medium',
                    'title' => sprintf('%s metadata is less complete than the source', $this->localeLabel($variantLocale)),
                    'description' => 'Missing fields: ' . $this->fieldLabels($extraMissing) . '.',
                    'actions' => [[
                        'type' => 'open_content',
                        'label' => sprintf('Open %s version', strtoupper($variantLocale)),
                        'content_id' => (string) $variantContent->id,
                    ]],
                ];
            }

            if ((bool) ($variant['slug_missing'] ?? false)) {
                $issues[] = [
                    'key' => 'localized_slug_missing',
                    'severity' => 'medium',
                    'title' => sprintf('%s localized slug is missing', $this->localeLabel($variantLocale)),
                    'description' => sprintf(
                        'The %s locale version does not have a localized publish slug yet.',
                        $this->localeLabel($variantLocale)
                    ),
                    'actions' => [[
                        'type' => 'open_content',
                        'label' => sprintf('Open %s version', strtoupper($variantLocale)),
                        'content_id' => (string) $variantContent->id,
                    ]],
                ];
            }
        }
    }

    /**
     * @return array{locale:string,confidence:string}
     */
    private function detectTextLocale(string $title, string $plainText, string $fallbackLocale): array
    {
        $text = mb_strtolower(trim(strip_tags(implode(' ', array_filter([$title, $plainText])))));
        $text = preg_replace('/[^\p{L}\s]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        $scores = collect([
            SupportedLanguage::EN->value => $this->scoreTerms($text, [
                'the', 'and', 'for', 'with', 'this', 'that', 'how', 'your', 'why',
                'english', 'translation', 'guide', 'build',
            ]),
            SupportedLanguage::NL->value => $this->scoreTerms($text, [
                'de', 'het', 'een', 'en', 'voor', 'van', 'met', 'op', 'dit', 'deze',
                'hoe', 'zo', 'je', 'jouw', 'niet', 'welke', 'waarom', 'nederlandse',
            ]),
            SupportedLanguage::DE->value => $this->scoreTerms($text, [
                'der', 'die', 'das', 'und', 'für', 'mit', 'wie', 'warum', 'deutsche',
                'anleitung', 'dies', 'diese', 'nicht', 'aufbau', 'teams',
            ]),
            SupportedLanguage::FR->value => $this->scoreTerms($text, [
                'le', 'la', 'les', 'des', 'pour', 'avec', 'comment', 'pourquoi', 'français',
                'guide', 'ceci', 'cette', 'équipe', 'équipes', 'manuel',
            ]),
            SupportedLanguage::ES->value => $this->scoreTerms($text, [
                'el', 'la', 'los', 'las', 'para', 'con', 'como', 'por', 'qué',
                'español', 'guía', 'este', 'esta', 'equipo', 'equipos', 'manual',
            ]),
        ])->sortDesc();

        $bestLocale = (string) ($scores->keys()->first() ?? $fallbackLocale);
        $bestScore = (int) ($scores->first() ?? 0);
        $secondScore = (int) ($scores->skip(1)->first() ?? 0);

        return match (true) {
            $bestScore === 0 => ['locale' => $fallbackLocale, 'confidence' => 'low'],
            $bestScore >= max(2, $secondScore + 2) => ['locale' => $bestLocale, 'confidence' => 'high'],
            $bestScore > $secondScore => ['locale' => $bestLocale, 'confidence' => 'medium'],
            default => ['locale' => $fallbackLocale, 'confidence' => 'low'],
        };
    }

    /**
     * @param array<int,string> $terms
     */
    private function scoreTerms(string $text, array $terms): int
    {
        $score = 0;

        foreach ($terms as $term) {
            $score += preg_match_all('/\b' . preg_quote($term, '/') . '\b/u', $text);
        }

        return $score;
    }

    private function localeLabel(string $locale): string
    {
        $language = SupportedLanguage::fromStringOrDefault($locale);

        return $language->englishLabel();
    }

    /**
     * @param array<int,string> $fields
     */
    private function fieldLabels(array $fields): string
    {
        return collect($fields)
            ->map(fn (string $field): string => match ($field) {
                'seo_title' => 'SEO title',
                'seo_meta_description' => 'meta description',
                'seo_h1' => 'H1',
                'publish_url_key' => 'localized slug',
                default => str_replace('_', ' ', $field),
            })
            ->implode(', ');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function draftReviewActions(Draft $draft): array
    {
        $actions = [];

        if ($draft->sourceDraft) {
            $actions[] = [
                'type' => 'open_draft',
                'label' => sprintf('Open %s source draft', strtoupper((string) $draft->sourceDraft->language->value)),
                'draft_id' => (string) $draft->sourceDraft->id,
            ];
        }

        if ($draft->content_id) {
            $actions[] = [
                'type' => 'open_content',
                'label' => 'Open linked content',
                'content_id' => (string) $draft->content_id,
            ];
        }

        return $actions;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function contentReviewActions(Content $content, Content $sourceContent): array
    {
        $actions = [];

        if ((string) $content->id !== (string) $sourceContent->id) {
            $actions[] = [
                'type' => 'open_content',
                'label' => sprintf('Open %s source', strtoupper($sourceContent->localeCode())),
                'content_id' => (string) $sourceContent->id,
            ];
        }

        $actions[] = [
            'type' => 'open_content',
            'label' => 'Open current content',
            'content_id' => (string) $content->id,
        ];

        return $actions;
    }

    private function severityWeight(string $severity): int
    {
        return match ($severity) {
            'high' => 0,
            'medium' => 1,
            default => 2,
        };
    }
}
