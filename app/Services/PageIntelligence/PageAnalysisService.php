<?php

namespace App\Services\PageIntelligence;

use App\Models\CompanyIntelligenceProfile;
use App\Models\CompanyProfile;
use App\Models\MarketPackCompetitor;
use App\Models\MarketPackInstallation;
use App\Models\MarketPackTheme;
use App\Models\PageContentExtraction;
use App\Models\PageEntity;
use App\Models\PageMention;
use App\Models\PageScore;
use App\Models\PageSentiment;
use App\Models\PageSnapshot;
use App\Models\PageTopic;
use App\Models\SiteCompetitor;
use App\Models\TaxonomyItem;
use App\Support\EditorialTaxonomyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PageAnalysisService
{
    private const ENTITY_MODEL = 'deterministic-entity-v1';
    private const TOPIC_MODEL = 'deterministic-topic-v1';
    private const SENTIMENT_MODEL = 'deterministic-sentiment-v1';
    private const SCORE_MODEL = 'deterministic-score-v1';

    /**
     * @return Collection<int, PageEntity>
     */
    public function analyzeEntities(PageSnapshot $snapshot): Collection
    {
        $snapshot = $snapshot->loadMissing(['page', 'contentExtraction']);
        $extraction = $this->extraction($snapshot);
        $text = $this->analysisText($snapshot, $extraction);
        $observedAt = $snapshot->fetched_at ?: now();

        return collect($this->entityCandidates($snapshot))
            ->map(function (array $candidate) use ($snapshot, $extraction, $text, $observedAt): ?PageEntity {
                $matches = $this->matches($text, (string) $candidate['name']);

                if ($matches === []) {
                    return null;
                }

                $firstPosition = min(array_column($matches, 'position_start'));
                $mentionCount = count($matches);
                $entity = PageEntity::query()->updateOrCreate(
                    [
                        'page_snapshot_id' => $snapshot->id,
                        'entity_type' => $candidate['type'],
                        'entity_key' => $candidate['key'],
                    ],
                    [
                        'organization_id' => $snapshot->organization_id,
                        'workspace_id' => $snapshot->workspace_id,
                        'client_site_id' => $snapshot->client_site_id,
                        'monitored_page_id' => $snapshot->monitored_page_id,
                        'page_content_extraction_id' => $extraction?->id,
                        'entity_name' => $candidate['name'],
                        'source_type' => $candidate['source_type'],
                        'source_ref_type' => $candidate['source_ref_type'] ?? null,
                        'source_ref_id' => $candidate['source_ref_id'] ?? null,
                        'mention_count' => $mentionCount,
                        'first_position' => $firstPosition,
                        'prominence_score' => $this->prominence($mentionCount, $firstPosition, mb_strlen($text)),
                        'confidence_score' => $this->entityConfidence((string) $candidate['type'], $mentionCount),
                        'evidence_json' => array_slice($matches, 0, 5),
                        'analysis_method' => 'deterministic_match',
                        'model_used' => self::ENTITY_MODEL,
                        'analyzer_version' => 'page-entity-analyzer-v1',
                        'observed_at' => $observedAt,
                        'metadata_json' => [
                            'matched_term' => $candidate['name'],
                            'provenance' => $candidate['source_type'],
                        ],
                    ]
                );

                foreach ($matches as $match) {
                    $this->storeMention($snapshot, $extraction, $entity, $match, $observedAt);
                }

                return $entity;
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, PageTopic>
     */
    public function classifyTopics(PageSnapshot $snapshot): Collection
    {
        $snapshot = $snapshot->loadMissing(['page', 'contentExtraction']);
        $extraction = $this->extraction($snapshot);
        $text = $this->analysisText($snapshot, $extraction);
        $classifiedAt = now();

        return collect($this->topicCandidates($snapshot))
            ->map(function (array $candidate) use ($snapshot, $extraction, $text, $classifiedAt): ?PageTopic {
                $keywordMatches = [];

                foreach ($candidate['keywords'] as $keyword) {
                    $matches = $this->matches($text, $keyword);
                    if ($matches !== []) {
                        $keywordMatches[$keyword] = $matches;
                    }
                }

                if ($keywordMatches === []) {
                    return null;
                }

                $allMatches = collect($keywordMatches)->flatten(1)->values()->all();
                $mentionCount = count($allMatches);
                $firstPosition = min(array_column($allMatches, 'position_start'));

                return PageTopic::query()->updateOrCreate(
                    [
                        'page_snapshot_id' => $snapshot->id,
                        'topic_key' => $candidate['key'],
                    ],
                    [
                        'organization_id' => $snapshot->organization_id,
                        'workspace_id' => $snapshot->workspace_id,
                        'client_site_id' => $snapshot->client_site_id,
                        'monitored_page_id' => $snapshot->monitored_page_id,
                        'page_content_extraction_id' => $extraction?->id,
                        'topic_name' => $candidate['name'],
                        'topic_type' => $candidate['type'],
                        'source_type' => $candidate['source_type'],
                        'source_ref_type' => $candidate['source_ref_type'] ?? null,
                        'source_ref_id' => $candidate['source_ref_id'] ?? null,
                        'mention_count' => $mentionCount,
                        'first_position' => $firstPosition,
                        'prominence_score' => $this->prominence($mentionCount, $firstPosition, mb_strlen($text)),
                        'confidence_score' => min(95, 50 + ($mentionCount * 10)),
                        'keywords_json' => array_values($candidate['keywords']),
                        'evidence_json' => array_slice($allMatches, 0, 5),
                        'classification_method' => 'deterministic_match',
                        'model_used' => self::TOPIC_MODEL,
                        'classifier_version' => 'page-topic-classifier-v1',
                        'classified_at' => $classifiedAt,
                        'metadata_json' => ['matched_keywords' => array_keys($keywordMatches)],
                    ]
                );
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, PageSentiment>
     */
    public function analyzeSentiment(PageSnapshot $snapshot): Collection
    {
        $snapshot = $snapshot->loadMissing(['page', 'contentExtraction']);
        $extraction = $this->extraction($snapshot);
        $text = $this->analysisText($snapshot, $extraction);
        $analyzedAt = now();
        $sentiments = collect([
            $this->storeSentiment(
                $snapshot,
                $extraction,
                PageSentiment::TARGET_PAGE,
                (string) $snapshot->id,
                $snapshot->page?->title_current ?: $snapshot->page?->canonical_url,
                $text,
                [['snippet' => Str::limit($text, 320, '')]],
                $analyzedAt,
                PageSnapshot::class,
                (string) $snapshot->id
            ),
        ]);

        PageEntity::query()
            ->where('page_snapshot_id', $snapshot->id)
            ->get()
            ->each(function (PageEntity $entity) use ($snapshot, $extraction, $analyzedAt, $sentiments): void {
                $evidence = collect($entity->evidence_json ?: []);
                $entityText = $evidence->pluck('snippet')->implode(' ');
                $entityText = $entityText !== '' ? $entityText : (string) $entity->entity_name;

                $sentiments->push($this->storeSentiment(
                    $snapshot,
                    $extraction,
                    PageSentiment::TARGET_ENTITY,
                    $entity->entity_type.':'.$entity->entity_key,
                    $entity->entity_name,
                    $entityText,
                    $evidence->values()->all(),
                    $analyzedAt,
                    PageEntity::class,
                    (string) $entity->id
                ));

                if (in_array($entity->entity_type, [
                    PageSentiment::TARGET_BRAND,
                    PageSentiment::TARGET_COMPETITOR,
                    PageSentiment::TARGET_TOPIC,
                ], true)) {
                    $sentiments->push($this->storeSentiment(
                        $snapshot,
                        $extraction,
                        $entity->entity_type,
                        $entity->entity_key,
                        $entity->entity_name,
                        $entityText,
                        $evidence->values()->all(),
                        $analyzedAt,
                        PageEntity::class,
                        (string) $entity->id
                    ));
                }
            });

        PageTopic::query()
            ->where('page_snapshot_id', $snapshot->id)
            ->get()
            ->each(function (PageTopic $topic) use ($snapshot, $extraction, $analyzedAt, $sentiments): void {
                $evidence = collect($topic->evidence_json ?: []);
                $topicText = $evidence->pluck('snippet')->implode(' ');

                $sentiments->push($this->storeSentiment(
                    $snapshot,
                    $extraction,
                    PageSentiment::TARGET_TOPIC,
                    $topic->topic_key,
                    $topic->topic_name,
                    $topicText,
                    $evidence->values()->all(),
                    $analyzedAt,
                    PageTopic::class,
                    (string) $topic->id
                ));
            });

        return $sentiments->filter()->values();
    }

    /**
     * @return Collection<int, PageScore>
     */
    public function calculateBasicScores(PageSnapshot $snapshot): Collection
    {
        $snapshot = $snapshot->loadMissing(['page', 'contentExtraction']);
        $extraction = $this->extraction($snapshot);
        $computedAt = now();
        $entityCount = PageEntity::query()->where('page_snapshot_id', $snapshot->id)->count();
        $brandCount = PageEntity::query()->where('page_snapshot_id', $snapshot->id)->where('entity_type', PageEntity::TYPE_BRAND)->count();
        $competitorCount = PageEntity::query()->where('page_snapshot_id', $snapshot->id)->where('entity_type', PageEntity::TYPE_COMPETITOR)->count();
        $topicCount = PageTopic::query()->where('page_snapshot_id', $snapshot->id)->count();
        $pageSentiment = PageSentiment::query()
            ->where('page_snapshot_id', $snapshot->id)
            ->where('target_type', PageSentiment::TARGET_PAGE)
            ->first();

        $scores = [
            [
                'type' => 'entity_coverage',
                'score' => min(100, ($brandCount > 0 ? 35 : 0) + min(30, $competitorCount * 15) + min(35, $entityCount * 7)),
                'breakdown' => compact('entityCount', 'brandCount', 'competitorCount'),
                'explanation' => 'Measures whether the page contains recognizable brand, competitor and topic entities.',
            ],
            [
                'type' => 'topic_coverage',
                'score' => min(100, $topicCount * 20),
                'breakdown' => compact('topicCount'),
                'explanation' => 'Measures how many configured market or taxonomy topics were matched.',
            ],
            [
                'type' => 'sentiment_health',
                'score' => $pageSentiment ? round(((float) $pageSentiment->compound_score + 1) * 50, 2) : 50,
                'breakdown' => ['compound_score' => $pageSentiment?->compound_score, 'label' => $pageSentiment?->label],
                'explanation' => 'Maps page-level deterministic sentiment to a 0-100 health score.',
            ],
        ];

        return collect($scores)
            ->map(fn (array $score): PageScore => $this->storeScore($snapshot, $extraction, $score, $computedAt))
            ->push(app(PageIntelligenceScoreCalculator::class)->calculate($snapshot))
            ->values();
    }

    private function extraction(PageSnapshot $snapshot): ?PageContentExtraction
    {
        return $snapshot->contentExtraction
            ?: PageContentExtraction::query()->where('page_snapshot_id', $snapshot->id)->first();
    }

    private function analysisText(PageSnapshot $snapshot, ?PageContentExtraction $extraction): string
    {
        $parts = [
            $extraction?->title,
            $extraction?->meta_description,
            $extraction?->summary,
            $extraction?->mainTextForAnalysis(),
        ];

        $text = trim(collect($parts)->filter()->implode("\n\n"));

        if ($text !== '') {
            return $text;
        }

        return trim(strip_tags((string) $snapshot->raw_html));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function entityCandidates(PageSnapshot $snapshot): array
    {
        $candidates = [];

        CompanyIntelligenceProfile::query()
            ->where('workspace_id', $snapshot->workspace_id)
            ->where('status', CompanyIntelligenceProfile::STATUS_ACTIVE)
            ->orderByDesc('is_default')
            ->get()
            ->each(function (CompanyIntelligenceProfile $profile) use (&$candidates): void {
                foreach ($this->termList([
                    $profile->company_name,
                    $profile->target_entities,
                    $profile->products_services,
                ]) as $term) {
                    $candidates[] = $this->candidate(PageEntity::TYPE_BRAND, $term, 'company_intelligence_profile', CompanyIntelligenceProfile::class, (string) $profile->id);
                }

                foreach ($this->termList([
                    $profile->direct_competitors,
                    $profile->indirect_competitors,
                    $profile->aspirational_competitors,
                ]) as $term) {
                    $candidates[] = $this->candidate(PageEntity::TYPE_COMPETITOR, $term, 'company_intelligence_profile', CompanyIntelligenceProfile::class, (string) $profile->id);
                }

                foreach ($this->termList([
                    $profile->primary_topics,
                    $profile->authority_areas,
                    $profile->strategic_keywords,
                    $profile->market_category,
                ]) as $term) {
                    $candidates[] = $this->candidate(PageEntity::TYPE_TOPIC, $term, 'company_intelligence_profile', CompanyIntelligenceProfile::class, (string) $profile->id);
                }
            });

        CompanyProfile::query()
            ->where('workspace_id', $snapshot->workspace_id)
            ->get()
            ->each(function (CompanyProfile $profile) use (&$candidates): void {
                foreach ($this->termList([$profile->company_name]) as $term) {
                    $candidates[] = $this->candidate(PageEntity::TYPE_BRAND, $term, 'company_profile', CompanyProfile::class, (string) $profile->id);
                }
            });

        SiteCompetitor::query()
            ->where('workspace_id', $snapshot->workspace_id)
            ->where('is_active', true)
            ->get()
            ->each(function (SiteCompetitor $competitor) use (&$candidates): void {
                foreach ($this->termList([$competitor->name, $competitor->domain]) as $term) {
                    $candidates[] = $this->candidate(PageEntity::TYPE_COMPETITOR, $term, 'site_competitor', SiteCompetitor::class, (string) $competitor->id);
                }
            });

        MarketPackInstallation::query()
            ->with(['marketPack.competitors'])
            ->where('workspace_id', $snapshot->workspace_id)
            ->where('status', MarketPackInstallation::STATUS_ACTIVE)
            ->where(function ($query) use ($snapshot): void {
                $query->whereNull('client_site_id');

                if ($snapshot->client_site_id !== null) {
                    $query->orWhere('client_site_id', $snapshot->client_site_id);
                }
            })
            ->get()
            ->each(function (MarketPackInstallation $installation) use (&$candidates): void {
                $installation->marketPack?->competitors->each(function (MarketPackCompetitor $competitor) use (&$candidates): void {
                    foreach ($this->termList([$competitor->name, $competitor->domain, $competitor->aliases_json]) as $term) {
                        $candidates[] = $this->candidate(PageEntity::TYPE_COMPETITOR, $term, 'market_pack_competitor', MarketPackCompetitor::class, (string) $competitor->id);
                    }
                });
            });

        return $this->uniqueCandidates($candidates);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function topicCandidates(PageSnapshot $snapshot): array
    {
        $candidates = [];

        CompanyIntelligenceProfile::query()
            ->where('workspace_id', $snapshot->workspace_id)
            ->where('status', CompanyIntelligenceProfile::STATUS_ACTIVE)
            ->get()
            ->each(function (CompanyIntelligenceProfile $profile) use (&$candidates): void {
                foreach ($this->termList([
                    $profile->primary_topics,
                    $profile->authority_areas,
                    $profile->strategic_keywords,
                    $profile->market_category,
                ]) as $term) {
                    $candidates[] = $this->topicCandidate($term, 'theme', 'company_intelligence_profile', CompanyIntelligenceProfile::class, (string) $profile->id);
                }
            });

        if ($snapshot->organization_id) {
            $taxonomy = app(EditorialTaxonomyService::class);
            foreach ([TaxonomyItem::TYPE_INTENT, TaxonomyItem::TYPE_AUDIENCE] as $type) {
                foreach ($taxonomy->activeItemsByTenantAndType((int) $snapshot->organization_id, $type) as $item) {
                    $candidates[] = $this->topicCandidate($item->name, $type, 'editorial_taxonomy', TaxonomyItem::class, (string) $item->id);
                }
            }
        }

        foreach ($this->marketTerms() as $term) {
            $candidates[] = $this->topicCandidate($term, 'market', 'argusly_markets_config', null, null);
        }

        foreach ($this->marketPackTopicCandidates($snapshot) as $candidate) {
            $candidates[] = $candidate;
        }

        return collect($candidates)
            ->unique(fn (array $candidate): string => (string) $candidate['key'])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    private function termList(array $values): array
    {
        return collect($values)
            ->flatMap(fn ($value): array => is_array($value) ? $value : [$value])
            ->flatMap(function ($value): array {
                if (is_array($value)) {
                    return collect($value)
                        ->flatten()
                        ->filter(fn (mixed $item): bool => is_scalar($item))
                        ->map(fn (mixed $item): string => (string) $item)
                        ->all();
                }

                if (! is_scalar($value)) {
                    return [];
                }

                return preg_split('/[,;\n]+/', (string) $value) ?: [];
            })
            ->map(fn ($term): string => trim((string) $term))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 3 && mb_strlen($term) <= 120)
            ->unique(fn (string $term): string => $this->key($term))
            ->values()
            ->all();
    }

    private function candidate(string $type, string $term, string $sourceType, ?string $sourceRefType, ?string $sourceRefId): array
    {
        return [
            'type' => $type,
            'key' => $this->key($term),
            'name' => $term,
            'source_type' => $sourceType,
            'source_ref_type' => $sourceRefType,
            'source_ref_id' => $sourceRefId,
        ];
    }

    private function topicCandidate(
        string $term,
        string $type,
        string $sourceType,
        ?string $sourceRefType,
        ?string $sourceRefId,
        ?array $keywords = null,
        ?string $key = null,
    ): array
    {
        return [
            'key' => $key ?: $this->key($term),
            'name' => $term,
            'type' => $type,
            'keywords' => $keywords ?: [$term],
            'source_type' => $sourceType,
            'source_ref_type' => $sourceRefType,
            'source_ref_id' => $sourceRefId,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function marketPackTopicCandidates(PageSnapshot $snapshot): array
    {
        return MarketPackInstallation::query()
            ->with(['marketPack.themes.keywords'])
            ->where('workspace_id', $snapshot->workspace_id)
            ->where('status', MarketPackInstallation::STATUS_ACTIVE)
            ->where(function ($query) use ($snapshot): void {
                $query->whereNull('client_site_id');

                if ($snapshot->client_site_id !== null) {
                    $query->orWhere('client_site_id', $snapshot->client_site_id);
                }
            })
            ->get()
            ->flatMap(function (MarketPackInstallation $installation): array {
                $pack = $installation->marketPack;
                if (! $pack) {
                    return [];
                }

                return $pack->themes->map(function (MarketPackTheme $theme) use ($installation, $pack): array {
                    $keywords = collect([$theme->name])
                        ->merge($theme->keywords->pluck('keyword'))
                        ->merge($this->overrideTerms($installation->theme_overrides_json, $theme->key))
                        ->merge($this->overrideTerms($installation->keyword_overrides_json, $theme->key))
                        ->filter(fn (mixed $term): bool => is_string($term) && trim($term) !== '')
                        ->map(fn (string $term): string => trim($term))
                        ->unique(fn (string $term): string => $this->key($term))
                        ->values()
                        ->all();

                    return $this->topicCandidate(
                        (string) $theme->name,
                        'market_pack_theme',
                        'market_pack',
                        MarketPackTheme::class,
                        (string) $theme->id,
                        $keywords,
                        $this->key($pack->key.' '.$theme->key)
                    );
                })->all();
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function overrideTerms(?array $overrides, string $key): array
    {
        $overrides = (array) $overrides;
        $terms = $overrides[$key] ?? $overrides[Str::snake($key)] ?? [];

        return collect(is_array($terms) ? $terms : [$terms])
            ->flatten()
            ->filter(fn (mixed $term): bool => is_scalar($term))
            ->map(fn (mixed $term): string => (string) $term)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    private function uniqueCandidates(array $candidates): array
    {
        return collect($candidates)
            ->unique(fn (array $candidate): string => $candidate['type'].':'.$candidate['key'])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function marketTerms(): array
    {
        return collect((array) config('argusly_markets.pages', []))
            ->flatMap(function (array $market): array {
                $values = [
                    $market['label'] ?? null,
                    $market['nav_label'] ?? null,
                    data_get($market, 'hero.title'),
                ];

                foreach ((array) data_get($market, 'sections', []) as $section) {
                    $values[] = $section['title'] ?? null;
                    foreach ((array) ($section['points'] ?? []) as $point) {
                        $values[] = $point;
                    }
                }

                return $values;
            })
            ->filter(fn ($value): bool => is_string($value))
            ->flatMap(fn (string $value): array => preg_split('/[,;]+/', $value) ?: [])
            ->map(fn ($value): string => trim((string) $value))
            ->filter(fn (string $value): bool => mb_strlen($value) >= 4 && mb_strlen($value) <= 80)
            ->unique(fn (string $value): string => $this->key($value))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{matched_text:string,position_start:int,position_end:int,snippet:string}>
     */
    private function matches(string $text, string $term): array
    {
        $term = trim($term);
        if ($text === '' || $term === '') {
            return [];
        }

        $pattern = '/(?<![\pL\pN])'.preg_quote($term, '/').'(?![\pL\pN])/iu';
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

        return collect($matches[0] ?? [])
            ->map(fn (array $match): array => [
                'matched_text' => (string) $match[0],
                'position_start' => (int) $match[1],
                'position_end' => (int) $match[1] + strlen((string) $match[0]),
                'snippet' => $this->snippet($text, (int) $match[1], strlen((string) $match[0])),
            ])
            ->all();
    }

    private function storeMention(PageSnapshot $snapshot, ?PageContentExtraction $extraction, PageEntity $entity, array $match, mixed $observedAt): PageMention
    {
        $dedupeHash = hash('sha256', implode('|', [
            $snapshot->id,
            $entity->entity_type,
            $entity->entity_key,
            $match['position_start'],
        ]));

        return PageMention::query()->updateOrCreate(
            [
                'workspace_id' => $snapshot->workspace_id,
                'dedupe_hash' => $dedupeHash,
            ],
            [
                'organization_id' => $snapshot->organization_id,
                'client_site_id' => $snapshot->client_site_id,
                'monitored_page_id' => $snapshot->monitored_page_id,
                'page_snapshot_id' => $snapshot->id,
                'page_content_extraction_id' => $extraction?->id,
                'page_entity_id' => $entity->id,
                'mention_type' => $entity->entity_type,
                'entity_type' => $entity->entity_type,
                'entity_key' => $entity->entity_key,
                'entity_name' => $entity->entity_name,
                'matched_text' => $match['matched_text'],
                'source_field' => 'normalized_content',
                'position_start' => $match['position_start'],
                'position_end' => $match['position_end'],
                'evidence_snippet' => $match['snippet'],
                'confidence_score' => $entity->confidence_score,
                'observed_at' => $observedAt,
                'analysis_method' => 'deterministic_match',
                'model_used' => self::ENTITY_MODEL,
                'metadata_json' => ['page_entity_key' => $entity->entity_key],
            ]
        );
    }

    private function storeSentiment(
        PageSnapshot $snapshot,
        ?PageContentExtraction $extraction,
        string $targetType,
        string $targetKey,
        ?string $targetName,
        string $text,
        array $evidence,
        mixed $analyzedAt,
        ?string $targetRefType,
        ?string $targetRefId
    ): PageSentiment {
        $result = $this->sentiment($text);

        return PageSentiment::query()->updateOrCreate(
            [
                'page_snapshot_id' => $snapshot->id,
                'target_type' => $targetType,
                'target_key' => $targetKey,
            ],
            [
                'organization_id' => $snapshot->organization_id,
                'workspace_id' => $snapshot->workspace_id,
                'client_site_id' => $snapshot->client_site_id,
                'monitored_page_id' => $snapshot->monitored_page_id,
                'page_content_extraction_id' => $extraction?->id,
                'target_name' => $targetName,
                'target_ref_type' => $targetRefType,
                'target_ref_id' => $targetRefId,
                'compound_score' => $result['compound_score'],
                'label' => $result['label'],
                'confidence_score' => $result['confidence_score'],
                'analysis_method' => 'lexicon',
                'model_used' => self::SENTIMENT_MODEL,
                'analyzer_version' => 'page-sentiment-analyzer-v1',
                'explanation' => $result['explanation'],
                'evidence_json' => $evidence,
                'analyzed_at' => $analyzedAt,
                'metadata_json' => [
                    'positive_hits' => $result['positive_hits'],
                    'negative_hits' => $result['negative_hits'],
                ],
            ]
        );
    }

    private function storeScore(PageSnapshot $snapshot, ?PageContentExtraction $extraction, array $score, mixed $computedAt): PageScore
    {
        $previous = PageScore::query()
            ->where('monitored_page_id', $snapshot->monitored_page_id)
            ->where('score_type', $score['type'])
            ->where('score_version', 'page-basic-score-v1')
            ->where('page_snapshot_id', '!=', $snapshot->id)
            ->latest('computed_at')
            ->first();

        return PageScore::query()->updateOrCreate(
            [
                'page_snapshot_id' => $snapshot->id,
                'score_type' => $score['type'],
                'score_version' => 'page-basic-score-v1',
            ],
            [
                'organization_id' => $snapshot->organization_id,
                'workspace_id' => $snapshot->workspace_id,
                'client_site_id' => $snapshot->client_site_id,
                'monitored_page_id' => $snapshot->monitored_page_id,
                'page_content_extraction_id' => $extraction?->id,
                'score' => $score['score'],
                'previous_score' => $previous?->score,
                'delta' => $previous ? round((float) $score['score'] - (float) $previous->score, 2) : null,
                'calculation_method' => 'deterministic',
                'model_used' => self::SCORE_MODEL,
                'explanation' => $score['explanation'],
                'breakdown_json' => $score['breakdown'],
                'evidence_json' => ['page_snapshot_id' => $snapshot->id],
                'computed_at' => $computedAt,
                'metadata_json' => ['phase' => 'page_intelligence_analysis_v1'],
            ]
        );
    }

    /**
     * @return array{compound_score:float,label:string,confidence_score:float,explanation:string,positive_hits:int,negative_hits:int}
     */
    private function sentiment(string $text): array
    {
        $positive = ['accurate', 'best', 'clear', 'excellent', 'fast', 'great', 'growth', 'improve', 'leading', 'positive', 'reliable', 'strong', 'trusted', 'win'];
        $negative = ['bad', 'decline', 'fail', 'fails', 'negative', 'poor', 'problem', 'risk', 'risky', 'slow', 'weak', 'worse'];
        $normalized = mb_strtolower($text);

        $positiveHits = collect($positive)->sum(fn (string $term): int => preg_match_all('/(?<![\pL\pN])'.preg_quote($term, '/').'(?![\pL\pN])/iu', $normalized));
        $negativeHits = collect($negative)->sum(fn (string $term): int => preg_match_all('/(?<![\pL\pN])'.preg_quote($term, '/').'(?![\pL\pN])/iu', $normalized));
        $total = max(1, $positiveHits + $negativeHits);
        $compound = round(($positiveHits - $negativeHits) / $total, 4);
        $label = match (true) {
            $compound >= 0.15 => 'positive',
            $compound <= -0.15 => 'negative',
            default => 'neutral',
        };

        return [
            'compound_score' => $compound,
            'label' => $label,
            'confidence_score' => round(min(95, 45 + (abs($compound) * 40) + (min($total, 5) * 4)), 2),
            'explanation' => sprintf('Deterministic lexicon sentiment found %d positive and %d negative cues.', $positiveHits, $negativeHits),
            'positive_hits' => $positiveHits,
            'negative_hits' => $negativeHits,
        ];
    }

    private function prominence(int $mentionCount, int $firstPosition, int $textLength): float
    {
        $earlyScore = $textLength > 0 ? max(0, 50 * (1 - ($firstPosition / $textLength))) : 0;

        return round(min(100, $earlyScore + ($mentionCount * 15)), 2);
    }

    private function entityConfidence(string $type, int $mentionCount): float
    {
        $base = match ($type) {
            PageEntity::TYPE_BRAND, PageEntity::TYPE_COMPETITOR => 75,
            default => 60,
        };

        return round(min(98, $base + ($mentionCount * 5)), 2);
    }

    private function snippet(string $text, int $position, int $length): string
    {
        $sentenceStart = max(
            strrpos(substr($text, 0, $position), '.') ?: 0,
            strrpos(substr($text, 0, $position), '!') ?: 0,
            strrpos(substr($text, 0, $position), '?') ?: 0,
            strrpos(substr($text, 0, $position), "\n") ?: 0,
        );
        $sentenceEndCandidates = collect(['.', '!', '?', "\n"])
            ->map(fn (string $char): int|false => strpos($text, $char, $position + $length))
            ->filter(fn ($candidate): bool => $candidate !== false)
            ->map(fn ($candidate): int => (int) $candidate)
            ->values();

        if ($sentenceEndCandidates->isNotEmpty()) {
            $sentenceEnd = $sentenceEndCandidates->min() + 1;
            $sentence = substr($text, $sentenceStart, $sentenceEnd - $sentenceStart);

            if (strlen((string) $sentence) <= 260) {
                return trim((string) preg_replace('/\s+/', ' ', (string) $sentence));
            }
        }

        $start = max(0, $position - 80);
        $snippet = substr($text, $start, $length + 160);

        return trim((string) preg_replace('/\s+/', ' ', $snippet));
    }

    private function key(string $value): string
    {
        $key = Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

        return $key !== '' ? $key : hash('sha256', $value);
    }
}
