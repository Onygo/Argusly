@extends('layouts.app', ['title' => 'Content Series'])

@section('content')
    @php
        $statusKey = $series->normalizedStatus();
        $isPublished = $statusKey === \App\Models\ContentSeries::STATUS_PUBLISHED;
        $isReadOnly = $isReadOnly ?? ($series->isLocked() || $series->isArchived());
        $badgeMap = [
            'draft' => ['Draft', 'border-border text-textSecondary'],
            'strategy_generated' => ['Draft', 'border-border text-textSecondary'],
            'generating' => ['Generating', 'border-amber-300 text-amber-700'],
            'ready' => ['Ready', 'border-emerald-300 text-emerald-700'],
            'scheduled' => ['Scheduled', 'border-sky-300 text-sky-700'],
            'published' => ['Published', 'border-slate-400 text-slate-700'],
            'archived' => ['Archived', 'border-border text-textSecondary'],
            'strategy_ready' => ['Draft', 'border-border text-textSecondary'],
            'generated' => ['Ready', 'border-emerald-300 text-emerald-700'],
            'publishing' => ['Scheduled', 'border-sky-300 text-sky-700'],
        ];
        $badge = $badgeMap[$statusKey] ?? [ucfirst($statusKey), 'border-border text-textSecondary'];
        $publishHistory = collect((array) data_get($series->publish_plan_json, 'publish_history', []))
            ->filter(fn($row) => is_array($row))
            ->values();
        $generationStatus = (string) ($generationRun->status ?? 'idle');
        $generationBadgeMap = [
            'idle' => ['Idle', 'border-border text-textSecondary'],
            'pending' => ['Pending', 'border-amber-300 text-amber-700'],
            'running' => ['Running', 'border-amber-300 text-amber-700'],
            'completed' => ['Completed', 'border-emerald-300 text-emerald-700'],
            'failed' => ['Failed', 'border-rose-300 text-rose-700'],
        ];
        $generationBadge = $generationBadgeMap[$generationStatus] ?? [ucfirst($generationStatus), 'border-border text-textSecondary'];
        $translationLanguages = collect($translationLanguages ?? \App\Enums\SupportedLanguage::cases());
    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-semibold tracking-tight text-textPrimary">
                {{ $series->name }}
                @if ($isReadOnly)
                    <i data-lucide="lock" class="h-5 w-5 text-textSecondary"></i>
                @endif
            </h1>
            <p class="mt-1 text-textSecondary">
                {{ $series->main_topic }} ·
                <span class="inline-flex items-center rounded border px-2 py-0.5 text-xs {{ $badge[1] }}">{{ $badge[0] }}</span>
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('app.content.series.index') }}" class="rounded border border-border px-3 py-2 text-sm">Back to series</a>
            <a href="{{ route('app.content.index') }}" class="rounded border border-border px-3 py-2 text-sm">Content list</a>
            <form method="POST" action="{{ route('app.content.series.duplicate', $series) }}">
                @csrf
                <button class="rounded border border-border px-3 py-2 text-sm">Create new series based on this</button>
            </form>
            @if ($isPublished)
                <form method="POST" action="{{ route('app.content.series.archive', $series) }}">
                    @csrf
                    <button class="rounded border border-border px-3 py-2 text-sm">Archive</button>
                </form>
            @endif
        </div>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($isReadOnly)
        <div class="mb-4 rounded border border-border bg-surfaceMuted px-4 py-3 text-sm text-textPrimary">
            @if (($progress['unpublished'] ?? 0) > 0)
                This series structure is locked, but remaining draft locale articles can still be published.
            @else
                This series has been published and is locked.
            @endif
        </div>
    @endif

    @foreach (['series_strategy', 'series_generation', 'series_publish', 'series_translation'] as $errorKey)
        @if ($errors->has($errorKey))
            <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first($errorKey) }}</div>
        @endif
    @endforeach

    <div class="mb-4 grid gap-3 md:grid-cols-4">
        <div class="rounded border border-border bg-surface p-3">
            <p class="text-xs text-textSecondary">Step 2 Strategy</p>
            <p class="mt-1 text-sm text-textPrimary">{{ $strategyArticles->count() }} planned article(s)</p>
        </div>
        <div class="rounded border border-border bg-surface p-3">
            <p class="text-xs text-textSecondary">Step 3 Structure</p>
            <p class="mt-1 text-sm text-textPrimary">{{ $pillarArticle ? 'Pillar selected' : 'Needs review' }}</p>
            <p class="mt-1 text-xs text-textSecondary">Confirm the hub article before generation.</p>
        </div>
        <div class="rounded border border-border bg-surface p-3">
            <p class="text-xs text-textSecondary">Step 4 Generation</p>
            <p class="mt-1 text-sm text-textPrimary">{{ $progress['generated'] }} generated article(s)</p>
            <p class="mt-1 text-xs text-textSecondary">Status: {{ $generationBadge[0] }}</p>
        </div>
        <div class="rounded border border-border bg-surface p-3">
            <p class="text-xs text-textSecondary">Step 5 Publishing</p>
            <p class="mt-1 text-sm text-textPrimary">{{ $progress['published'] }} / {{ $progress['locales'] }} locale article(s) published</p>
            @if (($progress['translated'] ?? 0) > 0)
                <p class="mt-1 text-xs text-textSecondary">{{ $progress['translated'] }} translation(s) exist, {{ $progress['unpublished'] }} locale article(s) still draft.</p>
            @endif
        </div>
    </div>

    <div class="space-y-4">
        <section class="rounded border border-border bg-surface p-4 {{ $isPublished ? 'border-slate-300' : '' }}">
            <div class="mb-3">
                <h2 class="text-base font-semibold text-textPrimary">Series Overview</h2>
                <p class="text-sm text-textSecondary">Archive view for strategy angle, article chain, and publish lifecycle.</p>
            </div>
            <div class="grid gap-3 text-sm md:grid-cols-2">
                <div>
                    <p class="text-xs text-textSecondary">Main topic</p>
                    <p class="text-textPrimary">{{ $series->main_topic }}</p>
                </div>
                <div>
                    <p class="text-xs text-textSecondary">Primary keyword</p>
                    <p class="text-textPrimary">{{ $series->primary_keyword }}</p>
                </div>
                <div>
                    <p class="text-xs text-textSecondary">Audience</p>
                    <p class="text-textPrimary">{{ $series->audience ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-xs text-textSecondary">Tone</p>
                    <p class="text-textPrimary">{{ $series->tone ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-xs text-textSecondary">Funnel stage</p>
                    <p class="text-textPrimary">{{ $series->funnel_stage ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-xs text-textSecondary">Supporting keywords</p>
                    <p class="text-textPrimary">{{ implode(', ', (array) $series->supporting_keywords) ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-xs text-textSecondary">Content intent</p>
                    <p class="text-textPrimary">{{ implode(', ', (array) $series->intent_keys) ?: '-' }}</p>
                </div>
                <div>
                    <p class="text-xs text-textSecondary">Pillar article</p>
                    <p class="text-textPrimary">{{ $pillarArticle?->title ?: $pillarArticle?->content?->title ?: 'Not set yet' }}</p>
                </div>
            </div>
        </section>

        <section class="rounded border border-border bg-surface p-4">
            <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">Strategy & Internal Linking Structure</h2>
                    <p class="text-sm text-textSecondary">Strategy angle and link chain across planned articles.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($strategyArticles->isNotEmpty())
                        <a href="{{ route('app.content.series.structure', $series) }}" class="rounded border border-border px-3 py-2 text-sm">Review structure</a>
                    @endif
                    <form method="POST" action="{{ route('app.content.series.generate-strategy', $series) }}">
                        @csrf
                        <button @disabled($isReadOnly) class="rounded border border-border px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50">Generate strategy</button>
                    </form>
                </div>
            </div>

            @if ($strategyArticles->isEmpty())
                <p class="text-sm text-textSecondary">No strategy yet.</p>
            @else
                <div class="mb-3 rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Angle</p>
                    <p class="mt-1 text-sm text-textPrimary">{{ data_get($series->strategy_json, 'angle', '-') }}</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                        <tr class="text-left text-textSecondary">
                            <th class="pb-2 font-medium">#</th>
                            <th class="pb-2 font-medium">Role</th>
                            <th class="pb-2 font-medium">Title</th>
                            <th class="pb-2 font-medium">Primary keyword</th>
                            <th class="pb-2 font-medium">Links to</th>
                            <th class="pb-2 font-medium">Action</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                        @foreach ($strategyArticles as $article)
                            @php
                                $articleNumber = (int) data_get($article, 'article_number', $loop->iteration);
                                $isPillar = (bool) data_get($article, 'is_pillar', false);
                            @endphp
                            <tr>
                                <td class="py-2">{{ $articleNumber }}</td>
                                <td class="py-2">
                                    <span class="inline-flex items-center rounded border px-2 py-0.5 text-xs {{ $isPillar ? 'border-sky-300 text-sky-700' : 'border-border text-textSecondary' }}">
                                        {{ $isPillar ? 'Pillar' : 'Supporting' }}
                                    </span>
                                </td>
                                <td class="py-2 text-textPrimary">{{ data_get($article, 'title', '-') }}</td>
                                <td class="py-2 text-textSecondary">{{ data_get($article, 'primary_keyword', '-') }}</td>
                                <td class="py-2 text-textSecondary">
                                    {{ implode(', ', (array) data_get($article, 'internal_links_to', [])) ?: '-' }}
                                </td>
                                <td class="py-2">
                                    <a href="{{ route('app.content.series.structure', $series) }}" class="text-link hover:text-linkHover underline">Review in structure</a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="rounded border border-border bg-surface p-4">
            <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">Generated Articles</h2>
                    <p class="text-sm text-textSecondary">Generated article set with linked cluster references and publish state.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('app.content.series.structure', $series) }}" class="rounded border border-border px-3 py-2 text-sm">Review structure</a>
                    @if ($progress['generated'] > 0)
                        <form method="POST" action="{{ route('app.content.series.translate', $series) }}" class="flex flex-wrap items-center gap-2">
                            @csrf
                            <select name="target_locale" class="rounded border border-border bg-background px-2 py-2 text-sm">
                                @foreach ($translationLanguages as $language)
                                    <option value="{{ $language->value }}" @selected($language->value === 'nl')>
                                        {{ $language->englishLabel() }}
                                    </option>
                                @endforeach
                            </select>
                            <button class="rounded border border-border px-3 py-2 text-sm">Translate series</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('app.content.series.generate-articles', $series) }}">
                        @csrf
                        <button @disabled($isReadOnly) class="rounded border border-border px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50">Generate articles</button>
                    </form>
                    @if ($articleRows->where('status', 'failed')->isNotEmpty())
                        <form method="POST" action="{{ route('app.content.series.generate-articles', $series) }}">
                            @csrf
                            @foreach ($articleRows->where('status', 'failed') as $failedRow)
                                <input type="hidden" name="article_numbers[]" value="{{ (int) $failedRow['article_number'] }}">
                            @endforeach
                            <button @disabled($isReadOnly) class="rounded border border-border px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50">Retry failed</button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="rounded border border-border bg-background p-3 text-sm">
                <p class="text-textSecondary">Generated: {{ $progress['generated'] }} / {{ $progress['planned'] }}</p>
                @if (($progress['translated'] ?? 0) > 0)
                    <p class="mt-1 text-textSecondary">Translations: {{ $progress['translated'] }} existing · {{ $progress['unpublished'] }} locale article(s) not published</p>
                @endif
                <p class="mt-1 text-textSecondary">Run status: <span class="inline-flex items-center rounded border px-2 py-0.5 text-xs {{ $generationBadge[1] }}">{{ $generationBadge[0] }}</span></p>
                @if ($generationRun)
                    <p class="mt-1 text-xs text-textSecondary">
                        Completed: {{ (int) $generationRun->completed_articles }} · Failed: {{ (int) $generationRun->failed_articles }}
                    </p>
                @endif
                @if ($isGenerationRunning)
                    <p class="mt-2 text-xs text-amber-700">Generation is in progress. This page refreshes every 6 seconds.</p>
                @elseif ($generationStatus === 'failed')
                    <p class="mt-2 text-xs text-rose-700">{{ (string) ($generationRun?->last_error ?: 'One or more articles failed. Retry failed rows.') }}</p>
                @endif
            </div>

            @if ($articleRows->isNotEmpty())
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                        <tr class="text-left text-textSecondary">
                            <th class="pb-2 font-medium">#</th>
                            <th class="pb-2 font-medium">Role</th>
                            <th class="pb-2 font-medium">Title</th>
                            <th class="pb-2 font-medium">Status</th>
                            <th class="pb-2 font-medium">Publish</th>
                            <th class="pb-2 font-medium">Locales</th>
                            <th class="pb-2 font-medium">Links to</th>
                            <th class="pb-2 font-medium">Publishing date</th>
                            <th class="pb-2 font-medium">Action</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                        @foreach ($articleRows as $row)
                            <tr>
                                <td class="py-2">{{ (int) $row['article_number'] }}</td>
                                <td class="py-2">
                                    <span class="inline-flex items-center rounded border px-2 py-0.5 text-xs {{ $row['is_pillar'] ? 'border-sky-300 text-sky-700' : 'border-border text-textSecondary' }}">
                                        {{ $row['role_label'] }}
                                    </span>
                                </td>
                                <td class="py-2 text-textPrimary">{{ (string) $row['title'] }}</td>
                                <td class="py-2"><span class="pl-badge">{{ (string) $row['status'] }}</span></td>
                                <td class="py-2"><span class="pl-badge">{{ (string) $row['publish_status'] }}</span></td>
                                <td class="py-2">
                                    @if ($row['locales']->isNotEmpty())
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($row['locales'] as $localeRow)
                                                <span title="{{ $localeRow['language_label'] }} {{ $localeRow['status_label'] }}" class="inline-flex items-center gap-1 rounded border px-2 py-0.5 text-xs {{ $localeRow['publish_status'] === 'published' ? 'border-emerald-300 bg-emerald-50 text-emerald-700' : 'border-amber-300 bg-amber-50 text-amber-700' }}">
                                                    {{ $localeRow['label'] }}
                                                    <span>{{ $localeRow['publish_status'] === 'published' ? 'published' : 'draft' }}</span>
                                                </span>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-textSecondary">-</span>
                                    @endif
                                </td>
                                <td class="py-2 text-textSecondary">{{ implode(', ', (array) $row['links_to']) ?: '-' }}</td>
                                <td class="py-2 text-textSecondary">
                                    {{ $row['published_at']?->format('Y-m-d H:i') ?: '-' }}
                                </td>
                                <td class="py-2">
                                    @if ($row['content'])
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a href="{{ route('app.content.show', $row['content']) }}" class="text-link hover:text-linkHover underline">Open content</a>
                                            <a href="{{ route('app.content.series.structure', $series) }}" class="text-link hover:text-linkHover underline">Adjust role</a>
                                        </div>
                                    @elseif ($row['can_retry'])
                                        <form method="POST" action="{{ route('app.content.series.generate-articles', $series) }}">
                                            @csrf
                                            <input type="hidden" name="article_numbers[]" value="{{ (int) $row['article_number'] }}">
                                            <button @disabled($isReadOnly) class="rounded border border-border px-2 py-1 text-xs disabled:cursor-not-allowed disabled:opacity-50">Retry</button>
                                        </form>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                            @if (!empty($row['error_message']))
                                <tr>
                                    <td colspan="9" class="pb-2 text-xs text-rose-700">{{ (string) $row['error_message'] }}</td>
                                </tr>
                            @endif
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="mt-3 text-sm text-textSecondary">No article rows available yet.</p>
            @endif
        </section>

        <section class="rounded border border-border bg-surface p-4">
            <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">Publishing History</h2>
                    <p class="text-sm text-textSecondary">Publishing controls and historical runs for this series archive.</p>
                </div>
                <form method="POST" action="{{ route('app.content.series.publish', $series) }}">
                    @csrf
                    <button @disabled(($progress['unpublished'] ?? 0) === 0) class="rounded border border-border px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-50">Publish remaining</button>
                </form>
            </div>

            <p class="text-sm text-textSecondary">Published: {{ $progress['published'] }} / {{ $progress['locales'] }} locale article(s). Existing translations are included in the next publish run.</p>

            @if ($publishHistory->isEmpty())
                <p class="mt-3 text-sm text-textSecondary">No publish history yet.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                        <tr class="text-left text-textSecondary">
                            <th class="pb-2 font-medium">Run at</th>
                            <th class="pb-2 font-medium">Site type</th>
                            <th class="pb-2 font-medium">Queued</th>
                            <th class="pb-2 font-medium">Published</th>
                            <th class="pb-2 font-medium">Failed</th>
                            <th class="pb-2 font-medium">Series status</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                        @foreach ($publishHistory as $historyRow)
                            <tr>
                                <td class="py-2 text-textSecondary">{{ (string) data_get($historyRow, 'run_at', '-') }}</td>
                                <td class="py-2 text-textSecondary">{{ data_get($historyRow, 'site_type', '-') }}</td>
                                <td class="py-2 text-textSecondary">{{ (int) data_get($historyRow, 'queued', 0) }}</td>
                                <td class="py-2 text-textSecondary">{{ (int) data_get($historyRow, 'published', 0) }}</td>
                                <td class="py-2 text-textSecondary">{{ (int) data_get($historyRow, 'failed', 0) }}</td>
                                <td class="py-2 text-textSecondary">{{ data_get($historyRow, 'result_status', '-') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    @if ($isGenerationRunning)
        <script>
            setInterval(function () {
                window.location.reload();
            }, 6000);
        </script>
    @endif
@endsection
