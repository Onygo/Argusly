<?php

namespace App\Services\Visibility;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\FeatureFlag;
use App\Models\Topic;
use App\Models\VisibilityCitation;
use App\Models\VisibilityProviderRun;
use App\Models\VisibilityScore;
use App\Models\VisibilityTrend;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AiVisibilityDashboardService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dashboard(Account $account, Brand $brand, array $filters = []): array
    {
        $runs = $this->completedRuns($account, $brand, $filters)
            ->with(['citations', 'answerEntities'])
            ->latest('captured_at')
            ->limit(250)
            ->get();

        $competitors = $this->competitors($account, $brand);
        $brandPresence = $this->brandPresence($runs, $brand);
        $competitorPresence = $this->competitorPresence($runs, $competitors);
        $citations = $runs->sum(fn (VisibilityProviderRun $run): int => $run->citations->count());

        return [
            'scorecards' => [
                'visibility_score' => $this->averageScore($runs),
                'share_of_ai_voice' => $this->shareOfAiVoice($brandPresence, $competitorPresence),
                'brand_presence' => $brandPresence,
                'competitor_presence' => $competitorPresence,
                'citation_count' => $citations,
                'avg_citations' => $runs->isEmpty() ? 0 : round($citations / max(1, $runs->count()), 1),
            ],
            'scoreTrend' => $this->scoreTrend($account, $brand, $filters),
            'deepScoringEnabled' => $this->deepScoringEnabled(),
            'deepScorecards' => $this->deepScorecards($account, $brand, $filters),
            'deepTrend' => $this->deepTrend($account, $brand, $filters),
            'providerBenchmarks' => $this->providerBenchmarks($runs, $brand, $competitors),
            'competitorComparison' => $this->competitorComparison($runs, $competitors),
            'topicOwnership' => $this->topicOwnership($account, $brand, $runs, $competitors),
            'recommendations' => $this->recommendations($runs, $brand, $competitors),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<VisibilityCitation>
     */
    public function citations(Account $account, Brand $brand, array $filters = []): LengthAwarePaginator
    {
        return VisibilityCitation::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->with(['providerRun.promptTemplate'])
            ->when($filters['domain'] ?? null, fn (Builder $query, string $domain) => $query->where('domain', 'like', "%{$domain}%"))
            ->when($filters['q'] ?? null, fn (Builder $query, string $term) => $query->where(function (Builder $scope) use ($term): void {
                $scope->where('url', 'like', "%{$term}%")
                    ->orWhere('title', 'like', "%{$term}%")
                    ->orWhere('snippet', 'like', "%{$term}%");
            }))
            ->when($filters['provider'] ?? null, fn (Builder $query, string $provider) => $query->whereHas('providerRun', fn (Builder $run) => $run->where('provider', $provider)))
            ->when($filters['language'] ?? null, fn (Builder $query, string $language) => $query->whereHas('providerRun', fn (Builder $run) => $run->where('language', $language)))
            ->when($filters['market'] ?? null, fn (Builder $query, string $market) => $query->whereHas('providerRun', fn (Builder $run) => $run->where('market', $market)))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->whereHas('providerRun', fn (Builder $run) => $run->whereDate('captured_at', '>=', $from)))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->whereHas('providerRun', fn (Builder $run) => $run->whereDate('captured_at', '<=', $to)))
            ->latest()
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<VisibilityProviderRun>
     */
    private function completedRuns(Account $account, Brand $brand, array $filters = []): Builder
    {
        return VisibilityProviderRun::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where('status', 'completed')
            ->when($filters['language'] ?? null, fn (Builder $query, string $language) => $query->where('language', $language))
            ->when($filters['market'] ?? null, fn (Builder $query, string $market) => $query->where('market', $market))
            ->when($filters['provider'] ?? null, fn (Builder $query, string $provider) => $query->where('provider', $provider))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->whereDate('captured_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->whereDate('captured_at', '<=', $to));
    }

    private function deepScoringEnabled(): bool
    {
        return FeatureFlag::query()
            ->where('key', 'ai_visibility_deep_scoring')
            ->where('enabled', true)
            ->where(function (Builder $query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int|null>
     */
    private function deepScorecards(Account $account, Brand $brand, array $filters): array
    {
        $scores = $this->deepScores($account, $brand, $filters)
            ->latest()
            ->limit(100)
            ->get();

        return [
            'ai_attention_score' => $scores->isEmpty() ? null : (int) round($scores->avg('ai_attention_score')),
            'answer_presence_score' => $scores->isEmpty() ? null : (int) round($scores->avg('answer_presence_score')),
            'citation_score' => $scores->isEmpty() ? null : (int) round($scores->avg('citation_score')),
            'owned_source_presence' => $scores->isEmpty() ? null : (int) round($scores->avg('source_presence_score')),
            'competitor_presence_score' => $scores->isEmpty() ? null : (int) round($scores->avg('competitor_presence_score')),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{date: string, score: int|null, answer_presence: int|null, citation: int|null, owned_source: int|null, competitor_presence: int|null, count: int}>
     */
    private function deepTrend(Account $account, Brand $brand, array $filters): Collection
    {
        $trends = VisibilityTrend::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->where('period', 'day')
            ->whereNull('provider')
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->whereDate('period_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->whereDate('period_date', '<=', $to))
            ->latest('period_date')
            ->limit(30)
            ->get()
            ->sortBy('period_date')
            ->values();

        if ($trends->isNotEmpty()) {
            return $trends->map(fn (VisibilityTrend $trend): array => [
                'date' => $trend->period_date->toDateString(),
                'score' => $trend->ai_attention_score,
                'answer_presence' => $trend->answer_presence_score,
                'citation' => $trend->citation_score,
                'owned_source' => $trend->source_presence_score,
                'competitor_presence' => $trend->competitor_presence_score,
                'count' => $trend->scores_count,
            ]);
        }

        return $this->deepScores($account, $brand, $filters)
            ->where('created_at', '>=', now()->subDays(30))
            ->get()
            ->groupBy(fn (VisibilityScore $score): string => $score->created_at->toDateString())
            ->map(fn (Collection $scores, string $date): array => [
                'date' => $date,
                'score' => (int) round($scores->avg('ai_attention_score')),
                'answer_presence' => (int) round($scores->avg('answer_presence_score')),
                'citation' => (int) round($scores->avg('citation_score')),
                'owned_source' => (int) round($scores->avg('source_presence_score')),
                'competitor_presence' => (int) round($scores->avg('competitor_presence_score')),
                'count' => $scores->count(),
            ])
            ->sortBy('date')
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<VisibilityScore>
     */
    private function deepScores(Account $account, Brand $brand, array $filters): Builder
    {
        return VisibilityScore::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->when($filters['provider'] ?? null, fn (Builder $query, string $provider) => $query->where('provider', $provider))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->whereDate('created_at', '<=', $to));
    }

    /**
     * @return Collection<int, Competitor>
     */
    private function competitors(Account $account, Brand $brand): Collection
    {
        return Competitor::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int, VisibilityProviderRun>  $runs
     */
    private function averageScore(Collection $runs): ?int
    {
        $scores = $runs
            ->map(fn (VisibilityProviderRun $run): ?int => $this->score($run))
            ->filter(fn (?int $score): bool => $score !== null);

        return $scores->isEmpty() ? null : (int) round($scores->avg());
    }

    /**
     * @param  Collection<int, VisibilityProviderRun>  $runs
     */
    private function brandPresence(Collection $runs, Brand $brand): int
    {
        return $runs->filter(fn (VisibilityProviderRun $run): bool => $this->mentions($run, $brand->name, 'brand'))->count();
    }

    /**
     * @param  Collection<int, VisibilityProviderRun>  $runs
     * @param  Collection<int, Competitor>  $competitors
     */
    private function competitorPresence(Collection $runs, Collection $competitors): int
    {
        if ($competitors->isEmpty()) {
            return 0;
        }

        return $runs->sum(fn (VisibilityProviderRun $run): int => $competitors
            ->filter(fn (Competitor $competitor): bool => $this->mentions($run, $competitor->name, 'competitor'))
            ->count());
    }

    private function shareOfAiVoice(int $brandPresence, int $competitorPresence): int
    {
        $total = $brandPresence + $competitorPresence;

        return $total === 0 ? 0 : (int) round(($brandPresence / $total) * 100);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{date: string, score: int|null, runs: int}>
     */
    private function scoreTrend(Account $account, Brand $brand, array $filters): Collection
    {
        return $this->completedRuns($account, $brand, $filters)
            ->where('captured_at', '>=', now()->subDays(30))
            ->get()
            ->groupBy(fn (VisibilityProviderRun $run): string => $run->captured_at?->toDateString() ?? now()->toDateString())
            ->map(fn (Collection $runs, string $date): array => [
                'date' => $date,
                'score' => $this->averageScore($runs),
                'runs' => $runs->count(),
            ])
            ->sortBy('date')
            ->values();
    }

    /**
     * @param  Collection<int, VisibilityProviderRun>  $runs
     * @param  Collection<int, Competitor>  $competitors
     * @return Collection<int, array<string, mixed>>
     */
    private function providerBenchmarks(Collection $runs, Brand $brand, Collection $competitors): Collection
    {
        return $runs
            ->groupBy('provider')
            ->map(fn (Collection $providerRuns, string $provider): array => [
                'provider' => $provider,
                'runs' => $providerRuns->count(),
                'score' => $this->averageScore($providerRuns),
                'brand_presence' => $this->brandPresence($providerRuns, $brand),
                'competitor_presence' => $this->competitorPresence($providerRuns, $competitors),
                'citations' => $providerRuns->sum(fn (VisibilityProviderRun $run): int => $run->citations->count()),
            ])
            ->sortByDesc(fn (array $row): int => (int) ($row['score'] ?? 0))
            ->values();
    }

    /**
     * @param  Collection<int, VisibilityProviderRun>  $runs
     * @param  Collection<int, Competitor>  $competitors
     * @return Collection<int, array<string, mixed>>
     */
    private function competitorComparison(Collection $runs, Collection $competitors): Collection
    {
        return $competitors
            ->map(fn (Competitor $competitor): array => [
                'name' => $competitor->name,
                'mentions' => $runs->filter(fn (VisibilityProviderRun $run): bool => $this->mentions($run, $competitor->name, 'competitor'))->count(),
                'positive' => $runs->filter(fn (VisibilityProviderRun $run): bool => $this->mentions($run, $competitor->name, 'competitor', 'positive'))->count(),
                'latest_seen_at' => $runs
                    ->filter(fn (VisibilityProviderRun $run): bool => $this->mentions($run, $competitor->name, 'competitor'))
                    ->sortByDesc('captured_at')
                    ->first()?->captured_at,
            ])
            ->sortByDesc('mentions')
            ->values();
    }

    /**
     * @param  Collection<int, VisibilityProviderRun>  $runs
     * @param  Collection<int, Competitor>  $competitors
     * @return Collection<int, array<string, mixed>>
     */
    private function topicOwnership(Account $account, Brand $brand, Collection $runs, Collection $competitors): Collection
    {
        $topics = Topic::query()
            ->where('account_id', $account->id)
            ->where(fn (Builder $query) => $query->whereNull('brand_id')->orWhere('brand_id', $brand->id))
            ->active()
            ->orderBy('name')
            ->limit(20)
            ->get();

        if ($topics->isEmpty()) {
            return $this->fallbackTopicOwnership($runs, $brand, $competitors);
        }

        return $topics
            ->map(function (Topic $topic) use ($runs, $brand, $competitors): array {
                $topicRuns = $runs->filter(fn (VisibilityProviderRun $run): bool => $this->containsTerm($run->query.' '.$run->normalized_answer, $topic->name));

                return [
                    'topic' => $topic->name,
                    'runs' => $topicRuns->count(),
                    'score' => $this->averageScore($topicRuns),
                    'brand_presence' => $this->brandPresence($topicRuns, $brand),
                    'competitor_presence' => $this->competitorPresence($topicRuns, $competitors),
                    'ownership' => $this->shareOfAiVoice($this->brandPresence($topicRuns, $brand), $this->competitorPresence($topicRuns, $competitors)),
                ];
            })
            ->filter(fn (array $row): bool => $row['runs'] > 0)
            ->sortByDesc('ownership')
            ->take(8)
            ->values();
    }

    /**
     * @param  Collection<int, VisibilityProviderRun>  $runs
     * @param  Collection<int, Competitor>  $competitors
     * @return Collection<int, array<string, mixed>>
     */
    private function fallbackTopicOwnership(Collection $runs, Brand $brand, Collection $competitors): Collection
    {
        return $runs
            ->groupBy(fn (VisibilityProviderRun $run): string => $run->intent ?: $run->market ?: 'Tracked prompts')
            ->map(fn (Collection $topicRuns, string $topic): array => [
                'topic' => Str::headline($topic),
                'runs' => $topicRuns->count(),
                'score' => $this->averageScore($topicRuns),
                'brand_presence' => $this->brandPresence($topicRuns, $brand),
                'competitor_presence' => $this->competitorPresence($topicRuns, $competitors),
                'ownership' => $this->shareOfAiVoice($this->brandPresence($topicRuns, $brand), $this->competitorPresence($topicRuns, $competitors)),
            ])
            ->sortByDesc('ownership')
            ->take(8)
            ->values();
    }

    /**
     * @param  Collection<int, VisibilityProviderRun>  $runs
     * @param  Collection<int, Competitor>  $competitors
     * @return Collection<int, array<string, mixed>>
     */
    private function recommendations(Collection $runs, Brand $brand, Collection $competitors): Collection
    {
        return $runs
            ->filter(fn (VisibilityProviderRun $run): bool => ($this->score($run) ?? 100) < 70 || $run->citations->isEmpty())
            ->sortBy(fn (VisibilityProviderRun $run): int => $this->score($run) ?? 0)
            ->take(6)
            ->map(fn (VisibilityProviderRun $run): array => [
                'title' => ($this->score($run) ?? 0) < 50 ? 'Improve AI visibility' : 'Strengthen citations',
                'summary' => $this->recommendationSummary($run, $brand, $competitors),
                'provider' => $run->provider,
                'query' => $run->query,
                'score' => $this->score($run),
                'priority' => ($this->score($run) ?? 0) < 40 ? 'high' : 'medium',
            ])
            ->values();
    }

    /**
     * @param  Collection<int, Competitor>  $competitors
     */
    private function recommendationSummary(VisibilityProviderRun $run, Brand $brand, Collection $competitors): string
    {
        if (! $this->mentions($run, $brand->name, 'brand')) {
            return 'Brand presence is missing in this AI answer. Create answer-led content and rerun the prompt.';
        }

        if ($run->citations->isEmpty()) {
            return 'The brand appears, but the answer has no citations. Improve authoritative source coverage.';
        }

        if ($this->competitorPresence(collect([$run]), $competitors) > 0) {
            return 'Competitors are present in the answer. Clarify positioning and citation depth for this topic.';
        }

        return 'The score is below target. Review answer wording, cited domains and topic coverage.';
    }

    private function score(VisibilityProviderRun $run): ?int
    {
        $score = $run->metadata['visibility_score'] ?? null;

        return is_numeric($score) ? (int) $score : null;
    }

    private function mentions(VisibilityProviderRun $run, string $name, ?string $entityType = null, ?string $sentiment = null): bool
    {
        $entityMatch = $run->answerEntities->contains(function ($entity) use ($name, $entityType, $sentiment): bool {
            if (! $this->sameName((string) $entity->entity_name, $name)) {
                return false;
            }

            if ($entityType !== null && $entity->entity_type !== $entityType) {
                return false;
            }

            return $sentiment === null || $entity->sentiment === $sentiment;
        });

        return $entityMatch || $this->containsTerm((string) $run->normalized_answer, $name);
    }

    private function sameName(string $left, string $right): bool
    {
        return Str::lower(trim($left)) === Str::lower(trim($right));
    }

    private function containsTerm(string $haystack, string $needle): bool
    {
        return $needle !== '' && str_contains(Str::lower($haystack), Str::lower($needle));
    }
}
