<?php

namespace App\Support\Interaction;

use App\Models\MonitoredPage;
use App\Models\PageAlert;
use App\Models\PageCampaignMatch;
use App\Models\PageCompetitorMatch;
use App\Models\PageEntity;
use App\Models\PageGeoObservation;
use App\Models\PageMention;
use App\Models\PagePrValue;
use App\Models\PageScore;
use App\Models\PageSentiment;
use App\Models\PageSerpObservation;
use App\Models\PageTopic;
use App\Models\SignalEvent;
use App\Services\PageIntelligence\PageIntelligenceScoreCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class MonitoredPageMetadataProvider
{
    public function forPage(MonitoredPage $page): array
    {
        $page->loadMissing(['source:id,name,source_type,domain']);

        $snapshot = $page->latestSnapshot()
            ->with('contentExtraction:id,page_snapshot_id,title,summary,main_text,word_count,quality_score,content_depth_score,extraction_method,extractor_version')
            ->first();

        $extraction = $snapshot?->contentExtraction ?: $page->latestContentExtraction()->first();
        $entities = $this->latestPageRows(PageEntity::query(), $page, $snapshot?->id)
            ->orderByDesc('prominence_score')
            ->limit(8)
            ->get();
        $mentions = $this->latestPageRows(PageMention::query(), $page, $snapshot?->id)
            ->orderByDesc('confidence_score')
            ->limit(8)
            ->get();
        $sentiments = $this->latestPageRows(PageSentiment::query(), $page, $snapshot?->id)
            ->orderByDesc('confidence_score')
            ->limit(8)
            ->get();
        $topics = $this->latestPageRows(PageTopic::query(), $page, $snapshot?->id)
            ->orderByDesc('prominence_score')
            ->limit(8)
            ->get();
        $prValues = $this->latestPageRows(PagePrValue::query(), $page, $snapshot?->id)
            ->orderByDesc('calculated_at')
            ->limit(5)
            ->get();
        $intelligenceScore = PageScore::query()
            ->where('monitored_page_id', $page->getKey())
            ->where('score_type', PageIntelligenceScoreCalculator::SCORE_TYPE)
            ->latest('computed_at')
            ->first();
        $serpObservations = PageSerpObservation::query()
            ->where('monitored_page_id', $page->getKey())
            ->latest('observed_at')
            ->limit(5)
            ->get();
        $geoObservations = PageGeoObservation::query()
            ->where('monitored_page_id', $page->getKey())
            ->latest('observed_at')
            ->limit(5)
            ->get();
        $campaignMatches = PageCampaignMatch::query()
            ->where('monitored_page_id', $page->getKey())
            ->with('campaign:id,name')
            ->orderByDesc('observed_at')
            ->limit(6)
            ->get();
        $competitorMatches = PageCompetitorMatch::query()
            ->where('monitored_page_id', $page->getKey())
            ->with('competitor:id,name,domain')
            ->orderByDesc('observed_at')
            ->limit(6)
            ->get();
        $alerts = PageAlert::query()
            ->where('monitored_page_id', $page->getKey())
            ->with('recommendedAction:id,title,status')
            ->latest('fired_at')
            ->limit(6)
            ->get();
        $signalEvents = $this->linkedSignalEvents($page);

        return [
            'key' => 'monitored-page.inspect',
            'resource_type' => ResourceType::MONITORED_PAGE,
            'resource_key' => ResourceType::MONITORED_PAGE.':'.$page->getKey(),
            'resource_id' => $page->getKey(),
            'mode' => 'inspect',
            'width' => 'xl',
            'title' => (string) ($page->title_current ?: $extraction?->title ?: 'Monitored page'),
            'subtitle' => (string) ($page->canonical_url ?: $page->first_seen_url ?: $page->domain),
            'tabs' => [
                ['key' => 'overview', 'label' => 'Overview'],
                ['key' => 'evidence', 'label' => 'Evidence'],
                ['key' => 'signals', 'label' => 'Signals'],
            ],
            'sections' => [
                $this->pageSection($page),
                $this->snapshotSection($snapshot),
                $this->latestExtractionSection($extraction),
                $this->summarySection($extraction),
                $this->entitiesSection($entities, $mentions),
                $this->sentimentSection($sentiments),
                $this->topicsSection($topics),
                $this->prValueSection($prValues),
                $this->intelligenceScoreSection($intelligenceScore),
                $this->serpSection($serpObservations),
                $this->geoSection($geoObservations),
                $this->campaignsSection($campaignMatches),
                $this->competitorsSection($competitorMatches),
                $this->alertsSection($alerts),
                $this->signalEventsSection($signalEvents),
                $this->nextActionsSection($alerts),
            ],
            'metadata' => [
                'source' => 'page_intelligence',
                'snapshot_internal' => true,
                'canonical_url_hash' => $page->canonical_url_hash,
            ],
        ];
    }

    private function latestPageRows($query, MonitoredPage $page, ?string $snapshotId)
    {
        return $query
            ->where('monitored_page_id', $page->getKey())
            ->when($snapshotId !== null, fn ($query) => $query->where('page_snapshot_id', $snapshotId));
    }

    private function pageSection(MonitoredPage $page): array
    {
        return [
            'key' => 'page',
            'title' => 'Page',
            'items' => [
                ['label' => 'Title', 'value' => (string) ($page->title_current ?: 'Untitled monitored page')],
                ['label' => 'URL', 'value' => (string) ($page->canonical_url ?: $page->first_seen_url ?: '-')],
                ['label' => 'Domain', 'value' => (string) ($page->domain ?: '-')],
                ['label' => 'Source', 'value' => (string) ($page->source?->name ?: $page->source_type ?: '-')],
                ['label' => 'Page type', 'value' => (string) ($page->page_type ?: '-')],
            ],
        ];
    }

    private function snapshotSection(mixed $snapshot): array
    {
        return [
            'key' => 'latest_snapshot',
            'title' => 'Latest snapshot',
            'items' => [
                ['label' => 'Status', 'value' => $snapshot ? (string) ($snapshot->http_status ?: $snapshot->error_code ?: 'fetched') : 'No snapshot yet'],
                ['label' => 'Fetched at', 'value' => $snapshot?->fetched_at?->toDateTimeString() ?: '-'],
                ['label' => 'Content changed', 'value' => $snapshot ? ($snapshot->content_changed ? 'Yes' : 'No') : '-'],
                ['label' => 'Canonical conflict', 'value' => $snapshot ? ($snapshot->canonical_conflict ? 'Yes' : 'No') : '-'],
            ],
        ];
    }

    private function summarySection(mixed $extraction): array
    {
        $summary = trim((string) ($extraction?->summary ?: Str::limit((string) ($extraction?->main_text ?? ''), 280)));

        return [
            'key' => 'summary',
            'title' => 'Extracted summary',
            'items' => [
                ['label' => 'Summary', 'value' => $summary !== '' ? $summary : 'No extracted summary yet'],
                ['label' => 'Words', 'value' => $extraction?->word_count === null ? '-' : number_format((int) $extraction->word_count)],
                ['label' => 'Quality', 'value' => $extraction?->quality_score === null ? '-' : (string) $extraction->quality_score],
            ],
        ];
    }

    private function latestExtractionSection(mixed $extraction): array
    {
        return [
            'key' => 'latest_extraction',
            'title' => 'Latest extraction',
            'items' => [
                ['label' => 'Title', 'value' => (string) ($extraction?->title ?: '-')],
                ['label' => 'Method', 'value' => (string) ($extraction?->extraction_method ?: '-')],
                ['label' => 'Version', 'value' => (string) ($extraction?->extractor_version ?: '-')],
                ['label' => 'Content depth', 'value' => $extraction?->content_depth_score === null ? '-' : (string) $extraction->content_depth_score],
            ],
        ];
    }

    private function entitiesSection(Collection $entities, Collection $mentions): array
    {
        return [
            'key' => 'entities_mentions',
            'title' => 'Entities and mentions',
            'items' => [
                ['label' => 'Entities', 'value' => $this->entityList($entities)],
                ['label' => 'Mentions', 'value' => $mentions->pluck('entity_name')->filter()->unique()->take(6)->implode(', ') ?: 'No mentions yet'],
            ],
        ];
    }

    private function sentimentSection(Collection $sentiments): array
    {
        return [
            'key' => 'sentiment',
            'title' => 'Sentiment',
            'items' => $sentiments->isEmpty()
                ? [['label' => 'Sentiment', 'value' => 'No sentiment yet']]
                : $sentiments->map(fn (PageSentiment $sentiment): array => [
                    'label' => Str::headline((string) $sentiment->target_type),
                    'value' => trim((string) $sentiment->label.' '.(string) $sentiment->compound_score.' - '.(string) $sentiment->explanation),
                ])->values()->all(),
        ];
    }

    private function topicsSection(Collection $topics): array
    {
        return [
            'key' => 'topics',
            'title' => 'Topics',
            'items' => [
                ['label' => 'Detected topics', 'value' => $topics->pluck('topic_name')->filter()->take(8)->implode(', ') ?: 'No topics yet'],
            ],
        ];
    }

    private function prValueSection(Collection $prValues): array
    {
        return [
            'key' => 'pr_value',
            'title' => 'PR value breakdown',
            'items' => $prValues->isEmpty()
                ? [['label' => 'PR value', 'value' => 'No PR value calculated yet']]
                : $prValues->map(fn (PagePrValue $value): array => [
                    'label' => $value->model_key.' v'.$value->model_version,
                    'value' => trim(number_format((float) $value->score, 2).' score, '.(string) $value->currency.' '.number_format((float) $value->estimated_value_amount, 2).' - '.$this->compactBreakdown((array) $value->breakdown_json)),
                ])->values()->all(),
        ];
    }

    private function intelligenceScoreSection(?PageScore $score): array
    {
        if (! $score) {
            return [
                'key' => 'intelligence_score',
                'title' => 'Intelligence Score breakdown',
                'items' => [['label' => 'Score', 'value' => 'No Intelligence Score calculated yet']],
            ];
        }

        $components = collect((array) data_get($score->breakdown_json, 'components', []))
            ->map(fn (array $component, string $key): array => [
                'label' => Str::headline($key),
                'value' => ($component['available'] ?? false)
                    ? number_format((float) ($component['score'] ?? 0), 1).' x '.number_format((float) ($component['weight'] ?? 0), 2)
                    : 'Missing input',
            ])
            ->values()
            ->all();

        return [
            'key' => 'intelligence_score',
            'title' => 'Intelligence Score breakdown',
            'items' => array_merge([
                ['label' => 'Confidence-adjusted score', 'value' => number_format((float) data_get($score->metadata_json, 'confidence_adjusted_score', $score->score), 2)],
                ['label' => 'Raw score', 'value' => number_format((float) data_get($score->metadata_json, 'raw_score', $score->score), 2)],
                ['label' => 'Model', 'value' => (string) data_get($score->metadata_json, 'model_key', $score->model_used).' '.(string) data_get($score->metadata_json, 'model_version', $score->score_version)],
                ['label' => 'Confidence', 'value' => number_format((float) data_get($score->metadata_json, 'confidence', 0), 1).'%'],
                ['label' => 'Missing weight', 'value' => number_format((float) data_get($score->metadata_json, 'missing_weight_total', 0) * 100, 1).'%'],
                ['label' => 'Missing inputs', 'value' => collect((array) data_get($score->metadata_json, 'missing_inputs', []))->map(fn ($input) => Str::headline((string) $input))->implode(', ') ?: 'None'],
                ['label' => 'Computed', 'value' => $score->computed_at?->toDateTimeString() ?: '-'],
            ], $components),
        ];
    }

    private function serpSection(Collection $observations): array
    {
        return [
            'key' => 'serp_visibility',
            'title' => 'SERP visibility history',
            'items' => $observations->isEmpty()
                ? [['label' => 'SERP', 'value' => 'No SERP observations yet']]
                : $observations->map(fn (PageSerpObservation $observation): array => [
                    'label' => $observation->query,
                    'value' => trim('Position '.($observation->position ?: '-').', score '.number_format((float) $observation->visibility_score, 1).' on '.Str::headline((string) $observation->search_engine)),
                ])->values()->all(),
        ];
    }

    private function geoSection(Collection $observations): array
    {
        return [
            'key' => 'geo_visibility',
            'title' => 'GEO visibility',
            'items' => $observations->isEmpty()
                ? [['label' => 'GEO', 'value' => 'No GEO observations yet']]
                : $observations->map(fn (PageGeoObservation $observation): array => [
                    'label' => $observation->query,
                    'value' => trim(implode(', ', array_filter([
                        Str::headline((string) $observation->answer_engine),
                        'score '.number_format((float) $observation->geo_visibility_score, 1),
                        'citations '.(string) $observation->citation_count,
                        $observation->client_cited ? 'client cited' : null,
                        $observation->competitors_cited ? 'competitor cited' : null,
                        $observation->brand_mentioned ? 'brand mentioned' : null,
                        $this->geoTerms($observation),
                    ]))),
                ])->values()->all(),
        ];
    }

    private function geoTerms(PageGeoObservation $observation): ?string
    {
        $brands = collect((array) $observation->mentioned_brands_json)
            ->pluck('term')
            ->filter()
            ->unique()
            ->take(3)
            ->implode('/');
        $competitors = collect((array) $observation->mentioned_competitors_json)
            ->pluck('term')
            ->filter()
            ->unique()
            ->take(3)
            ->implode('/');

        return trim(($brands !== '' ? 'brands '.$brands : '').($competitors !== '' ? ' competitors '.$competitors : '')) ?: null;
    }

    private function campaignsSection(Collection $matches): array
    {
        return [
            'key' => 'linked_campaigns',
            'title' => 'Linked campaigns',
            'items' => [
                ['label' => 'Campaigns', 'value' => $matches->map(fn (PageCampaignMatch $match): string => trim((string) ($match->campaign?->name ?: $match->campaign_id).' ('.(string) $match->match_type.')'))->filter()->implode(', ') ?: 'No campaign matches yet'],
            ],
        ];
    }

    private function competitorsSection(Collection $matches): array
    {
        return [
            'key' => 'linked_competitors',
            'title' => 'Linked competitors',
            'items' => [
                ['label' => 'Competitors', 'value' => $matches->map(fn (PageCompetitorMatch $match): string => trim((string) ($match->competitor?->name ?: $match->site_competitor_id).' ('.(string) $match->match_type.')'))->filter()->implode(', ') ?: 'No competitor matches yet'],
            ],
        ];
    }

    private function alertsSection(Collection $alerts): array
    {
        return [
            'key' => 'alerts',
            'title' => 'Alerts',
            'items' => $alerts->isEmpty()
                ? [['label' => 'Alerts', 'value' => 'No alerts yet']]
                : $alerts->map(fn (PageAlert $alert): array => [
                    'label' => Str::headline((string) $alert->severity),
                    'value' => trim((string) $alert->title.' - '.Str::headline((string) $alert->status)),
                ])->values()->all(),
        ];
    }

    private function signalEventsSection(Collection $events): array
    {
        return [
            'key' => 'linked_signal_events',
            'title' => 'Linked Signal Events',
            'items' => [
                ['label' => 'Events', 'value' => $events->pluck('topic')->filter()->take(6)->implode(', ') ?: 'No linked Signal Events yet'],
            ],
        ];
    }

    private function nextActionsSection(Collection $alerts): array
    {
        $actions = $alerts->pluck('recommendedAction')->filter();

        return [
            'key' => 'recommended_next_actions',
            'title' => 'Recommended next actions',
            'items' => $actions->isEmpty()
                ? [['label' => 'Placeholder', 'value' => 'Recommendations will be generated after the action planning layer is connected.']]
                : $actions->map(fn (mixed $action): array => [
                    'label' => (string) $action->status,
                    'value' => (string) $action->title,
                ])->values()->all(),
        ];
    }

    private function linkedSignalEvents(MonitoredPage $page): Collection
    {
        return SignalEvent::query()
            ->where('workspace_id', $page->workspace_id)
            ->where(function ($query) use ($page): void {
                $query->where('metadata->monitored_page_id', $page->getKey())
                    ->orWhere('evidence->monitored_page_id', $page->getKey());
            })
            ->latest('observed_at')
            ->limit(8)
            ->get();
    }

    private function entityList(Collection $entities): string
    {
        return $entities
            ->map(fn (PageEntity $entity): string => trim((string) $entity->entity_name.' ('.(string) $entity->entity_type.')'))
            ->filter()
            ->take(8)
            ->implode(', ') ?: 'No entities yet';
    }

    private function compactBreakdown(array $breakdown): string
    {
        return collect($breakdown)
            ->take(4)
            ->map(fn (mixed $value, string|int $key): string => Str::headline((string) $key).': '.(is_scalar($value) ? (string) $value : json_encode($value)))
            ->implode(', ');
    }
}
