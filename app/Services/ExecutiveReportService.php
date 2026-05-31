<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\Ga4MetricSnapshot;
use App\Models\IntelligenceSignal;
use App\Models\PerformanceInsight;
use App\Models\Recommendation;
use App\Models\Report;
use App\Models\ReportSection;
use App\Models\ReportSnapshot;
use App\Models\SearchConsoleQuerySnapshot;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\VisibilitySnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ExecutiveReportService
{
    /**
     * @return Collection<int, Report>
     */
    public function reportsForTenant(Account $account, ?Brand $brand = null, int $limit = 20): Collection
    {
        return Report::query()
            ->forTenant($account, $brand)
            ->with(['brand', 'latestSnapshot' => fn ($query) => $query->limit(1)])
            ->latest('generated_at')
            ->limit($limit)
            ->get();
    }

    public function findForTenant(Account $account, ?Brand $brand, int $id): Report
    {
        return Report::query()
            ->forTenant($account, $brand)
            ->with(['brand', 'generator', 'sections', 'latestSnapshot' => fn ($query) => $query->limit(1)])
            ->whereKey($id)
            ->firstOrFail();
    }

    public function generate(Account $account, ?Brand $brand, User $user, string $type = 'executive'): Report
    {
        if (! in_array($type, Report::TYPES, true)) {
            throw new InvalidArgumentException("Invalid report type [{$type}].");
        }

        if ($brand !== null && $brand->account_id !== $account->id) {
            throw new InvalidArgumentException('Report brand must belong to the account.');
        }

        $period = $this->periodFor($type);
        $sections = $this->sections($account, $brand, $period['start'], $period['end']);

        $report = Report::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand?->id,
            'type' => $type,
            'title' => $this->title($type, $brand),
            'summary' => $this->summary($sections),
            'period_start' => $period['start']->toDateString(),
            'period_end' => $period['end']->toDateString(),
            'generated_by' => $user->id,
            'generated_at' => now(),
        ]);

        foreach ($sections as $position => $section) {
            ReportSection::query()->create([
                'report_id' => $report->id,
                'account_id' => $account->id,
                'brand_id' => $brand?->id,
                'section_type' => $section['section_type'],
                'title' => $section['title'],
                'summary' => $section['summary'],
                'payload' => $section['payload'],
                'position' => $position + 1,
            ]);
        }

        $report->load('sections');

        ReportSnapshot::query()->create([
            'report_id' => $report->id,
            'account_id' => $account->id,
            'brand_id' => $brand?->id,
            'payload' => [
                'report' => [
                    'id' => $report->id,
                    'type' => $report->type,
                    'title' => $report->title,
                    'summary' => $report->summary,
                    'period_start' => $report->period_start?->toDateString(),
                    'period_end' => $report->period_end?->toDateString(),
                ],
                'sections' => $report->sections->map(fn (ReportSection $section) => [
                    'section_type' => $section->section_type,
                    'title' => $section->title,
                    'summary' => $section->summary,
                    'payload' => $section->payload,
                    'position' => $section->position,
                ])->values()->all(),
            ],
            'html' => $this->renderHtml($report),
            'generated_at' => $report->generated_at,
        ]);

        return $report->refresh()->load(['sections', 'latestSnapshot' => fn ($query) => $query->limit(1)]);
    }

    /**
     * @return array<int, array{section_type: string, title: string, summary: string, payload: array<string, mixed>}>
     */
    private function sections(Account $account, ?Brand $brand, mixed $start, mixed $end): array
    {
        return [
            $this->visibilitySection($account, $brand, $start, $end),
            $this->contentSection($account, $brand, $start, $end),
            $this->searchSection($account, $brand, $start, $end),
            $this->socialSection($account, $brand, $start, $end),
            $this->recommendationsSection($account, $brand),
            $this->risksSection($account, $brand),
            $this->winsSection($account, $brand, $start, $end),
            $this->nextActionsSection($account, $brand),
        ];
    }

    private function visibilitySection(Account $account, ?Brand $brand, mixed $start, mixed $end): array
    {
        $query = VisibilitySnapshot::query()
            ->where('account_id', $account->id)
            ->tap(fn (Builder $query) => $this->scopeBrand($query, $brand))
            ->whereBetween('captured_at', [$start, $end]);

        $latest = (clone $query)->latest('captured_at')->first();
        $average = (clone $query)->avg('score');

        return $this->section('ai_visibility', 'AI visibility', 'Latest AI visibility signals across monitored providers.', [
            'snapshots' => (clone $query)->count(),
            'latest_score' => $latest?->score,
            'average_score' => $average !== null ? round((float) $average, 1) : null,
            'providers' => (clone $query)->distinct('provider')->count('provider'),
        ]);
    }

    private function contentSection(Account $account, ?Brand $brand, mixed $start, mixed $end): array
    {
        $totals = Ga4MetricSnapshot::query()
            ->where('account_id', $account->id)
            ->tap(fn (Builder $query) => $this->scopeBrand($query, $brand))
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('COALESCE(SUM(sessions), 0) as sessions_total')
            ->selectRaw('COALESCE(SUM(pageviews), 0) as pageviews_total')
            ->selectRaw('COALESCE(SUM(conversions), 0) as conversions_total')
            ->first();

        $published = ContentAsset::query()
            ->where('account_id', $account->id)
            ->tap(fn (Builder $query) => $this->scopeBrand($query, $brand))
            ->where('status', 'published')
            ->count();

        return $this->section('content_performance', 'Content performance', 'Content reach and publishing state from available content and GA4 snapshots.', [
            'published_assets' => $published,
            'sessions' => (int) ($totals?->sessions_total ?? 0),
            'pageviews' => (int) ($totals?->pageviews_total ?? 0),
            'conversions' => (int) ($totals?->conversions_total ?? 0),
        ]);
    }

    private function searchSection(Account $account, ?Brand $brand, mixed $start, mixed $end): array
    {
        $totals = SearchConsoleQuerySnapshot::query()
            ->where('account_id', $account->id)
            ->tap(fn (Builder $query) => $this->scopeBrand($query, $brand))
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('COALESCE(SUM(clicks), 0) as clicks_total')
            ->selectRaw('COALESCE(SUM(impressions), 0) as impressions_total')
            ->selectRaw('AVG(ctr) as ctr_average')
            ->selectRaw('AVG(position) as position_average')
            ->first();

        return $this->section('search_performance', 'Search performance', 'Search Console clicks, impressions, CTR and average position.', [
            'clicks' => (int) ($totals?->clicks_total ?? 0),
            'impressions' => (int) ($totals?->impressions_total ?? 0),
            'ctr' => $totals?->ctr_average !== null ? round((float) $totals->ctr_average, 4) : null,
            'position' => $totals?->position_average !== null ? round((float) $totals->position_average, 2) : null,
        ]);
    }

    private function socialSection(Account $account, ?Brand $brand, mixed $start, mixed $end): array
    {
        $base = SocialPost::query()
            ->where('account_id', $account->id)
            ->tap(fn (Builder $query) => $this->scopeBrand($query, $brand))
            ->whereBetween('created_at', [$start, $end]);

        return $this->section('social_distribution', 'Social distribution', 'Social publishing coverage and current workflow state.', [
            'posts' => (clone $base)->count(),
            'published' => (clone $base)->where('status', 'published')->count(),
            'scheduled' => (clone $base)->where('status', 'scheduled')->count(),
            'failed' => (clone $base)->where('status', 'failed')->count(),
        ]);
    }

    private function recommendationsSection(Account $account, ?Brand $brand): array
    {
        $base = Recommendation::query()
            ->where('account_id', $account->id)
            ->tap(fn (Builder $query) => $this->scopeBrandWithNull($query, $brand));

        return $this->section('recommendations', 'Recommendations', 'Open and completed recommendations from the intelligence system.', [
            'open' => (clone $base)->open()->count(),
            'accepted' => (clone $base)->where('status', 'accepted')->count(),
            'completed' => (clone $base)->where('status', 'completed')->count(),
            'top' => (clone $base)->open()->orderByDesc('impact_score')->limit(3)->pluck('title')->all(),
        ]);
    }

    private function risksSection(Account $account, ?Brand $brand): array
    {
        $signals = IntelligenceSignal::query()
            ->where('account_id', $account->id)
            ->tap(fn (Builder $query) => $this->scopeBrandWithNull($query, $brand))
            ->open()
            ->whereIn('priority', ['high', 'critical']);

        $performance = class_exists(PerformanceInsight::class)
            ? PerformanceInsight::query()->where('account_id', $account->id)->tap(fn (Builder $query) => $this->scopeBrand($query, $brand))->open()
            : null;

        return $this->section('risks', 'Risks', 'High-priority signals and unresolved performance risks.', [
            'critical_signals' => (clone $signals)->where('priority', 'critical')->count(),
            'high_signals' => (clone $signals)->where('priority', 'high')->count(),
            'performance_insights' => $performance ? (clone $performance)->count() : 0,
            'top' => (clone $signals)->latest('detected_at')->limit(3)->pluck('title')->all(),
        ]);
    }

    private function winsSection(Account $account, ?Brand $brand, mixed $start, mixed $end): array
    {
        return $this->section('wins', 'Wins', 'Completed work and positive movement captured during the reporting period.', [
            'published_content' => ContentAsset::query()->where('account_id', $account->id)->tap(fn (Builder $query) => $this->scopeBrand($query, $brand))->where('status', 'published')->whereBetween('published_at', [$start, $end])->count(),
            'published_social_posts' => SocialPost::query()->where('account_id', $account->id)->tap(fn (Builder $query) => $this->scopeBrand($query, $brand))->where('status', 'published')->whereBetween('published_at', [$start, $end])->count(),
            'completed_recommendations' => Recommendation::query()->where('account_id', $account->id)->tap(fn (Builder $query) => $this->scopeBrandWithNull($query, $brand))->where('status', 'completed')->count(),
        ]);
    }

    private function nextActionsSection(Account $account, ?Brand $brand): array
    {
        $actions = Recommendation::query()
            ->where('account_id', $account->id)
            ->tap(fn (Builder $query) => $this->scopeBrandWithNull($query, $brand))
            ->open()
            ->orderByDesc('impact_score')
            ->limit(5)
            ->get(['title', 'recommended_action', 'impact_score'])
            ->map(fn (Recommendation $recommendation) => [
                'title' => $recommendation->title,
                'action' => $recommendation->recommended_action,
                'impact_score' => $recommendation->impact_score,
            ])
            ->all();

        return $this->section('next_actions', 'Next actions', 'Highest-impact next actions ready for review or execution.', [
            'actions' => $actions,
        ]);
    }

    private function section(string $type, string $title, string $summary, array $payload): array
    {
        return [
            'section_type' => $type,
            'title' => $title,
            'summary' => $summary,
            'payload' => $payload,
        ];
    }

    private function renderHtml(Report $report): string
    {
        $sections = $report->sections->map(function (ReportSection $section): string {
            $items = collect($section->payload ?? [])->map(function (mixed $value, string $key): string {
                $rendered = is_array($value) ? implode(', ', array_map(fn ($item) => is_array($item) ? ($item['title'] ?? json_encode($item)) : (string) $item, $value)) : (string) ($value ?? 'n/a');

                return '<li><strong>'.e(str($key)->replace('_', ' ')->headline()).':</strong> '.e($rendered).'</li>';
            })->implode('');

            return '<section><h2>'.e($section->title).'</h2><p>'.e((string) $section->summary).'</p><ul>'.$items.'</ul></section>';
        })->implode('');

        return '<article><h1>'.e($report->title).'</h1><p>'.e((string) $report->summary).'</p>'.$sections.'</article>';
    }

    private function title(string $type, ?Brand $brand): string
    {
        return str($type)->headline().' report'.($brand ? ' for '.$brand->name : '');
    }

    private function summary(array $sections): string
    {
        $risks = collect($sections)->firstWhere('section_type', 'risks')['payload'] ?? [];
        $wins = collect($sections)->firstWhere('section_type', 'wins')['payload'] ?? [];

        return 'Static executive report generated from current tenant metrics: '
            .(int) ($risks['critical_signals'] ?? 0).' critical risks and '
            .(int) ($wins['completed_recommendations'] ?? 0).' completed recommendations.';
    }

    private function periodFor(string $type): array
    {
        $end = now();
        $start = match ($type) {
            'weekly' => now()->subDays(6)->startOfDay(),
            'monthly' => now()->subDays(29)->startOfDay(),
            default => now()->subDays(29)->startOfDay(),
        };

        return ['start' => $start, 'end' => $end];
    }

    private function scopeBrand(Builder $query, ?Brand $brand): void
    {
        $brand !== null
            ? $query->where('brand_id', $brand->id)
            : $query->whereNull('brand_id');
    }

    private function scopeBrandWithNull(Builder $query, ?Brand $brand): void
    {
        $brand !== null
            ? $query->where(fn (Builder $scope) => $scope->whereNull('brand_id')->orWhere('brand_id', $brand->id))
            : $query->whereNull('brand_id');
    }
}
