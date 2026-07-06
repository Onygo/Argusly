<?php

namespace App\Services\PageIntelligence\Matching;

use App\Models\MonitoredPage;
use App\Models\PageCompetitorMatch;
use App\Models\SiteCompetitor;
use App\Services\PageIntelligence\Matching\Concerns\BuildsPageMatchContext;
use Illuminate\Support\Collection;

class PageCompetitorMatcher
{
    use BuildsPageMatchContext;

    public function match(MonitoredPage $page): Collection
    {
        $snapshot = $this->latestSnapshot($page);
        $extraction = $this->latestExtraction($page, $snapshot);
        $text = $this->pageText($page, $extraction);
        $links = $this->links($extraction);
        $matches = collect();

        SiteCompetitor::query()
            ->where('workspace_id', $page->workspace_id)
            ->where('is_active', true)
            ->get()
            ->each(function (SiteCompetitor $competitor) use ($page, $snapshot, $extraction, $text, $links, $matches): void {
                if ($this->hostMatches((string) $competitor->domain, (string) $page->domain)) {
                    $matches->push($this->store($page, $competitor, $snapshot?->id, $extraction?->id, 'competitor_domain', 0.96, [
                        'page_domain' => $page->domain,
                        'competitor_domain' => $competitor->domain,
                    ]));
                }

                foreach ($links as $link) {
                    if ($this->hostMatches((string) $competitor->domain, $this->hostFromUrl((string) $link['href']))) {
                        $matches->push($this->store($page, $competitor, $snapshot?->id, $extraction?->id, 'competitor_backlink', 0.76, $link));
                    }
                }

                foreach ($this->termList([$competitor->name, $competitor->domain]) as $term) {
                    if ($this->containsTerm($text, $term)) {
                        $matches->push($this->store($page, $competitor, $snapshot?->id, $extraction?->id, 'competitor_mention', 0.68, [
                            'term' => $term,
                            'snippet' => $this->snippet($text, $term),
                        ]));
                    }
                }
            });

        return $matches;
    }

    private function store(MonitoredPage $page, SiteCompetitor $competitor, ?string $snapshotId, ?string $extractionId, string $type, float $score, array $evidence): PageCompetitorMatch
    {
        return PageCompetitorMatch::query()->updateOrCreate([
            'monitored_page_id' => $page->id,
            'site_competitor_id' => $competitor->id,
            'match_type' => $type,
        ], [
            'organization_id' => $page->organization_id,
            'workspace_id' => $page->workspace_id,
            'client_site_id' => $page->client_site_id,
            'page_snapshot_id' => $snapshotId,
            'page_content_extraction_id' => $extractionId,
            'match_score' => $score,
            'evidence_json' => $evidence,
            'observed_at' => now(),
        ]);
    }
}
