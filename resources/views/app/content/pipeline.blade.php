@extends('layouts.app', ['title' => $title ?? 'Content Pipeline', 'pageWidth' => 'wide'])

@section('content')
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Content Workspace</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-textPrimary">Content Pipeline</h1>
            <p class="mt-1 max-w-2xl text-sm text-textSecondary">Move ideas through production, review, readiness, publishing, and results without switching tools.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('app.content.create') }}" class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                <i data-lucide="plus" class="h-4 w-4"></i>
                New content
            </a>
            <a href="{{ route('app.content.calendar') }}" class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                <i data-lucide="calendar-days" class="h-4 w-4"></i>
                Calendar
            </a>
        </div>
    </div>

    <div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ($lanes as $key => $label)
            <a href="{{ route('app.content.pipeline.index', ['lane' => $key]) }}" class="rounded-lg border border-border bg-surface p-4 transition hover:border-primary/40 {{ $selectedLane === $key ? 'ring-2 ring-primary/25' : '' }}">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-medium text-textSecondary">{{ $label }}</p>
                    <span class="rounded-full border border-border bg-background px-2 py-0.5 text-xs font-semibold text-textSecondary">{{ (int) data_get($summary, $key, 0) }}</span>
                </div>
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-surfaceMuted">
                    <div class="h-full rounded-full bg-primary" style="width: {{ match($key) { 'ideas' => 10, 'in_progress' => 40, 'review' => 65, 'ready' => 85, 'published' => 100, default => 20 } }}%"></div>
                </div>
            </a>
        @endforeach
    </div>

    @if ($selectedLane !== '')
        <div class="mb-4">
            <a href="{{ route('app.content.pipeline.index') }}" class="inline-flex items-center gap-2 text-sm font-medium text-textSecondary hover:text-textPrimary">
                <i data-lucide="x" class="h-4 w-4"></i>
                Show all stages
            </a>
        </div>
    @endif

    @if ($selectedLane === 'published')
        <section class="rounded-lg border border-border bg-surface p-5" aria-label="Published">
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">Published archive</h2>
                    <p class="mt-1 text-sm text-textSecondary">All published content lives here as a compact list, instead of stretching the workflow board.</p>
                </div>
                <a href="{{ route('app.content.index') }}" class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-background px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                    Content library
                    <i data-lucide="arrow-right" class="h-4 w-4"></i>
                </a>
            </div>

            <div class="divide-y divide-border rounded-md border border-border bg-background">
                @forelse (($cardsByLane['published'] ?? collect()) as $card)
                    <article class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <a href="{{ data_get($card, 'url') }}" class="block truncate text-sm font-semibold text-textPrimary hover:underline">{{ data_get($card, 'title') }}</a>
                            <p class="mt-1 truncate text-xs text-textSecondary">{{ data_get($card, 'site') ?: 'No site selected' }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="rounded-full border border-border bg-surface px-2 py-0.5 text-[11px] font-semibold text-textSecondary">{{ data_get($card, 'status') }}</span>
                            <a href="{{ data_get($card, 'url') }}" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border bg-surface text-textPrimary hover:bg-surfaceMuted" aria-label="Open {{ data_get($card, 'title') }}">
                                <i data-lucide="arrow-right" class="h-3.5 w-3.5"></i>
                            </a>
                        </div>
                    </article>
                @empty
                    <div class="p-5 text-sm text-textSecondary">No published content yet.</div>
                @endforelse
            </div>
        </section>
    @else
        <section aria-label="Active content workflow">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">Active work</h2>
                    <p class="mt-1 text-sm text-textSecondary">Only the stages that still need movement are shown as columns.</p>
                </div>
                @if ((int) data_get($summary, 'published', 0) > 0)
                    <a href="{{ route('app.content.pipeline.index', ['lane' => 'published']) }}" class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                        <i data-lucide="archive" class="h-4 w-4"></i>
                        {{ (int) data_get($summary, 'published', 0) }} published
                    </a>
                @endif
            </div>

            <div class="grid gap-4 xl:grid-cols-4">
                @foreach ($activeLaneKeys as $key)
                    @php($label = $lanes[$key])
                    @if ($selectedLane === '' || $selectedLane === $key)
                        <section class="min-w-0 rounded-lg border border-border bg-surface p-4" aria-label="{{ $label }}">
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <h3 class="text-base font-semibold text-textPrimary">{{ $label }}</h3>
                                <span class="text-xs font-medium text-textSecondary">{{ (int) data_get($summary, $key, 0) }}</span>
                            </div>

                            <div class="space-y-3">
                                @forelse (($cardsByLane[$key] ?? collect()) as $card)
                                    <article class="rounded-md border border-border bg-background p-3">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <a href="{{ data_get($card, 'url') }}" class="block truncate text-sm font-semibold text-textPrimary hover:underline">{{ data_get($card, 'title') }}</a>
                                                <p class="mt-1 truncate text-xs text-textSecondary">{{ data_get($card, 'site') ?: 'No site selected' }}</p>
                                            </div>
                                            <span class="shrink-0 rounded-full border border-border bg-surface px-2 py-0.5 text-[11px] font-semibold text-textSecondary">{{ data_get($card, 'status') }}</span>
                                        </div>

                                        <div class="mt-3">
                                            <div class="flex items-center justify-between text-[11px] text-textSecondary">
                                                <span>Progress</span>
                                                <span>{{ (int) data_get($card, 'progress', 0) }}%</span>
                                            </div>
                                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-surfaceMuted">
                                                <div class="h-full rounded-full bg-primary" style="width: {{ max(0, min(100, (int) data_get($card, 'progress', 0))) }}%"></div>
                                            </div>
                                        </div>

                                        <p class="mt-3 text-xs leading-5 text-textSecondary">{{ data_get($card, 'next_step') }}</p>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <a href="{{ data_get($card, 'url') }}" class="inline-flex h-8 items-center gap-1 rounded-md border border-border bg-surface px-2 text-xs font-medium text-textPrimary hover:bg-surfaceMuted">
                                                Open <i data-lucide="arrow-right" class="h-3.5 w-3.5"></i>
                                            </a>
                                        </div>
                                    </article>
                                @empty
                                    <div class="rounded-md border border-dashed border-border bg-background p-4 text-sm text-textSecondary">
                                        No content in {{ strtolower($label) }}.
                                    </div>
                                @endforelse
                            </div>
                        </section>
                    @endif
                @endforeach
            </div>
        </section>

        @if ($selectedLane === '')
            <section class="mt-6 rounded-lg border border-border bg-surface p-5" aria-label="Recently published">
                <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-textPrimary">Recently published</h2>
                        <p class="mt-1 text-sm text-textSecondary">A short confirmation list; the full history stays in the library.</p>
                    </div>
                    <a href="{{ route('app.content.pipeline.index', ['lane' => 'published']) }}" class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-background px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                        View archive
                        <i data-lucide="arrow-right" class="h-4 w-4"></i>
                    </a>
                </div>

                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    @forelse ($recentPublishedCards as $card)
                        <article class="rounded-md border border-border bg-background p-3">
                            <a href="{{ data_get($card, 'url') }}" class="block truncate text-sm font-semibold text-textPrimary hover:underline">{{ data_get($card, 'title') }}</a>
                            <p class="mt-1 truncate text-xs text-textSecondary">{{ data_get($card, 'site') ?: 'No site selected' }}</p>
                        </article>
                    @empty
                        <div class="rounded-md border border-dashed border-border bg-background p-4 text-sm text-textSecondary md:col-span-2 xl:col-span-4">
                            Nothing has been published yet.
                        </div>
                    @endforelse
                </div>

                @if ($publishedOverflowCount > 0)
                    <p class="mt-3 text-xs text-textSecondary">+{{ $publishedOverflowCount }} older published items in the archive.</p>
                @endif
            </section>
        @endif
    @endif

    <section class="mt-6 rounded-lg border border-border bg-surface p-5">
        <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Advanced</p>
        <div class="mt-3 flex flex-wrap gap-2">
            <a href="{{ route('app.content.index') }}" class="inline-flex h-9 items-center rounded-md border border-border bg-background px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">Content library</a>
            <a href="{{ route('app.content.lifecycle.index') }}" class="inline-flex h-9 items-center rounded-md border border-border bg-background px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">Lifecycle view</a>
            <a href="{{ route('app.drafts') }}" class="inline-flex h-9 items-center rounded-md border border-border bg-background px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">Draft list</a>
        </div>
    </section>
@endsection
