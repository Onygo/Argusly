<?php

namespace App\Services\Content;

use App\Models\Content;
use App\Services\Publication\ContentPublicationStateService;
use App\Services\Seo\CanonicalUrlService;

class LocaleIntegrityValidationService
{
    public function __construct(
        private readonly ContentPublicationStateService $publicationState,
        private readonly CanonicalUrlService $canonicals,
    ) {
    }

    /**
     * @return array{
     *   family_locales:array<int,string>,
     *   published_locales:array<int,string>,
     *   alternates:array<string,string>,
     *   issues:array<int,array{code:string,severity:string,message:string,details?:array<string,mixed>}>
     * }
     */
    public function validate(Content $content): array
    {
        $content->loadMissing(['familyRoot', 'translationSourceContent', 'localizedVariants.currentVersion', 'localizedVariants.publications', 'currentVersion', 'publications']);

        $family = $content->normalizedLocalizationFamily();
        $allVariants = $family->prepend($content)->unique('id')->values();
        $published = $allVariants
            ->filter(fn (Content $variant): bool => $this->publicationState->isPublished($variant))
            ->values();

        $issues = [];
        $seenLocales = [];

        foreach ($allVariants as $variant) {
            $locale = $variant->localeCode();

            if (isset($seenLocales[$locale])) {
                $issues[] = [
                    'code' => 'duplicate_locale_content',
                    'severity' => 'critical',
                    'message' => sprintf('Multiple content records exist for locale %s in the same family.', strtoupper($locale)),
                    'details' => [
                        'locale' => $locale,
                        'content_ids' => [$seenLocales[$locale], (string) $variant->id],
                    ],
                ];
            }

            $seenLocales[$locale] = (string) $variant->id;

            $expectedCanonical = $this->canonicals->expectedCanonicalForContent($variant);
            $storedCanonical = $this->canonicals->normalize((string) ($variant->seo_canonical ?? ''));

            if ($expectedCanonical !== null && $storedCanonical !== null && ! $this->canonicals->equivalent($expectedCanonical, $storedCanonical)) {
                $issues[] = [
                    'code' => 'cross_locale_or_stale_canonical',
                    'severity' => 'high',
                    'message' => sprintf('Stored canonical for %s does not match the expected locale route.', strtoupper($locale)),
                    'details' => [
                        'locale' => $locale,
                        'expected' => $expectedCanonical,
                        'stored' => $storedCanonical,
                    ],
                ];
            }
        }

        $alternates = $published
            ->mapWithKeys(function (Content $variant): array {
                $canonical = $this->canonicals->expectedCanonicalForContent($variant);

                return $canonical ? [$variant->localeCode() => $canonical] : [];
            })
            ->all();

        if (count($published) > 1 && count($alternates) !== count($published)) {
            $issues[] = [
                'code' => 'missing_alternates',
                'severity' => 'high',
                'message' => 'One or more published locale variants are missing canonical alternates.',
            ];
        }

        return [
            'family_locales' => $allVariants->map(fn (Content $variant): string => $variant->localeCode())->unique()->values()->all(),
            'published_locales' => $published->map(fn (Content $variant): string => $variant->localeCode())->unique()->values()->all(),
            'alternates' => $alternates,
            'issues' => $issues,
        ];
    }
}
