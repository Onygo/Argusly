<?php

namespace App\Services\Visibility;

use App\Models\Brand;
use App\Models\Competitor;
use App\Models\VisibilityCitation;
use App\Models\VisibilityProviderRun;
use App\Models\VisibilitySource;
use App\Services\EvidenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CitationClassificationService
{
    public const OWNED_SOURCE = 'owned_source';
    public const COMPETITOR_SOURCE = 'competitor_source';
    public const NEUTRAL_SOURCE = 'neutral_source';
    public const MEDIA_SOURCE = 'media_source';
    public const DIRECTORY_SOURCE = 'directory_source';
    public const UNKNOWN_SOURCE = 'unknown_source';

    public const CLASSIFICATIONS = [
        self::OWNED_SOURCE,
        self::COMPETITOR_SOURCE,
        self::NEUTRAL_SOURCE,
        self::MEDIA_SOURCE,
        self::DIRECTORY_SOURCE,
        self::UNKNOWN_SOURCE,
    ];

    /**
     * @return Collection<int, string>
     */
    public function domainsFromAnswer(string $answer): Collection
    {
        preg_match_all('/https?:\/\/[^\s\]\)"\'<>]+/i', $answer, $urls);
        preg_match_all('/(?<!@)\b(?:[a-z0-9-]+\.)+[a-z]{2,}\b/i', $answer, $domains);

        return collect([...(array) ($urls[0] ?? []), ...(array) ($domains[0] ?? [])])
            ->map(fn (string $value): ?string => $this->normalizeDomain($value))
            ->filter()
            ->unique()
            ->values();
    }

    public function normalizeDomain(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $value = rtrim($value, ".,;:!?)]}'\"");
        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host)) {
            $host = parse_url("https://{$value}", PHP_URL_HOST);
        }

        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        $host = Str::lower($host);
        $host = preg_replace('/^www\./', '', $host);

        $host = $host ? trim($host) : null;

        return $host && str_contains($host, '.') ? $host : null;
    }

    public function classifyCitation(VisibilityCitation $citation): VisibilityCitation
    {
        $citation->loadMissing('providerRun.brandModel');
        $run = $citation->providerRun;
        $brand = $run?->brandModel;

        if (! $run || ! $brand) {
            return $citation;
        }

        $domain = $this->normalizeDomain($citation->source_domain ?: $citation->domain ?: $citation->source_url ?: $citation->url);
        $classification = $this->classifyDomain($brand, $domain);
        $confidence = $this->confidenceScore($citation, $classification, $domain);

        $citation->forceFill([
            'visibility_check_id' => $citation->visibility_check_id ?: $run->visibility_check_id,
            'source_url' => $citation->source_url ?: $citation->url,
            'source_domain' => $domain,
            'source_title' => $citation->source_title ?: $citation->title,
            'citation_type' => $classification,
            'is_owned_source' => $classification === self::OWNED_SOURCE,
            'is_competitor_source' => $classification === self::COMPETITOR_SOURCE,
            'confidence_score' => $confidence,
            'metadata_json' => [
                ...($citation->metadata_json ?? $citation->metadata ?? []),
                'classification' => $classification,
                'classified_at' => now()->toIso8601String(),
            ],
            'url' => $citation->url ?: $citation->source_url,
            'domain' => $citation->domain ?: $domain,
            'title' => $citation->title ?: $citation->source_title,
            'trust_score' => $citation->trust_score ?: $confidence,
            'metadata' => [
                ...($citation->metadata ?? []),
                'classification' => $classification,
            ],
        ])->save();

        $this->recordSource($brand, $domain, $classification, $confidence, $citation);
        $this->recordEvidence($citation, $classification, $confidence);

        return $citation->refresh();
    }

    /**
     * @return Collection<int, VisibilityCitation>
     */
    public function classifyRun(VisibilityProviderRun $run): Collection
    {
        $run->loadMissing(['brandModel', 'citations']);
        $brand = $run->brandModel;

        foreach ($this->domainsFromAnswer((string) ($run->raw_response ?: $run->normalized_answer)) as $domain) {
            $run->citations()->firstOrCreate(
                [
                    'source_domain' => $domain,
                ],
                [
                    'account_id' => $run->account_id,
                    'brand_id' => $run->brand_id,
                    'visibility_check_id' => $run->visibility_check_id,
                    'source_url' => "https://{$domain}",
                    'source_title' => Str::headline($domain),
                    'url' => "https://{$domain}",
                    'domain' => $domain,
                    'title' => Str::headline($domain),
                    'citation_type' => self::UNKNOWN_SOURCE,
                    'confidence_score' => 35,
                    'metadata_json' => ['extracted_from' => 'ai_response'],
                    'metadata' => ['extracted_from' => 'ai_response'],
                ],
            );
        }

        return $run->citations()
            ->get()
            ->map(fn (VisibilityCitation $citation): VisibilityCitation => $this->classifyCitation($citation));
    }

    public function classifyDomain(Brand $brand, ?string $domain): string
    {
        if ($domain === null || trim($domain) === '') {
            return self::UNKNOWN_SOURCE;
        }

        if ($this->domainMatches($domain, $this->ownedDomains($brand))) {
            return self::OWNED_SOURCE;
        }

        if ($this->domainMatches($domain, $this->competitorDomains($brand))) {
            return self::COMPETITOR_SOURCE;
        }

        if ($this->isDirectoryDomain($domain)) {
            return self::DIRECTORY_SOURCE;
        }

        if ($this->isMediaDomain($domain)) {
            return self::MEDIA_SOURCE;
        }

        return self::NEUTRAL_SOURCE;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function sourceOverviewForBrand(Brand $brand, array $filters = []): Collection
    {
        return VisibilityCitation::query()
            ->where('account_id', $brand->account_id)
            ->where('brand_id', $brand->id)
            ->with('providerRun.promptTemplate')
            ->when($filters['provider'] ?? null, fn (Builder $query, string $provider) => $query->whereHas('providerRun', fn (Builder $run) => $run->where('provider', $provider)))
            ->when($filters['domain'] ?? null, fn (Builder $query, string $domain) => $query->where(function (Builder $scope) use ($domain): void {
                $scope->where('source_domain', 'like', "%{$domain}%")
                    ->orWhere('domain', 'like', "%{$domain}%");
            }))
            ->get()
            ->map(fn (VisibilityCitation $citation): VisibilityCitation => in_array($citation->citation_type, self::CLASSIFICATIONS, true) ? $citation : $this->classifyCitation($citation))
            ->groupBy(fn (VisibilityCitation $citation): string => (string) ($citation->source_domain ?: $citation->domain ?: 'unknown'))
            ->map(function (Collection $citations, string $domain): array {
                $first = $citations->sortByDesc('created_at')->first();
                $providers = $citations
                    ->map(fn (VisibilityCitation $citation): ?string => $citation->providerRun?->provider)
                    ->filter()
                    ->unique()
                    ->values();
                $prompts = $citations
                    ->map(fn (VisibilityCitation $citation): ?string => $citation->providerRun?->promptTemplate?->name ?: $citation->providerRun?->query)
                    ->filter()
                    ->unique()
                    ->take(5)
                    ->values();

                return [
                    'domain' => $domain,
                    'type' => $first?->citation_type ?: self::UNKNOWN_SOURCE,
                    'seen_count' => $citations->count(),
                    'last_seen_at' => $citations->max('created_at'),
                    'prompts' => $prompts,
                    'providers' => $providers,
                    'is_owned' => (bool) $citations->contains('is_owned_source', true),
                    'is_competitor' => (bool) $citations->contains('is_competitor_source', true),
                    'confidence_score' => (int) round($citations->avg('confidence_score')),
                ];
            })
            ->sortByDesc('seen_count')
            ->values();
    }

    /**
     * @return array<int, string>
     */
    private function ownedDomains(Brand $brand): array
    {
        return collect([
            $brand->domain,
            $brand->website_url,
            ...($brand->getAttribute('settings')['owned_domains'] ?? []),
        ])
            ->map(fn (?string $domain): ?string => $this->normalizeDomain($domain))
            ->filter()
            ->unique()
            ->values()
            ->all();
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
            ->map(fn (?string $domain): ?string => $this->normalizeDomain($domain))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $knownDomains
     */
    private function domainMatches(string $domain, array $knownDomains): bool
    {
        foreach ($knownDomains as $knownDomain) {
            if ($domain === $knownDomain || str_ends_with($domain, ".{$knownDomain}")) {
                return true;
            }
        }

        return false;
    }

    private function isDirectoryDomain(string $domain): bool
    {
        return Str::contains($domain, [
            'g2.',
            'capterra.',
            'clutch.',
            'producthunt.',
            'alternativeto.',
            'directory',
            'marketplace',
            'softwareadvice.',
        ]);
    }

    private function isMediaDomain(string $domain): bool
    {
        return Str::contains($domain, [
            'news',
            'media',
            'magazine',
            'journal',
            'press',
            'forbes.',
            'techcrunch.',
            'wired.',
            'thenextweb.',
        ]);
    }

    private function confidenceScore(VisibilityCitation $citation, string $classification, ?string $domain): int
    {
        $score = $domain ? 45 : 15;
        $score += in_array($classification, [self::OWNED_SOURCE, self::COMPETITOR_SOURCE], true) ? 30 : 10;
        $score += ($citation->source_title || $citation->title) ? 10 : 0;
        $score += ($citation->snippet || $citation->source_url || $citation->url) ? 10 : 0;

        if ($citation->trust_score !== null) {
            $score = (int) round(($score + $citation->trust_score) / 2);
        }

        return max(0, min(100, $score));
    }

    private function recordSource(Brand $brand, ?string $domain, string $classification, int $confidence, VisibilityCitation $citation): void
    {
        if ($domain === null) {
            return;
        }

        VisibilitySource::query()->updateOrCreate(
            [
                'brand_id' => $brand->id,
                'domain' => $domain,
            ],
            [
                'account_id' => $brand->account_id,
                'source_type' => $classification,
                'is_owned' => $classification === self::OWNED_SOURCE,
                'is_competitor' => $classification === self::COMPETITOR_SOURCE,
                'authority_score' => $confidence,
                'last_seen_at' => now(),
                'metadata_json' => [
                    'latest_citation_id' => $citation->id,
                    'latest_provider_run_id' => $citation->provider_run_id,
                    'classification' => $classification,
                ],
            ],
        );
    }

    private function recordEvidence(VisibilityCitation $citation, string $classification, int $confidence): void
    {
        app(EvidenceService::class)->createForSubject($citation, [
            'evidence_type' => 'citation',
            'title' => $citation->source_title ?: $citation->title ?: $citation->source_domain ?: $citation->domain,
            'url' => $citation->source_url ?: $citation->url,
            'snippet' => $citation->snippet,
            'raw_payload' => [
                'classification' => $classification,
                'source_domain' => $citation->source_domain ?: $citation->domain,
                'provider_run_id' => $citation->provider_run_id,
                'visibility_check_id' => $citation->visibility_check_id,
            ],
            'confidence_score' => $confidence,
            'captured_at' => now(),
        ]);
    }
}
