<?php

namespace App\Services\Visibility;

use App\Models\Brand;
use App\Models\Competitor;
use App\Models\VisibilityCitation;
use App\Models\VisibilitySource;
use Illuminate\Support\Collection;

class SourcePresenceAnalyzer
{
    public function __construct(private readonly CitationExtractor $citations) {}

    /**
     * @param  Collection<int, VisibilityCitation>  $citations
     * @return array{citation_score: int, source_presence_score: int, authority_score: int, owned_sources: int, competitor_sources: int, total_sources: int}
     */
    public function analyze(Brand $brand, Collection $citations): array
    {
        if ($citations->isEmpty()) {
            return [
                'citation_score' => 0,
                'source_presence_score' => 0,
                'authority_score' => 0,
                'owned_sources' => 0,
                'competitor_sources' => 0,
                'total_sources' => 0,
            ];
        }

        $competitorDomains = $this->competitorDomains($brand);
        $ownedDomain = $this->citations->domainFromUrl($brand->website_url ?: $brand->domain);

        $normalized = $citations->map(function (VisibilityCitation $citation) use ($brand, $ownedDomain, $competitorDomains): VisibilityCitation {
            $domain = $citation->source_domain ?: $citation->domain ?: $this->citations->domainFromUrl($citation->source_url ?: $citation->url);
            $isOwned = $ownedDomain !== null && $domain !== null && ($domain === $ownedDomain || str_ends_with($domain, ".{$ownedDomain}"));
            $isCompetitor = $domain !== null && in_array($domain, $competitorDomains, true);
            $authorityScore = $this->authorityScore($citation);

            $classification = $isOwned
                ? CitationClassificationService::OWNED_SOURCE
                : ($isCompetitor ? CitationClassificationService::COMPETITOR_SOURCE : ($citation->citation_type ?: CitationClassificationService::NEUTRAL_SOURCE));

            $citation->forceFill([
                'source_domain' => $domain,
                'domain' => $citation->domain ?: $domain,
                'citation_type' => $classification,
                'is_owned_source' => $isOwned,
                'is_competitor_source' => $isCompetitor,
                'confidence_score' => $citation->confidence_score ?: $authorityScore,
                'trust_score' => $citation->trust_score ?: $authorityScore,
            ])->save();

            if ($domain !== null) {
                VisibilitySource::query()->updateOrCreate(
                    [
                        'brand_id' => $brand->id,
                        'domain' => $domain,
                    ],
                    [
                        'account_id' => $brand->account_id,
                        'source_type' => $classification,
                        'is_owned' => $isOwned,
                        'is_competitor' => $isCompetitor,
                        'authority_score' => $authorityScore,
                        'last_seen_at' => now(),
                        'metadata_json' => [
                            'latest_citation_id' => $citation->id,
                            'latest_provider_run_id' => $citation->provider_run_id,
                        ],
                    ],
                );
            }

            return $citation->refresh();
        });

        $total = $normalized->count();
        $owned = $normalized->where('is_owned_source', true)->count();
        $competitor = $normalized->where('is_competitor_source', true)->count();
        $authority = (int) round($normalized->avg(fn (VisibilityCitation $citation): int => $this->authorityScore($citation)));

        return [
            'citation_score' => min(100, 25 + ($total * 18)),
            'source_presence_score' => min(100, (int) round(($owned / max(1, $total)) * 70) + min(30, $total * 5)),
            'authority_score' => $authority,
            'owned_sources' => $owned,
            'competitor_sources' => $competitor,
            'total_sources' => $total,
        ];
    }

    private function authorityScore(VisibilityCitation $citation): int
    {
        $score = $citation->confidence_score ?: $citation->trust_score;

        if (is_numeric($score)) {
            return max(0, min(100, (int) $score));
        }

        return $citation->rank !== null ? max(30, 95 - ((int) $citation->rank * 10)) : 50;
    }

    /**
     * @return array<int, string>
     */
    private function competitorDomains(Brand $brand): array
    {
        return Competitor::query()
            ->where('account_id', $brand->account_id)
            ->where('brand_id', $brand->id)
            ->active()
            ->pluck('website')
            ->map(fn (?string $url): ?string => $this->citations->domainFromUrl($url))
            ->filter()
            ->values()
            ->all();
    }
}
