<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\CampaignItem;
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
use Illuminate\Support\Str;
use InvalidArgumentException;
use ZipArchive;

class ExecutiveReportService
{
    /**
     * @return Collection<int, Report>
     */
    public function reportsForTenant(Account $account, ?Brand $brand = null, int $limit = 20): Collection
    {
        return Report::query()
            ->forTenant($account, $brand)
            ->with(['brand', 'sections', 'latestSnapshot' => fn ($query) => $query->limit(1)])
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

    /**
     * @return array<string, mixed>
     */
    public function dashboard(Account $account, ?Brand $brand = null): array
    {
        $reports = $this->reportsForTenant($account, $brand, 12);
        $latest = $reports->first();

        return [
            'stats' => [
                'reports' => $reports->count(),
                'snapshots' => ReportSnapshot::query()->where('account_id', $account->id)->tap(fn (Builder $query) => $this->scopeBrandWithNull($query, $brand))->count(),
                'scheduled' => $reports->filter(fn (Report $report) => (bool) data_get($report->latestSnapshot->first()?->payload, 'schedule.enabled'))->count(),
                'kpis' => $latest?->sections->firstWhere('section_type', 'kpi_tracking')?->payload['kpis'] ?? [],
            ],
            'latest' => $latest,
            'reports' => $reports,
            'trends' => $this->trendPayload($account, $brand, now()->subDays(29)->startOfDay(), now()),
            'board' => $this->boardPayload($account, $brand),
        ];
    }

    public function generate(Account $account, ?Brand $brand, ?User $user = null, string $type = 'executive', array $options = []): Report
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
            'generated_by' => $user?->id,
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
                'schedule' => [
                    'enabled' => (bool) ($options['scheduled'] ?? false),
                    'frequency' => $options['frequency'] ?? null,
                ],
                'exports' => [
                    'pdf_route' => 'app.reports.export.pdf',
                    'powerpoint_route' => 'app.reports.export.powerpoint',
                ],
            ],
            'html' => $this->renderHtml($report),
            'generated_at' => $report->generated_at,
        ]);

        return $report->refresh()->load(['sections', 'latestSnapshot' => fn ($query) => $query->limit(1)]);
    }

    /**
     * @return Collection<int, Report>
     */
    public function generateScheduled(string $type = 'weekly', int $limit = 50): Collection
    {
        return Brand::query()
            ->with('account')
            ->where('status', 'active')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (Brand $brand) => $this->generate($brand->account, $brand, null, $type, [
                'scheduled' => true,
                'frequency' => $type,
            ]));
    }

    public function exportPdf(Report $report): string
    {
        $text = $this->plainText($report);
        $stream = "BT /F1 12 Tf 50 780 Td 14 TL\n";

        foreach (collect(preg_split('/\R/', wordwrap($text, 86, "\n", true)))->take(48) as $line) {
            $stream .= '('.$this->pdfEscape((string) $line).") Tj T*\n";
        }

        $stream .= 'ET';
        $objects = [
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj',
            '4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj',
            '5 0 obj << /Length '.strlen($stream)." >> stream\n{$stream}\nendstream endobj",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object."\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        return $pdf."trailer << /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    public function exportPowerPoint(Report $report): string
    {
        $path = tempnam(sys_get_temp_dir(), 'argusly-pptx-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', $this->pptContentTypes());
        $zip->addFromString('_rels/.rels', $this->pptRootRels());
        $zip->addFromString('ppt/presentation.xml', $this->pptPresentation($report));
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $this->pptPresentationRels());
        $zip->addFromString('ppt/slides/slide1.xml', $this->pptSlide($report));
        $zip->addFromString('ppt/slides/_rels/slide1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        $zip->close();

        $contents = file_get_contents($path) ?: '';
        @unlink($path);

        return $contents;
    }

    /**
     * @return array<int, array{section_type: string, title: string, summary: string, payload: array<string, mixed>}>
     */
    private function sections(Account $account, ?Brand $brand, mixed $start, mixed $end): array
    {
        return [
            $this->executiveSummarySection($account, $brand, $start, $end),
            $this->kpiSection($account, $brand, $start, $end),
            $this->visibilitySection($account, $brand, $start, $end),
            $this->contentSection($account, $brand, $start, $end),
            $this->searchSection($account, $brand, $start, $end),
            $this->socialSection($account, $brand, $start, $end),
            $this->recommendationsSection($account, $brand),
            $this->risksSection($account, $brand),
            $this->winsSection($account, $brand, $start, $end),
            $this->nextActionsSection($account, $brand),
            $this->boardSummarySection($account, $brand),
            $this->trendReportSection($account, $brand, $start, $end),
        ];
    }

    private function executiveSummarySection(Account $account, ?Brand $brand, mixed $start, mixed $end): array
    {
        $trends = $this->trendPayload($account, $brand, $start, $end);
        $board = $this->boardPayload($account, $brand);

        return $this->section('executive_summary', 'Executive summary', 'Board-ready summary of market visibility, execution and operational risk.', [
            'headline' => $this->executiveHeadline($trends),
            'period' => $start->toDateString().' - '.$end->toDateString(),
            'board_focus' => $board['focus'],
            'risk_level' => $board['risk_level'],
        ]);
    }

    private function kpiSection(Account $account, ?Brand $brand, mixed $start, mixed $end): array
    {
        $visibility = VisibilitySnapshot::query()->where('account_id', $account->id)->tap(fn (Builder $query) => $this->scopeBrand($query, $brand))->whereBetween('captured_at', [$start, $end]);
        $search = SearchConsoleQuerySnapshot::query()->where('account_id', $account->id)->tap(fn (Builder $query) => $this->scopeBrand($query, $brand))->whereBetween('date', [$start->toDateString(), $end->toDateString()]);
        $traffic = Ga4MetricSnapshot::query()->where('account_id', $account->id)->tap(fn (Builder $query) => $this->scopeBrand($query, $brand))->whereBetween('date', [$start->toDateString(), $end->toDateString()]);

        return $this->section('kpi_tracking', 'KPI tracking', 'Primary executive KPIs tracked for the selected workspace and brand.', [
            'kpis' => [
                ['label' => 'AI visibility score', 'value' => round((float) ((clone $visibility)->avg('score') ?? 0), 1), 'target' => 75],
                ['label' => 'Search clicks', 'value' => (int) (clone $search)->sum('clicks'), 'target' => 500],
                ['label' => 'Content sessions', 'value' => (int) (clone $traffic)->sum('sessions'), 'target' => 1000],
                ['label' => 'Open recommendations', 'value' => Recommendation::query()->where('account_id', $account->id)->tap(fn (Builder $query) => $this->scopeBrandWithNull($query, $brand))->open()->count(), 'target' => 10],
            ],
        ]);
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

    private function boardSummarySection(Account $account, ?Brand $brand): array
    {
        return $this->section('board_summary', 'Board summary', 'Board-level operating summary across campaigns, execution and risk.', $this->boardPayload($account, $brand));
    }

    private function trendReportSection(Account $account, ?Brand $brand, mixed $start, mixed $end): array
    {
        return $this->section('trend_report', 'Trend report', 'Period-over-period directional trends for visibility, content, search and social.', $this->trendPayload($account, $brand, $start, $end));
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

    private function boardPayload(Account $account, ?Brand $brand): array
    {
        $campaigns = Campaign::query()->where('account_id', $account->id)->tap(fn (Builder $query) => $this->scopeBrand($query, $brand));
        $items = CampaignItem::query()->where('account_id', $account->id)->tap(fn (Builder $query) => $this->scopeBrand($query, $brand));
        $risks = IntelligenceSignal::query()->where('account_id', $account->id)->tap(fn (Builder $query) => $this->scopeBrandWithNull($query, $brand))->open()->whereIn('priority', ['high', 'critical'])->count();

        return [
            'active_campaigns' => (clone $campaigns)->where('status', 'active')->count(),
            'open_board_items' => (clone $items)->whereNotIn('status', ['completed', 'archived'])->count(),
            'completed_board_items' => (clone $items)->where('status', 'completed')->count(),
            'risk_level' => $risks > 5 ? 'high' : ($risks > 0 ? 'medium' : 'low'),
            'focus' => (clone $campaigns)->where('status', 'active')->latest()->limit(3)->pluck('name')->all(),
        ];
    }

    private function trendPayload(Account $account, ?Brand $brand, mixed $start, mixed $end): array
    {
        $midpoint = $start->copy()->addSeconds((int) floor($start->diffInSeconds($end) / 2));

        return [
            'visibility_delta' => $this->delta(
                VisibilitySnapshot::query()->where('account_id', $account->id)->tap(fn (Builder $query) => $this->scopeBrand($query, $brand)),
                'score',
                'captured_at',
                $start,
                $midpoint,
                $end,
                true,
            ),
            'search_click_delta' => $this->delta(
                SearchConsoleQuerySnapshot::query()->where('account_id', $account->id)->tap(fn (Builder $query) => $this->scopeBrand($query, $brand)),
                'clicks',
                'date',
                $start->toDateString(),
                $midpoint->toDateString(),
                $end->toDateString(),
            ),
            'session_delta' => $this->delta(
                Ga4MetricSnapshot::query()->where('account_id', $account->id)->tap(fn (Builder $query) => $this->scopeBrand($query, $brand)),
                'sessions',
                'date',
                $start->toDateString(),
                $midpoint->toDateString(),
                $end->toDateString(),
            ),
            'social_published_delta' => $this->delta(
                SocialPost::query()->where('account_id', $account->id)->tap(fn (Builder $query) => $this->scopeBrand($query, $brand))->where('status', 'published'),
                'id',
                'published_at',
                $start,
                $midpoint,
                $end,
            ),
        ];
    }

    private function delta(Builder $query, string $column, string $dateColumn, mixed $start, mixed $midpoint, mixed $end, bool $average = false): array
    {
        $previous = (clone $query)->whereBetween($dateColumn, [$start, $midpoint]);
        $current = (clone $query)->whereBetween($dateColumn, [$midpoint, $end]);
        $previousValue = $average ? (float) ($previous->avg($column) ?? 0) : (float) ($column === 'id' ? $previous->count() : $previous->sum($column));
        $currentValue = $average ? (float) ($current->avg($column) ?? 0) : (float) ($column === 'id' ? $current->count() : $current->sum($column));

        return [
            'previous' => round($previousValue, 2),
            'current' => round($currentValue, 2),
            'change' => round($currentValue - $previousValue, 2),
        ];
    }

    private function executiveHeadline(array $trends): string
    {
        $visibility = (float) ($trends['visibility_delta']['change'] ?? 0);

        if ($visibility > 0) {
            return 'AI visibility improved while operating metrics remain under review.';
        }

        if ($visibility < 0) {
            return 'AI visibility declined and should be reviewed with current recommendations.';
        }

        return 'Executive KPIs are stable with current data coverage.';
    }

    private function plainText(Report $report): string
    {
        return collect([
            $report->title,
            (string) $report->summary,
            ...$report->sections->map(fn (ReportSection $section) => $section->title.': '.$section->summary)->all(),
        ])->implode("\n\n");
    }

    private function pdfEscape(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $value);
    }

    private function pptContentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/><Override PartName="/ppt/slides/slide1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/></Types>';
    }

    private function pptRootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/></Relationships>';
    }

    private function pptPresentation(Report $report): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><p:sldIdLst><p:sldId id="256" r:id="rId1"/></p:sldIdLst><p:sldSz cx="12192000" cy="6858000" type="wide"/><p:notesSz cx="6858000" cy="9144000"/></p:presentation>';
    }

    private function pptPresentationRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide1.xml"/></Relationships>';
    }

    private function pptSlide(Report $report): string
    {
        $body = e(Str::limit($this->plainText($report), 900));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/><p:sp><p:nvSpPr><p:cNvPr id="2" name="Executive report"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr><p:spPr><a:xfrm><a:off x="600000" y="500000"/><a:ext cx="11000000" cy="5600000"/></a:xfrm></p:spPr><p:txBody><a:bodyPr wrap="square"/><a:lstStyle/><a:p><a:r><a:rPr lang="en-US" sz="2200"/><a:t>'.e($report->title).'</a:t></a:r></a:p><a:p><a:r><a:rPr lang="en-US" sz="1200"/><a:t>'.$body.'</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
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
