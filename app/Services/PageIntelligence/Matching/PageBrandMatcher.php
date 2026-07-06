<?php

namespace App\Services\PageIntelligence\Matching;

use App\Models\CompanyIntelligenceProfile;
use App\Models\CompanyProfile;
use App\Models\MonitoredPage;
use App\Models\PageBrandMatch;
use App\Services\PageIntelligence\Matching\Concerns\BuildsPageMatchContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PageBrandMatcher
{
    use BuildsPageMatchContext;

    public function match(MonitoredPage $page): Collection
    {
        $snapshot = $this->latestSnapshot($page);
        $extraction = $this->latestExtraction($page, $snapshot);
        $text = $this->pageText($page, $extraction);
        $matches = collect();

        foreach ($this->brandCandidates($page) as $candidate) {
            foreach ($candidate['terms'] as $term) {
                if ($this->containsTerm($text, $term)) {
                    $matches->push($this->store($page, $snapshot?->id, $extraction?->id, [
                        ...$candidate,
                        'match_type' => 'brand_mention',
                        'match_score' => $candidate['score'],
                        'evidence_json' => ['term' => $term, 'snippet' => $this->snippet($text, $term)],
                    ]));

                    break;
                }
            }
        }

        return $matches;
    }

    private function brandCandidates(MonitoredPage $page): array
    {
        $candidates = [];

        CompanyIntelligenceProfile::query()
            ->where('workspace_id', $page->workspace_id)
            ->where('status', CompanyIntelligenceProfile::STATUS_ACTIVE)
            ->get()
            ->each(function (CompanyIntelligenceProfile $profile) use (&$candidates): void {
                $terms = $this->termList([$profile->company_name, $profile->target_entities, $profile->products_services]);

                if ($terms !== []) {
                    $candidates[] = [
                        'brand_ref_type' => CompanyIntelligenceProfile::class,
                        'brand_ref_id' => (string) $profile->id,
                        'brand_key' => $profile->brand_key ?: Str::slug((string) $profile->company_name),
                        'brand_name' => (string) $profile->company_name,
                        'terms' => $terms,
                        'score' => 0.86,
                    ];
                }
            });

        CompanyProfile::query()
            ->where('workspace_id', $page->workspace_id)
            ->get()
            ->each(function (CompanyProfile $profile) use (&$candidates): void {
                $terms = $this->termList([$profile->company_name]);

                if ($terms !== []) {
                    $candidates[] = [
                        'brand_ref_type' => CompanyProfile::class,
                        'brand_ref_id' => (string) $profile->id,
                        'brand_key' => Str::slug((string) $profile->company_name),
                        'brand_name' => (string) $profile->company_name,
                        'terms' => $terms,
                        'score' => 0.8,
                    ];
                }
            });

        return collect($candidates)->unique('brand_key')->values()->all();
    }

    private function store(MonitoredPage $page, ?string $snapshotId, ?string $extractionId, array $match): PageBrandMatch
    {
        return PageBrandMatch::query()->updateOrCreate([
            'monitored_page_id' => $page->id,
            'brand_key' => $match['brand_key'],
            'match_type' => $match['match_type'],
        ], [
            'organization_id' => $page->organization_id,
            'workspace_id' => $page->workspace_id,
            'client_site_id' => $page->client_site_id,
            'page_snapshot_id' => $snapshotId,
            'page_content_extraction_id' => $extractionId,
            'brand_ref_type' => $match['brand_ref_type'],
            'brand_ref_id' => $match['brand_ref_id'],
            'brand_name' => $match['brand_name'],
            'match_score' => $match['match_score'],
            'evidence_json' => $match['evidence_json'],
            'observed_at' => now(),
        ]);
    }
}
