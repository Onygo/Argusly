<?php

namespace App\Services\AiVisibility;

use App\Models\BrandContext;
use App\Models\ClientSite;
use App\Models\CompanyIntelligenceProfile;
use App\Models\CompanyProfile;
use App\Models\LlmTrackingQuery;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AiVisibilityStarterQueryService
{
    public const CATEGORY_BRAND_VISIBILITY = 'brand_visibility';
    public const CATEGORY_COMPETITOR_COMPARISON = 'competitor_comparison';
    public const CATEGORY_BUYER_INTENT = 'buyer_intent';
    public const CATEGORY_AUTHORITY = 'authority';
    public const CATEGORY_CATEGORY_LEADERSHIP = 'category_leadership';

    public const MAX_QUERIES = 10;

    /**
     * @param Collection<int,SiteCompetitor>|array<int,SiteCompetitor> $competitors
     */
    public function suggest(
        Workspace $workspace,
        ClientSite $site,
        ?CompanyProfile $companyProfile = null,
        ?CompanyIntelligenceProfile $companyIntelligence = null,
        Collection|array $competitors = [],
    ): SuggestedQueryCollection {
        $competitors = collect($competitors);
        $brandContext = BrandContext::query()
            ->where('workspace_id', $workspace->id)
            ->latest()
            ->first();

        $brandName = $this->brandName($workspace, $companyProfile, $companyIntelligence, $brandContext);
        $topic = $this->firstContextValue([
            $companyIntelligence?->primary_topics,
            $companyIntelligence?->authority_areas,
            data_get($brandContext?->structured_json ?? [], 'primary_topics'),
            data_get($brandContext?->structured_json ?? [], 'authority_areas'),
        ], 'your category');
        $service = $this->firstContextValue([
            $companyIntelligence?->products_services,
            $companyProfile?->keyServicesArray(),
            $companyIntelligence?->strategic_keywords,
        ], $topic);
        $category = trim((string) ($companyIntelligence?->market_category ?: $companyProfile?->industry ?: $topic)) ?: 'your category';
        $competitorNames = $competitors
            ->pluck('name')
            ->merge((array) ($companyIntelligence?->direct_competitors ?? []))
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => Str::lower($value))
            ->values();

        $primaryCompetitor = (string) ($competitorNames->get(0) ?: 'leading competitors');
        $secondaryCompetitor = (string) ($competitorNames->get(1) ?: $primaryCompetitor);

        $candidates = [
            $this->query('brand-1', "what is {$brandName}", self::CATEGORY_BRAND_VISIBILITY, 'brand_discovery', 88, 'Checks whether AI systems understand the brand by name.'),
            $this->query('brand-2', "{$brandName} review for {$topic}", self::CATEGORY_BRAND_VISIBILITY, 'brand_evaluation', 84, 'Surfaces brand reputation, positioning and context quality.'),
            $this->query('brand-3', "is {$brandName} a good option for {$service}", self::CATEGORY_BRAND_VISIBILITY, 'brand_fit', 82, 'Tests whether the brand appears for a service-specific evaluation question.'),

            $this->query('competitor-1', "alternatives to {$primaryCompetitor}", self::CATEGORY_COMPETITOR_COMPARISON, 'alternative_search', $competitorNames->isNotEmpty() ? 90 : 66, 'Finds whether your brand appears when buyers ask for competitor alternatives.'),
            $this->query('competitor-2', "{$brandName} vs {$primaryCompetitor}", self::CATEGORY_COMPETITOR_COMPARISON, 'direct_comparison', $competitorNames->isNotEmpty() ? 88 : 64, 'Tests direct comparison visibility against the primary competitor.'),
            $this->query('competitor-3', "best alternatives to {$secondaryCompetitor} for {$topic}", self::CATEGORY_COMPETITOR_COMPARISON, 'competitor_switching', $competitorNames->count() > 1 ? 86 : 62, 'Captures switching intent around competitor-led searches.'),

            $this->query('buyer-1', "best {$service}", self::CATEGORY_BUYER_INTENT, 'best_solution', 82, 'Tracks high-intent category discovery for your service.'),
            $this->query('buyer-2', "best provider for {$topic}", self::CATEGORY_BUYER_INTENT, 'provider_selection', 80, 'Checks whether AI systems recommend the brand for buyer selection.'),

            $this->query('authority-1', "who are experts in {$topic}", self::CATEGORY_AUTHORITY, 'expert_discovery', 78, 'Measures authority visibility for the strategic topic.'),
            $this->query('leadership-1', "top companies for {$category}", self::CATEGORY_CATEGORY_LEADERSHIP, 'category_leadership', 76, 'Looks for category-level leadership and market shortlist visibility.'),
        ];

        $existing = LlmTrackingQuery::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_site_id', $site->id)
            ->pluck('query_text')
            ->map(fn ($value): string => $this->fingerprint((string) $value))
            ->all();

        $seen = collect($existing)->flip();
        $queries = [];

        foreach ($candidates as $candidate) {
            $fingerprint = $this->fingerprint($candidate->queryText);

            if ($fingerprint === '' || $seen->has($fingerprint)) {
                continue;
            }

            $seen->put($fingerprint, true);
            $queries[] = $candidate;

            if (count($queries) >= self::MAX_QUERIES) {
                break;
            }
        }

        return new SuggestedQueryCollection($queries);
    }

    private function query(string $key, string $text, string $category, string $intent, int $confidence, string $explanation): SuggestedQuery
    {
        return new SuggestedQuery(
            key: $key,
            queryText: $this->normalizeText($text),
            category: $category,
            intent: $intent,
            confidenceScore: max(1, min(100, $confidence)),
            explanation: $explanation,
        );
    }

    private function brandName(Workspace $workspace, ?CompanyProfile $profile, ?CompanyIntelligenceProfile $intelligence, ?BrandContext $context): string
    {
        $name = trim((string) ($intelligence?->company_name ?: $profile?->company_name ?: data_get($context?->structured_json ?? [], 'company_name') ?: $workspace->display_name ?: $workspace->name));

        return $name !== '' ? $name : 'your brand';
    }

    /**
     * @param array<int,mixed> $sources
     */
    private function firstContextValue(array $sources, string $fallback): string
    {
        foreach ($sources as $source) {
            $items = is_array($source) ? $source : [$source];

            foreach ($items as $item) {
                $value = trim((string) $item);
                if ($value !== '') {
                    return $this->normalizeText($value);
                }
            }
        }

        return $fallback;
    }

    private function normalizeText(string $value): string
    {
        return (string) Str::of($value)
            ->replaceMatches('/\s+/', ' ')
            ->trim();
    }

    private function fingerprint(string $value): string
    {
        return Str::lower($this->normalizeText($value));
    }
}
