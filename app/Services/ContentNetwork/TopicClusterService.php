<?php

namespace App\Services\ContentNetwork;

use App\Models\ArticleEntity;
use App\Models\Content;
use App\Models\ContentCluster;
use App\Models\Draft;
use App\Models\SeoAudit;
use App\Models\SeoAuditPage;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TopicClusterService
{
    /**
     * @return array{
     *   clusters:Collection<int,ContentCluster>,
     *   content_signals:array<string,array<string,mixed>>,
     *   weak_areas:array<int,array<string,mixed>>,
     *   summary:array<string,mixed>
     * }
     */
    public function buildAndPersist(Workspace $workspace): array
    {
        $contents = $this->publishedContents($workspace);

        if ($contents->isEmpty()) {
            ContentCluster::query()->where('workspace_id', $workspace->id)->delete();

            return [
                'clusters' => collect(),
                'content_signals' => [],
                'weak_areas' => [],
                'summary' => [
                    'published_content_count' => 0,
                    'cluster_count' => 0,
                ],
            ];
        }

        $signals = $this->buildSignals($workspace, $contents);
        $clusterBuckets = $this->clusterBuckets($signals);
        $rows = $this->clusterRows($workspace, $contents, $signals, $clusterBuckets);

        $persisted = DB::transaction(function () use ($workspace, $rows): Collection {
            ContentCluster::query()->where('workspace_id', $workspace->id)->delete();

            $created = collect();
            foreach ($rows as $row) {
                $created->push(ContentCluster::query()->create($row));
            }

            return $created;
        });

        $weakAreas = $this->weakAreas($persisted);

        return [
            'clusters' => $persisted,
            'content_signals' => $signals,
            'weak_areas' => $weakAreas,
            'summary' => [
                'published_content_count' => $contents->count(),
                'cluster_count' => $persisted->count(),
                'average_cluster_score' => round((float) $persisted->avg('cluster_score'), 2),
            ],
        ];
    }

    /**
     * @return Collection<int,Content>
     */
    private function publishedContents(Workspace $workspace): Collection
    {
        return Content::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query): void {
                $query->where('publish_status', 'published')
                    ->orWhere('status', 'published')
                    ->orWhereNotNull('published_url');
            })
            ->where('status', '!=', 'archived')
            ->orderBy('published_url')
            ->orderBy('id')
            ->get([
                'id',
                'workspace_id',
                'client_site_id',
                'title',
                'primary_keyword',
                'status',
                'publish_status',
                'published_url',
                'publish_url_key',
                'canonical_url_key',
                'created_at',
            ]);
    }

    /**
     * @param Collection<int,Content> $contents
     * @return array<string,array<string,mixed>>
     */
    private function buildSignals(Workspace $workspace, Collection $contents): array
    {
        $contentIds = $contents->pluck('id')->all();

        $latestDraftsByContent = Draft::query()
            ->whereIn('content_id', $contentIds)
            ->orderByDesc('created_at')
            ->get(['id', 'content_id'])
            ->groupBy('content_id')
            ->map(fn (Collection $group): ?Draft => $group->first());

        $draftIds = $latestDraftsByContent
            ->filter()
            ->map(fn (Draft $draft): string => (string) $draft->id)
            ->values()
            ->all();

        $entitiesByDraft = ArticleEntity::query()
            ->whereIn('article_id', $draftIds)
            ->orderByDesc('confidence')
            ->get(['article_id', 'entity', 'entity_type', 'confidence'])
            ->groupBy('article_id');

        $latestAudit = SeoAudit::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'completed')
            ->latest('finished_at')
            ->first(['id']);

        $seoPagesByContent = collect();
        if ($latestAudit) {
            $seoPagesByContent = SeoAuditPage::query()
                ->where('seo_audit_id', $latestAudit->id)
                ->whereIn('argusly_content_id', $contentIds)
                ->get(['argusly_content_id', 'word_count', 'internal_links_count'])
                ->keyBy('argusly_content_id');
        }

        $signals = [];
        foreach ($contents as $content) {
            $draft = $latestDraftsByContent->get((string) $content->id);
            $entities = $draft ? ($entitiesByDraft->get((string) $draft->id, collect())) : collect();
            $entityTerms = $entities
                ->map(fn (ArticleEntity $entity): string => (string) $entity->entity)
                ->filter(fn (string $entity): bool => trim($entity) !== '')
                ->values()
                ->all();

            $terms = $this->normalizedTerms([
                (string) ($content->primary_keyword ?? ''),
                (string) ($content->title ?? ''),
                ...$entityTerms,
            ]);

            $topicKeyword = $this->topicKeyword($content, $terms);
            $clusterKey = $this->clusterKey($topicKeyword, $terms);
            $seoPage = $seoPagesByContent->get((string) $content->id);

            $signals[(string) $content->id] = [
                'content_id' => (string) $content->id,
                'title' => (string) ($content->title ?? ''),
                'primary_keyword' => trim((string) ($content->primary_keyword ?? '')),
                'topic_keyword' => $topicKeyword,
                'cluster_key' => $clusterKey,
                'terms' => $terms,
                'internal_links_count' => (int) data_get($seoPage, 'internal_links_count', 0),
                'word_count' => (int) data_get($seoPage, 'word_count', 0),
            ];
        }

        return $signals;
    }

    /**
     * @param array<string,array<string,mixed>> $signals
     * @return array<string,array<int,string>>
     */
    private function clusterBuckets(array $signals): array
    {
        $buckets = [];

        foreach ($signals as $contentId => $signal) {
            $key = (string) ($signal['cluster_key'] ?? '');
            if ($key === '') {
                $key = 'general';
            }

            if (! isset($buckets[$key])) {
                $buckets[$key] = [];
            }

            $buckets[$key][] = (string) $contentId;
        }

        ksort($buckets);

        return $buckets;
    }

    /**
     * @param Collection<int,Content> $contents
     * @param array<string,array<string,mixed>> $signals
     * @param array<string,array<int,string>> $clusterBuckets
     * @return array<int,array<string,mixed>>
     */
    private function clusterRows(
        Workspace $workspace,
        Collection $contents,
        array $signals,
        array $clusterBuckets
    ): array {
        $contentById = $contents->keyBy('id');
        $rows = [];

        foreach ($clusterBuckets as $clusterKey => $contentIds) {
            $contentIds = collect($contentIds)
                ->map(fn (string $id): string => trim($id))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($contentIds === []) {
                continue;
            }

            $topicKeyword = (string) ($signals[$contentIds[0]]['topic_keyword'] ?? $clusterKey);
            $pillarId = $this->pillarCandidateId($contentIds, $signals);
            $supportingIds = array_values(array_filter(
                $contentIds,
                fn (string $id): bool => $id !== $pillarId
            ));

            $termPool = collect($contentIds)
                ->flatMap(fn (string $id): array => (array) ($signals[$id]['terms'] ?? []))
                ->map(fn (string $term): string => trim($term))
                ->filter()
                ->countBy()
                ->sortDesc()
                ->keys()
                ->take(8)
                ->values()
                ->all();

            $averageInternalLinks = collect($contentIds)
                ->map(fn (string $id): int => (int) ($signals[$id]['internal_links_count'] ?? 0))
                ->avg();

            $clusterScore = $this->clusterScore(
                articleCount: count($contentIds),
                hasPillar: $pillarId !== '',
                averageInternalLinks: (float) ($averageInternalLinks ?? 0.0),
            );

            $rows[] = [
                'workspace_id' => (string) $workspace->id,
                'name' => $this->clusterName($topicKeyword),
                'topic_keyword' => $topicKeyword,
                'pillar_content_id' => $pillarId !== '' ? $pillarId : null,
                'supporting_content_ids' => $supportingIds,
                'cluster_score' => $clusterScore,
                'meta' => [
                    'content_ids' => $contentIds,
                    'top_terms' => $termPool,
                    'related_article_count' => count($contentIds),
                    'pillar_title' => $pillarId !== '' ? (string) data_get($contentById->get($pillarId), 'title', '') : '',
                    'average_internal_links' => round((float) ($averageInternalLinks ?? 0.0), 2),
                    'cluster_key' => $clusterKey,
                ],
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $rows;
    }

    /**
     * @param array<int,string> $contentIds
     * @param array<string,array<string,mixed>> $signals
     */
    private function pillarCandidateId(array $contentIds, array $signals): string
    {
        $bestId = '';
        $bestScore = -INF;

        foreach ($contentIds as $contentId) {
            $internalLinks = (int) ($signals[$contentId]['internal_links_count'] ?? 0);
            $wordCount = (int) ($signals[$contentId]['word_count'] ?? 0);
            $primaryKeyword = trim((string) ($signals[$contentId]['primary_keyword'] ?? ''));

            $score = ($internalLinks * 1.8)
                + ($wordCount / 700)
                + ($primaryKeyword !== '' ? 2.5 : 0.0);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = $contentId;
            }
        }

        return $bestId;
    }

    private function clusterScore(int $articleCount, bool $hasPillar, float $averageInternalLinks): float
    {
        $score = 20
            + min(45, $articleCount * 12)
            + min(25, $averageInternalLinks * 5)
            + ($hasPillar ? 10 : 0);

        return round((float) max(0, min(100, $score)), 2);
    }

    private function clusterName(string $topicKeyword): string
    {
        $keyword = trim($topicKeyword);

        if ($keyword === '') {
            return 'General Cluster';
        }

        return Str::title($keyword) . ' Cluster';
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,string>
     */
    private function normalizedTerms(array $values): array
    {
        $stopwords = [
            'the', 'and', 'for', 'with', 'that', 'this', 'from', 'into', 'your', 'about',
            'een', 'het', 'voor', 'met', 'van', 'naar', 'door', 'over', 'onder', 'bij',
        ];

        return collect($values)
            ->flatMap(function (mixed $value): array {
                $string = strtolower(trim((string) $value));
                if ($string === '') {
                    return [];
                }

                return preg_split('/[^a-z0-9]+/i', $string) ?: [];
            })
            ->map(fn (string $term): string => trim($term))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 3)
            ->reject(fn (string $term): bool => in_array($term, $stopwords, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $terms
     */
    private function topicKeyword(Content $content, array $terms): string
    {
        $primaryKeyword = strtolower(trim((string) ($content->primary_keyword ?? '')));
        if ($primaryKeyword !== '') {
            return $primaryKeyword;
        }

        return $terms[0] ?? 'general';
    }

    /**
     * @param array<int,string> $terms
     */
    private function clusterKey(string $topicKeyword, array $terms): string
    {
        $topic = trim(strtolower($topicKeyword));
        if ($topic !== '') {
            return Str::slug(collect(preg_split('/\s+/', $topic) ?: [])->take(2)->implode(' '));
        }

        return $terms[0] ?? 'general';
    }

    /**
     * @param Collection<int,ContentCluster> $clusters
     * @return array<int,array<string,mixed>>
     */
    private function weakAreas(Collection $clusters): array
    {
        return $clusters
            ->filter(function (ContentCluster $cluster): bool {
                $supporting = collect((array) ($cluster->supporting_content_ids ?? []))->filter()->count();
                $score = (float) ($cluster->cluster_score ?? 0);

                return $supporting < 2 || $score < 55;
            })
            ->map(function (ContentCluster $cluster): array {
                $supporting = collect((array) ($cluster->supporting_content_ids ?? []))->filter()->count();
                $score = (float) ($cluster->cluster_score ?? 0);

                $reasons = [];
                if ($supporting < 2) {
                    $reasons[] = 'Cluster has limited supporting content.';
                }
                if ($score < 55) {
                    $reasons[] = 'Cluster coverage score is low.';
                }

                return [
                    'cluster_id' => (string) $cluster->id,
                    'cluster_name' => (string) $cluster->name,
                    'cluster_score' => $score,
                    'reasons' => $reasons,
                ];
            })
            ->values()
            ->all();
    }
}
