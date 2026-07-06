<?php

namespace App\Services\PageIntelligence\Matching;

use App\Models\Campaign;
use App\Models\MonitoredPage;
use App\Models\PageCampaignMatch;
use App\Services\PageIntelligence\Matching\Concerns\BuildsPageMatchContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PageCampaignMatcher
{
    use BuildsPageMatchContext;

    public function match(MonitoredPage $page): Collection
    {
        $snapshot = $this->latestSnapshot($page);
        $extraction = $this->latestExtraction($page, $snapshot);
        $text = $this->pageText($page, $extraction);
        $links = $this->links($extraction);
        $matches = collect();

        Campaign::query()
            ->where('workspace_id', $page->workspace_id)
            ->get()
            ->each(function (Campaign $campaign) use ($page, $snapshot, $extraction, $text, $links, $matches): void {
                foreach ($this->campaignMatches($page, $campaign, $text, $links) as $match) {
                    $matches->push($this->store($page, $campaign, $snapshot?->id, $extraction?->id, $match));
                }
            });

        return $matches;
    }

    private function campaignMatches(MonitoredPage $page, Campaign $campaign, string $text, Collection $links): array
    {
        $matches = [];
        $campaignUrls = $this->campaignUrls($campaign);

        foreach ($campaignUrls as $url) {
            foreach ($this->pageUrls($page) as $pageUrl) {
                if ($this->sameUrlOrPath($url, $pageUrl)) {
                    $matches[] = $this->matchData('campaign_url', 0.96, ['campaign_url' => $url, 'page_url' => $pageUrl]);
                }
            }

            foreach ($links as $link) {
                if ($this->sameUrlOrPath($url, (string) $link['href'])) {
                    $matches[] = $this->matchData('backlink', 0.78, ['campaign_url' => $url, 'href' => $link['href'], 'anchor_text' => $link['text']]);
                }
            }
        }

        $utmEvidence = $this->utmEvidence($page, $campaign);
        if ($utmEvidence !== []) {
            $matches[] = $this->matchData('utm', 0.9, $utmEvidence);
        }

        if ($this->containsTerm($text, (string) $campaign->name)) {
            $matches[] = $this->matchData('campaign_name', 0.7, ['campaign_name' => $campaign->name, 'snippet' => $this->snippet($text, (string) $campaign->name)]);
        }

        foreach ($this->termList([(array) data_get($campaign->metadata, 'press_release_fingerprints', [])]) as $fingerprint) {
            if ($this->containsTerm($text, $fingerprint)) {
                $matches[] = $this->matchData('press_release_fingerprint', 0.88, ['fingerprint' => $fingerprint, 'snippet' => $this->snippet($text, $fingerprint)]);
            }
        }

        foreach ($this->trackedKeywords($campaign) as $keyword) {
            if ($this->containsTerm($text, $keyword)) {
                $matches[] = $this->matchData('tracked_keyword', 0.66, ['keyword' => $keyword, 'snippet' => $this->snippet($text, $keyword)]);
            }
        }

        foreach ($links as $link) {
            if ($this->containsTerm((string) $link['text'], (string) $campaign->name)) {
                $matches[] = $this->matchData('anchor_text', 0.64, ['anchor_text' => $link['text'], 'href' => $link['href']]);
            }
        }

        if ($this->publicationTimingMatches($page, $campaign)) {
            $matches[] = $this->matchData('publication_timing', 0.42, [
                'published_at' => $page->published_at_current?->toDateString(),
                'planned_start_date' => $campaign->planned_start_date?->toDateString(),
                'planned_end_date' => $campaign->planned_end_date?->toDateString(),
            ]);
        }

        return collect($matches)->unique('match_type')->values()->all();
    }

    private function campaignUrls(Campaign $campaign): array
    {
        return $this->termList([
            data_get($campaign->metadata, 'campaign_urls', []),
            data_get($campaign->metadata, 'urls', []),
            data_get($campaign->metadata, 'landing_pages', []),
            data_get($campaign->metadata, 'target_urls', []),
            data_get($campaign->metadata, 'press_release_urls', []),
        ]);
    }

    private function trackedKeywords(Campaign $campaign): array
    {
        return $this->termList([
            data_get($campaign->metadata, 'tracked_keywords', []),
            data_get($campaign->ai_planning_context, 'tracked_keywords', []),
            data_get($campaign->ai_planning_context, 'keywords', []),
        ]);
    }

    private function utmEvidence(MonitoredPage $page, Campaign $campaign): array
    {
        $expected = collect($campaign->trackingParameters())
            ->mapWithKeys(fn (string $value, string $key): array => [Str::lower($key) => Str::lower($value)])
            ->all();

        if ($expected === []) {
            $expected['utm_campaign'] = Str::lower((string) ($campaign->slug ?: Str::slug((string) $campaign->name)));
        }

        foreach ($this->pageUrls($page) as $url) {
            $actual = $this->queryPairs($url);
            $matched = [];

            foreach ($expected as $key => $value) {
                if (($actual[$key] ?? null) === $value) {
                    $matched[$key] = $value;
                }
            }

            if ($matched !== []) {
                return ['url' => $url, 'matched_parameters' => $matched];
            }
        }

        return [];
    }

    private function publicationTimingMatches(MonitoredPage $page, Campaign $campaign): bool
    {
        $publishedAt = $page->published_at_current;
        if (! $publishedAt || ! $campaign->planned_start_date || ! $campaign->planned_end_date) {
            return false;
        }

        return $publishedAt->betweenIncluded(
            Carbon::parse($campaign->planned_start_date)->subDays(14),
            Carbon::parse($campaign->planned_end_date)->addDays(14),
        );
    }

    private function matchData(string $type, float $score, array $evidence): array
    {
        return ['match_type' => $type, 'match_score' => $score, 'evidence_json' => $evidence];
    }

    private function store(MonitoredPage $page, Campaign $campaign, ?string $snapshotId, ?string $extractionId, array $match): PageCampaignMatch
    {
        return PageCampaignMatch::query()->updateOrCreate([
            'monitored_page_id' => $page->id,
            'campaign_id' => $campaign->id,
            'match_type' => $match['match_type'],
        ], [
            'organization_id' => $page->organization_id,
            'workspace_id' => $page->workspace_id,
            'client_site_id' => $page->client_site_id,
            'page_snapshot_id' => $snapshotId,
            'page_content_extraction_id' => $extractionId,
            'match_score' => $match['match_score'],
            'evidence_json' => $match['evidence_json'],
            'observed_at' => now(),
        ]);
    }
}
