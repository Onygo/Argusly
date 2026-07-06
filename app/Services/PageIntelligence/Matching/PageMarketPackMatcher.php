<?php

namespace App\Services\PageIntelligence\Matching;

use App\Models\MarketPackInstallation;
use App\Models\MonitoredPage;
use App\Models\PageMarketPackMatch;
use App\Services\PageIntelligence\Matching\Concerns\BuildsPageMatchContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PageMarketPackMatcher
{
    use BuildsPageMatchContext;

    public function match(MonitoredPage $page): Collection
    {
        $snapshot = $this->latestSnapshot($page);
        $extraction = $this->latestExtraction($page, $snapshot);
        $text = $this->pageText($page, $extraction);
        $matches = collect();

        foreach ($this->marketPacks($page) as $key => $pack) {
            $keywords = $this->termList([$pack['keywords'] ?? [], $pack['themes'] ?? []]);
            $matched = collect($keywords)->filter(fn (string $term): bool => $this->containsTerm($text, $term))->values();

            if ($matched->isEmpty()) {
                continue;
            }

            $score = min(0.95, 0.45 + ($matched->count() * 0.12));
            $matches->push(PageMarketPackMatch::query()->updateOrCreate([
                'monitored_page_id' => $page->id,
                'market_pack_key' => (string) $key,
                'match_type' => 'market_theme',
            ], [
                'organization_id' => $page->organization_id,
                'workspace_id' => $page->workspace_id,
                'client_site_id' => $page->client_site_id,
                'page_snapshot_id' => $snapshot?->id,
                'page_content_extraction_id' => $extraction?->id,
                'market_pack_name' => (string) ($pack['name'] ?? Str::headline((string) $key)),
                'match_score' => $score,
                'evidence_json' => [
                    'matched_keywords' => $matched->all(),
                    'snippet' => $this->snippet($text, (string) $matched->first()),
                ],
                'observed_at' => now(),
            ]));
        }

        return $matches;
    }

    private function marketPacks(MonitoredPage $page): array
    {
        return array_replace(
            (array) config('page_intelligence.market_packs', []),
            $this->installedMarketPacks($page),
        );
    }

    private function installedMarketPacks(MonitoredPage $page): array
    {
        return MarketPackInstallation::query()
            ->with(['marketPack.themes', 'marketPack.keywords'])
            ->where('workspace_id', $page->workspace_id)
            ->where('status', MarketPackInstallation::STATUS_ACTIVE)
            ->where(function ($query) use ($page): void {
                $query->whereNull('client_site_id');

                if ($page->client_site_id !== null) {
                    $query->orWhere('client_site_id', $page->client_site_id);
                }
            })
            ->get()
            ->mapWithKeys(function (MarketPackInstallation $installation): array {
                $pack = $installation->marketPack;
                if (! $pack) {
                    return [];
                }

                $keywords = $pack->keywords
                    ->pluck('keyword')
                    ->merge(collect((array) ($installation->keyword_overrides_json ?? []))->flatten())
                    ->filter()
                    ->values()
                    ->all();

                $themes = $pack->themes
                    ->pluck('name')
                    ->merge(collect((array) ($installation->theme_overrides_json ?? []))->flatten())
                    ->filter()
                    ->values()
                    ->all();

                return [
                    $pack->key => [
                        'name' => $pack->name,
                        'keywords' => $keywords,
                        'themes' => $themes,
                    ],
                ];
            })
            ->all();
    }
}
