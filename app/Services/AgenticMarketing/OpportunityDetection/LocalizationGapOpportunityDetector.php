<?php

namespace App\Services\AgenticMarketing\OpportunityDetection;

use App\Enums\AgenticMarketingOpportunityType;
use App\Enums\SupportedLanguage;
use App\Models\AgenticMarketingObjective;
use App\Models\Content;

class LocalizationGapOpportunityDetector implements AgenticMarketingOpportunityDetector
{
    use DetectsObjectiveContent;

    public function detect(AgenticMarketingObjective $objective): array
    {
        $targetLocales = $this->targetLocales($objective);
        if (count($targetLocales) <= 1 || ! $objective->workspace_id) {
            return [];
        }

        $contents = Content::query()
            ->where('workspace_id', $objective->workspace_id)
            ->when($objective->client_site_id, fn ($query) => $query->where('client_site_id', $objective->client_site_id))
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', '!=', 'archived');
            })
            ->where(function ($query): void {
                $query->where('publish_status', 'published')
                    ->orWhere('status', 'published')
                    ->orWhereNotNull('published_url');
            })
            ->get([
                'id',
                'workspace_id',
                'client_site_id',
                'title',
                'language',
                'family_id',
                'translation_source_content_id',
                'is_source_locale',
                'publish_status',
                'published_url',
                'status',
            ]);

        $families = $contents->groupBy(fn (Content $content): string => $this->familyKey($content));

        return $families
            ->map(function ($family) use ($targetLocales): ?DetectedOpportunity {
                $source = $family->firstWhere('is_source_locale', true) ?: $family->first();
                $existingLocales = $family
                    ->map(fn (Content $content): string => $this->stringValue($content->language))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                $missing = array_values(array_diff($targetLocales, $existingLocales));

                if ($missing === []) {
                    return null;
                }

                return new DetectedOpportunity(
                    title: 'Create missing localized variant for ' . (string) $source->title,
                    type: AgenticMarketingOpportunityType::LocaleExpansion,
                    priorityScore: $this->scoreFromSignals(56, min(24, count($missing) * 8)),
                    payload: [
                        'detector' => 'localization_gaps',
                        'content_id' => (string) $source->id,
                        'signals' => [
                            'family_key' => $this->familyKey($source),
                            'source_locale' => $this->stringValue($source->language),
                            'existing_locales' => $existingLocales,
                            'missing_locales' => $missing,
                        ],
                    ],
                    contentId: (string) $source->id,
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function targetLocales(AgenticMarketingObjective $objective): array
    {
        $locales = collect((array) ($objective->languages ?: [$objective->locale]))
            ->push((string) $objective->locale)
            ->map(fn (string $locale): string => SupportedLanguage::fromStringOrDefault($locale)->value)
            ->filter()
            ->unique()
            ->values()
            ->all();

        sort($locales);

        return $locales;
    }

    private function familyKey(Content $content): string
    {
        return (string) ($content->family_id ?: $content->translation_source_content_id ?: $content->id);
    }
}
