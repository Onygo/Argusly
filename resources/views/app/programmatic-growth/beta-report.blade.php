@extends('layouts.app', ['title' => 'Programmatic Growth Beta Report'])

@section('content')
    @php
        $formatMinutes = function ($minutes) {
            if ($minutes === null) {
                return 'Pending';
            }

            if ((int) $minutes < 60) {
                return (int) $minutes.'m';
            }

            return floor(((int) $minutes) / 60).'h '.(((int) $minutes) % 60).'m';
        };
    @endphp

    <div class="space-y-6">
        @include('app.programmatic-growth._beta-banner')

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Internal beta</p>
                <h1 class="mt-1 text-2xl font-semibold tracking-tight text-textPrimary">Programmatic Growth Beta Report</h1>
                <p class="mt-2 max-w-3xl text-sm text-textSecondary">Product validation signals for internal testers. This report tracks clarity, speed to value and blockers without relying on live publishing.</p>
            </div>
            <form method="POST" action="{{ route('app.programmatic-growth.internal-beta-mode') }}">
                @csrf
                <input type="hidden" name="enabled" value="{{ $internalBetaMode ? 0 : 1 }}">
                <button class="rounded-md border border-border bg-background px-3 py-2 text-xs font-medium text-textPrimary">
                    {{ $internalBetaMode ? 'Disable tester mode' : 'Enable tester mode' }}
                </button>
            </form>
        </div>

        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Active Growth Programs</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ (int) ($report['active_growth_programs'] ?? 0) }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Average Success Score</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((float) ($report['average_success_score'] ?? 0), 1) }}%</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Feedback Responses</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ (int) data_get($report, 'feedback.total', 0) }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Clear Steps</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ (int) data_get($report, 'feedback.yes', 0) }}</p>
            </div>
        </div>

        <section class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-sm font-semibold text-textPrimary">Average Time To Value</h2>
            <div class="mt-4 grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                @foreach ([
                    'first_cluster_minutes' => 'First cluster',
                    'first_blueprint_minutes' => 'First blueprint',
                    'first_brief_minutes' => 'First brief',
                    'first_draft_minutes' => 'First draft',
                    'first_content_asset_minutes' => 'First content asset',
                    'first_scheduled_publication_record_minutes' => 'First scheduled publication record',
                ] as $key => $label)
                    <div class="rounded-md border border-border bg-background p-3">
                        <p class="text-xs text-textSecondary">{{ $label }}</p>
                        <p class="mt-1 text-lg font-semibold text-textPrimary">{{ $formatMinutes(data_get($report, 'average_time_to_value.'.$key)) }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Most Common Blockers</h2>
                <div class="mt-4 space-y-2">
                    @forelse (($report['top_blockers'] ?? []) as $item)
                        <div class="flex justify-between gap-3 rounded-md border border-border bg-background p-3 text-sm">
                            <span class="text-textSecondary">{{ str((string) $item['reason'])->replace('_', ' ')->headline() }}</span>
                            <span class="font-medium text-textPrimary">{{ (int) $item['count'] }}</span>
                        </div>
                    @empty
                        <p class="rounded-md border border-dashed border-border bg-background px-3 py-2 text-sm text-textMuted">No blockers tracked yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Most Common Conflicts</h2>
                <div class="mt-4 space-y-2">
                    @forelse (($report['top_conflicts'] ?? []) as $item)
                        <div class="flex justify-between gap-3 rounded-md border border-border bg-background p-3 text-sm">
                            <span class="text-textSecondary">{{ str((string) $item['reason'])->replace('_', ' ')->headline() }}</span>
                            <span class="font-medium text-textPrimary">{{ (int) $item['count'] }}</span>
                        </div>
                    @empty
                        <p class="rounded-md border border-dashed border-border bg-background px-3 py-2 text-sm text-textMuted">No conflicts tracked yet.</p>
                    @endforelse
                </div>
            </section>
        </div>

        <section class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-sm font-semibold text-textPrimary">Active Program Value Signals</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-border text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-textSecondary">
                            <th class="py-2 pr-4 font-medium">Growth Program</th>
                            <th class="py-2 pr-4 font-medium">Success</th>
                            <th class="py-2 pr-4 font-medium">First Cluster</th>
                            <th class="py-2 pr-4 font-medium">First Blueprint</th>
                            <th class="py-2 pr-4 font-medium">First Draft</th>
                            <th class="py-2 pr-4 font-medium">Content Assets</th>
                            <th class="py-2 pr-4 font-medium">Scheduled Records</th>
                            <th class="py-2 pr-4 font-medium">Feedback</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse (($report['programs'] ?? collect()) as $row)
                            @php($program = $row['program'])
                            @php($metrics = $row['metrics'])
                            <tr>
                                <td class="py-3 pr-4">
                                    <a href="{{ route('app.growth-programs.show', $program) }}" class="font-medium text-textPrimary hover:text-primary">{{ $program->name }}</a>
                                </td>
                                <td class="py-3 pr-4 text-textSecondary">{{ (int) data_get($metrics, 'success_score', 0) }}%</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $formatMinutes(data_get($metrics, 'time_to_value.first_cluster_minutes')) }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $formatMinutes(data_get($metrics, 'time_to_value.first_blueprint_minutes')) }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ $formatMinutes(data_get($metrics, 'time_to_value.first_draft_minutes')) }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ (int) data_get($metrics, 'product_metrics.content_created', 0) }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ (int) (($program->metrics ?? [])['scheduled_programmatic_publications_count'] ?? 0) }}</td>
                                <td class="py-3 pr-4 text-textSecondary">{{ (int) data_get($metrics, 'feedback.total', 0) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-4 text-sm text-textMuted">No active Growth Programs yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
