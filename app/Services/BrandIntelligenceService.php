<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\Mention;
use App\Models\Narrative;
use App\Models\Source;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class BrandIntelligenceService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dashboard(Account $account, ?Brand $brand, array $filters = []): array
    {
        $mentions = $this->mentions($account, $brand, $filters)
            ->with(['source', 'entities', 'topics'])
            ->latest('published_at')
            ->limit(500)
            ->get();

        $competitors = $brand
            ? Competitor::query()->where('account_id', $account->id)->where('brand_id', $brand->id)->active()->orderBy('name')->get()
            : collect();

        return [
            'coverage' => $this->coverage($mentions),
            'sentiment' => $this->sentiment($mentions),
            'sourceCategories' => $this->sourceCategories($mentions),
            'journalists' => $this->journalists($mentions),
            'publications' => $this->publications($mentions),
            'shareOfVoice' => $this->shareOfVoice($mentions, $competitors),
            'reputation' => $this->reputation($mentions),
            'narratives' => $brand ? $this->narrativeMonitoring($account, $brand, $mentions) : collect(),
            'executiveInsights' => $this->executiveInsights($mentions, $competitors),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function exportRows(Account $account, ?Brand $brand, array $filters = []): Collection
    {
        return $this->mentions($account, $brand, $filters)
            ->with(['brand', 'source', 'entities', 'topics'])
            ->recent()
            ->limit(2000)
            ->get()
            ->map(fn (Mention $mention): array => [
                'published_at' => $mention->published_at?->toDateTimeString(),
                'brand' => $mention->brand?->name ?? 'Account-level',
                'title' => $mention->title,
                'source' => $mention->source?->name,
                'source_type' => $mention->source?->type,
                'publication' => $this->publicationName($mention),
                'author' => $mention->author,
                'sentiment' => $mention->sentiment,
                'impact_score' => $mention->impact_score,
                'url' => $mention->url,
                'topics' => $mention->topics->pluck('name')->implode(', '),
                'entities' => $mention->entities->pluck('entity_name')->implode(', '),
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Mention>
     */
    private function mentions(Account $account, ?Brand $brand, array $filters = []): Builder
    {
        $this->ensureBrandBelongsToAccount($account, $brand);

        return Mention::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                    ->whereNull('brand_id')
                    ->orWhere('brand_id', $brand->id)),
                fn (Builder $query) => $query->whereNull('brand_id'),
            )
            ->when($filters['source_id'] ?? null, fn (Builder $query, mixed $sourceId) => $query->where('source_id', (int) $sourceId))
            ->when($filters['source_type'] ?? null, fn (Builder $query, string $type) => $query->whereHas('source', fn (Builder $source) => $source->where('type', $type)))
            ->when($filters['sentiment'] ?? null, fn (Builder $query, string $sentiment) => $query->where('sentiment', $sentiment))
            ->when($filters['author'] ?? null, fn (Builder $query, string $author) => $query->where('author', 'like', "%{$author}%"))
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(function (Builder $scope) use ($term): void {
                $scope->where('title', 'like', "%{$term}%")
                    ->orWhere('content', 'like', "%{$term}%")
                    ->orWhere('url', 'like', "%{$term}%")
                    ->orWhere('author', 'like', "%{$term}%");
            }))
            ->when($filters['brand_id'] ?? null, function (Builder $query, mixed $brandId): void {
                $brandId === 'account'
                    ? $query->whereNull('brand_id')
                    : $query->where('brand_id', (int) $brandId);
            })
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->where('published_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->where('published_at', '<=', Carbon::parse($date)->endOfDay()));
    }

    /**
     * @param  Collection<int, Mention>  $mentions
     * @return array<string, mixed>
     */
    private function coverage(Collection $mentions): array
    {
        return [
            'mentions' => $mentions->count(),
            'sources' => $mentions->pluck('source_id')->filter()->unique()->count(),
            'publications' => $mentions->map(fn (Mention $mention): ?string => $this->publicationName($mention))->filter()->unique()->count(),
            'avg_impact' => $mentions->whereNotNull('impact_score')->isEmpty() ? null : (int) round($mentions->whereNotNull('impact_score')->avg('impact_score')),
            'trend' => $mentions
                ->groupBy(fn (Mention $mention): string => $mention->published_at?->toDateString() ?? $mention->created_at?->toDateString() ?? now()->toDateString())
                ->map(fn (Collection $group, string $date): array => ['date' => $date, 'mentions' => $group->count()])
                ->sortBy('date')
                ->values(),
        ];
    }

    /**
     * @param  Collection<int, Mention>  $mentions
     * @return array{positive: int, neutral: int, negative: int, mixed: int, unknown: int, total: int}
     */
    private function sentiment(Collection $mentions): array
    {
        $counts = $mentions->groupBy(fn (Mention $mention): string => $mention->sentiment ?: 'unknown')->map->count();

        return [
            'positive' => (int) ($counts['positive'] ?? 0),
            'neutral' => (int) ($counts['neutral'] ?? 0),
            'negative' => (int) ($counts['negative'] ?? 0),
            'mixed' => (int) ($counts['mixed'] ?? 0),
            'unknown' => (int) ($counts['unknown'] ?? 0),
            'total' => $mentions->count(),
        ];
    }

    /**
     * @param  Collection<int, Mention>  $mentions
     * @return Collection<int, array<string, mixed>>
     */
    private function sourceCategories(Collection $mentions): Collection
    {
        return $mentions
            ->groupBy(fn (Mention $mention): string => $mention->source?->type ?: 'uncategorized')
            ->map(fn (Collection $group, string $type): array => [
                'type' => $type,
                'mentions' => $group->count(),
                'sentiment_score' => $this->sentimentScore($group),
                'avg_impact' => $group->whereNotNull('impact_score')->isEmpty() ? null : (int) round($group->whereNotNull('impact_score')->avg('impact_score')),
            ])
            ->sortByDesc('mentions')
            ->values();
    }

    /**
     * @param  Collection<int, Mention>  $mentions
     * @return Collection<int, array<string, mixed>>
     */
    private function journalists(Collection $mentions): Collection
    {
        return $mentions
            ->filter(fn (Mention $mention): bool => filled($mention->author))
            ->groupBy(fn (Mention $mention): string => (string) $mention->author)
            ->map(fn (Collection $group, string $author): array => [
                'name' => $author,
                'mentions' => $group->count(),
                'publications' => $group->map(fn (Mention $mention): ?string => $this->publicationName($mention))->filter()->unique()->values(),
                'sentiment_score' => $this->sentimentScore($group),
                'avg_impact' => $group->whereNotNull('impact_score')->isEmpty() ? null : (int) round($group->whereNotNull('impact_score')->avg('impact_score')),
                'latest_at' => $group->sortByDesc('published_at')->first()?->published_at,
            ])
            ->sortByDesc('mentions')
            ->take(8)
            ->values();
    }

    /**
     * @param  Collection<int, Mention>  $mentions
     * @return Collection<int, array<string, mixed>>
     */
    private function publications(Collection $mentions): Collection
    {
        return $mentions
            ->groupBy(fn (Mention $mention): string => $this->publicationName($mention) ?: 'Unknown publication')
            ->map(fn (Collection $group, string $publication): array => [
                'name' => $publication,
                'mentions' => $group->count(),
                'source_types' => $group->pluck('source.type')->filter()->unique()->values(),
                'sentiment_score' => $this->sentimentScore($group),
                'avg_impact' => $group->whereNotNull('impact_score')->isEmpty() ? null : (int) round($group->whereNotNull('impact_score')->avg('impact_score')),
            ])
            ->sortByDesc('mentions')
            ->take(8)
            ->values();
    }

    /**
     * @param  Collection<int, Mention>  $mentions
     * @param  Collection<int, Competitor>  $competitors
     * @return array<string, mixed>
     */
    private function shareOfVoice(Collection $mentions, Collection $competitors): array
    {
        $competitorMentions = $mentions->filter(function (Mention $mention) use ($competitors): bool {
            $entityMatch = $mention->entities->contains(fn ($entity): bool => $entity->entity_type === 'competitor');
            $text = Str::lower($mention->title.' '.$mention->content);
            $textMatch = $competitors->contains(fn (Competitor $competitor): bool => $competitor->name !== '' && str_contains($text, Str::lower($competitor->name)));

            return $entityMatch || $textMatch;
        })->count();

        $brandMentions = max(0, $mentions->count() - $competitorMentions);
        $total = $brandMentions + $competitorMentions;

        return [
            'brand_mentions' => $brandMentions,
            'competitor_mentions' => $competitorMentions,
            'score' => $total === 0 ? 0 : (int) round(($brandMentions / $total) * 100),
        ];
    }

    /**
     * @param  Collection<int, Mention>  $mentions
     * @return array<string, mixed>
     */
    private function reputation(Collection $mentions): array
    {
        $score = $this->sentimentScore($mentions);
        $negativeImpact = $mentions->where('sentiment', 'negative')->sum(fn (Mention $mention): int => (int) ($mention->impact_score ?? 0));

        return [
            'score' => $score,
            'risk' => match (true) {
                $negativeImpact >= 180 || $score < 35 => 'high',
                $negativeImpact >= 80 || $score < 50 => 'medium',
                default => 'low',
            },
            'negative_impact' => $negativeImpact,
        ];
    }

    /**
     * @param  Collection<int, Mention>  $mentions
     * @return Collection<int, array<string, mixed>>
     */
    private function narrativeMonitoring(Account $account, Brand $brand, Collection $mentions): Collection
    {
        return Narrative::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where('status', 'active')
            ->withCount(['observations', 'gaps', 'mentions'])
            ->with(['topics'])
            ->orderByDesc('importance')
            ->limit(8)
            ->get()
            ->map(function (Narrative $narrative) use ($mentions): array {
                $textMatches = $mentions->filter(fn (Mention $mention): bool => $this->mentionMatchesNarrative($mention, $narrative));

                return [
                    'title' => $narrative->title,
                    'importance' => $narrative->importance,
                    'mentions' => max($narrative->mentions_count, $textMatches->count()),
                    'observations' => $narrative->observations_count,
                    'open_gaps' => $narrative->gaps_count,
                    'sentiment_score' => $this->sentimentScore($textMatches),
                ];
            });
    }

    /**
     * @param  Collection<int, Mention>  $mentions
     * @param  Collection<int, Competitor>  $competitors
     * @return Collection<int, array{title: string, summary: string, priority: string}>
     */
    private function executiveInsights(Collection $mentions, Collection $competitors): Collection
    {
        $insights = collect();
        $reputation = $this->reputation($mentions);
        $share = $this->shareOfVoice($mentions, $competitors);
        $topPublication = $this->publications($mentions)->first();
        $topJournalist = $this->journalists($mentions)->first();

        if ($mentions->count() === 0) {
            return collect([[
                'title' => 'Coverage baseline is empty',
                'summary' => 'No brand intelligence mentions match the current context yet.',
                'priority' => 'medium',
            ]]);
        }

        $insights->push([
            'title' => 'Reputation risk is '.Str::headline($reputation['risk']),
            'summary' => "Current reputation score is {$reputation['score']} with {$reputation['negative_impact']} negative impact points.",
            'priority' => $reputation['risk'] === 'high' ? 'high' : 'medium',
        ]);

        $insights->push([
            'title' => 'Share of voice is '.$share['score'].'%',
            'summary' => "{$share['brand_mentions']} brand-led mentions compared with {$share['competitor_mentions']} competitor-context mentions.",
            'priority' => $share['score'] < 50 ? 'high' : 'medium',
        ]);

        if ($topPublication) {
            $insights->push([
                'title' => 'Top publication: '.$topPublication['name'],
                'summary' => "{$topPublication['mentions']} mentions with average impact ".($topPublication['avg_impact'] ?? '-').'.',
                'priority' => 'low',
            ]);
        }

        if ($topJournalist) {
            $insights->push([
                'title' => 'Most active journalist: '.$topJournalist['name'],
                'summary' => "{$topJournalist['mentions']} tracked mentions across ".$topJournalist['publications']->implode(', ').'.',
                'priority' => 'low',
            ]);
        }

        return $insights->take(6)->values();
    }

    /**
     * @param  Collection<int, Mention>  $mentions
     */
    private function sentimentScore(Collection $mentions): int
    {
        if ($mentions->isEmpty()) {
            return 0;
        }

        $score = $mentions->sum(fn (Mention $mention): int => match ($mention->sentiment) {
            'positive' => 100,
            'mixed' => 55,
            'negative' => 0,
            default => 50,
        });

        return (int) round($score / max(1, $mentions->count()));
    }

    private function publicationName(Mention $mention): ?string
    {
        $metadataPublication = $mention->metadata['publication'] ?? $mention->metadata['publication_name'] ?? null;

        if (is_string($metadataPublication) && $metadataPublication !== '') {
            return $metadataPublication;
        }

        if ($mention->source?->name) {
            return $mention->source->name;
        }

        if ($mention->url) {
            return parse_url($mention->url, PHP_URL_HOST) ?: null;
        }

        return null;
    }

    private function mentionMatchesNarrative(Mention $mention, Narrative $narrative): bool
    {
        $text = Str::lower($mention->title.' '.$mention->content);

        if (str_contains($text, Str::lower($narrative->title))) {
            return true;
        }

        return $narrative->topics->contains(fn ($topic): bool => str_contains($text, Str::lower($topic->name)));
    }

    private function ensureBrandBelongsToAccount(Account $account, ?Brand $brand): void
    {
        if ($brand !== null && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Brand intelligence brand must belong to the account.');
        }
    }
}
