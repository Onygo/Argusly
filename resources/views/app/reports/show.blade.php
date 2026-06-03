<x-app.layout :title="$report->title.' | Argusly'">
    <div class="mx-auto max-w-5xl">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Report detail</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">{{ $report->title }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">{{ $report->summary }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.badge variant="blue">{{ str($report->type)->headline() }}</x-ui.badge>
                <x-ui.button href="{{ route('app.reports') }}" variant="secondary">All reports</x-ui.button>
            </div>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-3">
            <x-dashboard.info-card label="Period start" :value="$report->period_start?->toFormattedDateString() ?? 'n/a'" />
            <x-dashboard.info-card label="Period end" :value="$report->period_end?->toFormattedDateString() ?? 'n/a'" />
            <x-dashboard.info-card label="Sections" :value="$report->sections->count()" />
        </div>

        <div class="mt-6 space-y-4">
            @foreach ($report->sections as $section)
                <x-ui.card class="p-5">
                    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ str($section->section_type)->replace('_', ' ')->headline() }}</p>
                            <h2 class="mt-2 text-lg font-semibold text-ink">{{ $section->title }}</h2>
                            <p class="mt-1 text-sm leading-6 text-muted">{{ $section->summary }}</p>
                        </div>
                        <x-ui.badge>#{{ $section->position }}</x-ui.badge>
                    </div>

                    <dl class="mt-5 grid gap-3 sm:grid-cols-2">
                        @foreach (($section->payload ?? []) as $key => $value)
                            <div class="rounded-md border border-line bg-panel p-3">
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">{{ str($key)->replace('_', ' ')->headline() }}</dt>
                                <dd class="mt-1 text-sm font-semibold text-ink">
                                    @if (is_array($value))
                                        {{ collect($value)->map(fn ($item) => is_array($item) ? ($item['title'] ?? json_encode($item)) : $item)->filter()->implode(', ') ?: 'n/a' }}
                                    @elseif ($value === null)
                                        n/a
                                    @else
                                        {{ is_numeric($value) ? number_format((float) $value, is_float($value + 0) ? 2 : 0) : $value }}
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </x-ui.card>
            @endforeach
        </div>

        @php($snapshot = $report->latestSnapshot->first())
        @if ($snapshot)
            <x-ui.card class="mt-6 p-5">
                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                    <div>
                        <h2 class="text-lg font-semibold text-ink">HTML export</h2>
                        <p class="mt-1 text-sm text-muted">Static HTML snapshot generated with the report. PDF and email delivery are intentionally not enabled yet.</p>
                    </div>
                    <x-ui.badge>{{ $snapshot->generated_at?->toDayDateTimeString() }}</x-ui.badge>
                </div>
                <div class="mt-5 overflow-auto rounded-md border border-line bg-white p-4 text-sm leading-6 text-ink">
                    {!! $snapshot->html !!}
                </div>
            </x-ui.card>
        @endif
    </div>
</x-app.layout>
