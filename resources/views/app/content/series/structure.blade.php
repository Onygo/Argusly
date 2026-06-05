@extends('layouts.app', ['title' => 'Series Structure'])

@section('content')
    @php
        $isReadOnly = $isReadOnly ?? ($series->isLocked() || $series->isArchived());
        $currentPillarNumber = (int) ($pillarArticle?->article_number ?? 0);
        $suggestedRow = $articleRows->first(fn (array $row): bool => (int) $row['article_number'] === (int) $suggestedPillarArticleNumber);
    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Step 3: Structure</h1>
            <p class="mt-1 max-w-3xl text-textSecondary">Review the generated article stack, confirm the pillar article, and keep the remaining articles as supporting content before draft generation.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('app.content.series.show', $series) }}" class="rounded border border-border px-3 py-2 text-sm">Back to series</a>
            <form method="POST" action="{{ route('app.content.series.generate-articles', $series) }}">
                @csrf
                <button @disabled($isReadOnly) class="rounded border border-border px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50">Generate articles</button>
            </form>
        </div>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @foreach (['series_strategy', 'series_generation'] as $errorKey)
        @if ($errors->has($errorKey))
            <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first($errorKey) }}</div>
        @endif
    @endforeach

    <div class="mb-5 grid gap-4 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
        <section class="rounded border border-border bg-surface p-4 sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">Suggested pillar article</h2>
                    <p class="mt-1 text-sm text-textSecondary">Auto-detected from main topic match and scope breadth. You can override it if another article should be the hub.</p>
                </div>
                @if ($pillarArticle)
                    <span class="inline-flex items-center rounded border border-sky-300 px-2 py-0.5 text-xs text-sky-700">Current pillar</span>
                @elseif ($suggestedRow)
                    <span class="inline-flex items-center rounded border border-amber-300 px-2 py-0.5 text-xs text-amber-800">Suggested</span>
                @endif
            </div>

            @if ($suggestedRow)
                <div class="mt-4 rounded-lg border {{ $currentPillarNumber === (int) $suggestedRow['article_number'] ? 'border-sky-300 bg-sky-50/70' : 'border-border bg-background' }} p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded border border-border px-2 py-0.5 text-xs text-textSecondary">Article {{ (int) $suggestedRow['article_number'] }}</span>
                                <span class="inline-flex items-center rounded border px-2 py-0.5 text-xs {{ $currentPillarNumber === (int) $suggestedRow['article_number'] ? 'border-sky-300 text-sky-700' : 'border-amber-300 text-amber-800' }}">
                                    {{ $currentPillarNumber === (int) $suggestedRow['article_number'] ? 'Pillar' : 'Suggested pillar' }}
                                </span>
                            </div>
                            <h3 class="mt-3 text-base font-semibold text-textPrimary">{{ (string) $suggestedRow['title'] }}</h3>
                            <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="text-xs text-textSecondary">Primary keyword</dt>
                                    <dd class="mt-1 break-words text-textPrimary">{{ $suggestedRow['primary_keyword'] ?: '-' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-textSecondary">Links to</dt>
                                    <dd class="mt-1 break-words text-textPrimary">{{ implode(', ', (array) $suggestedRow['links_to']) ?: '-' }}</dd>
                                </div>
                            </dl>
                        </div>

                        @if (! $isReadOnly)
                            @if ($currentPillarNumber === (int) $suggestedRow['article_number'])
                                <form method="POST" action="{{ route('app.content.series.pillar.clear', $series) }}">
                                    @csrf
                                    <button class="rounded border border-border px-3 py-2 text-sm">Remove pillar</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('app.content.series.pillar.set', $series) }}">
                                    @csrf
                                    <input type="hidden" name="article_number" value="{{ (int) $suggestedRow['article_number'] }}">
                                    <button class="rounded border border-border px-3 py-2 text-sm">Make pillar</button>
                                </form>
                            @endif
                        @endif
                    </div>
                </div>
            @else
                <p class="mt-4 text-sm text-textSecondary">Generate a strategy first to get a structure suggestion.</p>
            @endif
        </section>

        <section class="rounded border border-border bg-surface p-4 sm:p-5">
            <h2 class="text-base font-semibold text-textPrimary">Structure summary</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Series</p>
                    <p class="mt-1 break-words text-sm text-textPrimary">{{ $series->name }}</p>
                </div>
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Main topic</p>
                    <p class="mt-1 break-words text-sm text-textPrimary">{{ $series->main_topic }}</p>
                </div>
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Pillar status</p>
                    <p class="mt-1 text-sm text-textPrimary">{{ $pillarArticle ? 'Selected' : 'Not set yet' }}</p>
                </div>
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Generated article ideas</p>
                    <p class="mt-1 text-sm text-textPrimary">{{ $articleRows->count() }}</p>
                </div>
            </div>

            @if ($articleRows->count() > 1 && ! $pillarArticle)
                <div class="mt-4 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    Choose one pillar article before generation so the supporting articles can cluster around a clear hub.
                </div>
            @endif
        </section>
    </div>

    <section class="rounded border border-border bg-surface p-4 sm:p-5">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-textPrimary">Supporting articles</h2>
                <p class="mt-1 text-sm text-textSecondary">Keep manual ordering as-is while making the role of each article explicit.</p>
            </div>
            <a href="{{ route('app.content.series.show', $series) }}" class="rounded border border-border px-3 py-2 text-sm">Open full series overview</a>
        </div>

        @if ($articleRows->isEmpty())
            <p class="text-sm text-textSecondary">No generated structure yet.</p>
        @else
            <div class="grid gap-3 lg:grid-cols-2">
                @foreach ($articleRows as $row)
                    <article class="rounded-lg border {{ $row['is_pillar'] ? 'border-sky-300 bg-sky-50/70' : (($row['is_suggested_pillar'] ?? false) ? 'border-amber-300 bg-amber-50/70' : 'border-border bg-background') }} p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded border border-border px-2 py-0.5 text-xs text-textSecondary">Article {{ (int) $row['article_number'] }}</span>
                                    <span class="inline-flex items-center rounded border px-2 py-0.5 text-xs {{ $row['is_pillar'] ? 'border-sky-300 text-sky-700' : 'border-border text-textSecondary' }}">
                                        {{ $row['role_label'] }}
                                    </span>
                                    @if (($row['is_suggested_pillar'] ?? false) && ! $row['is_pillar'])
                                        <span class="inline-flex items-center rounded border border-amber-300 px-2 py-0.5 text-xs text-amber-800">Suggested pillar</span>
                                    @endif
                                </div>
                                <h3 class="mt-3 text-base font-semibold text-textPrimary">{{ (string) $row['title'] }}</h3>
                            </div>

                            @if (! $isReadOnly)
                                @if ($row['is_pillar'])
                                    <form method="POST" action="{{ route('app.content.series.pillar.clear', $series) }}">
                                        @csrf
                                        <button class="rounded border border-border px-3 py-2 text-sm">Remove pillar</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('app.content.series.pillar.set', $series) }}">
                                        @csrf
                                        <input type="hidden" name="article_number" value="{{ (int) $row['article_number'] }}">
                                        <button class="rounded border border-border px-3 py-2 text-sm">Make pillar</button>
                                    </form>
                                @endif
                            @endif
                        </div>

                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-xs text-textSecondary">Primary keyword</dt>
                                <dd class="mt-1 break-words text-textPrimary">{{ $row['primary_keyword'] ?: '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-textSecondary">Links to</dt>
                                <dd class="mt-1 break-words text-textPrimary">{{ implode(', ', (array) $row['links_to']) ?: '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-textSecondary">Draft status</dt>
                                <dd class="mt-1 text-textPrimary">{{ (string) $row['status'] }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-textSecondary">Publish status</dt>
                                <dd class="mt-1 text-textPrimary">{{ (string) $row['publish_status'] }}</dd>
                            </div>
                        </dl>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
