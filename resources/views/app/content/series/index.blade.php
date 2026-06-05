@extends('layouts.app', ['title' => 'Chains'])

@section('content')
    @php
        $activeFilter = $filter ?? 'all';
        $filterLabels = [
            'all' => 'All',
            'draft' => 'Draft',
            'published' => 'Published',
            'scheduled' => 'Scheduled',
            'archived' => 'Archived',
        ];
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
    @endphp

    <x-app.content-area-header mode="chains">
        <a href="{{ route('app.content.series.create') }}" class="rounded border border-border bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90">New chain</a>
    </x-app.content-area-header>

    @if (session('status'))
        <x-alert class="my-4">{{ session('status') }}</x-alert>
    @endif

    <div class="mt-6 mb-4 flex flex-wrap gap-2">
        @foreach (($filters ?? ['all', 'draft', 'published', 'scheduled', 'archived']) as $filterKey)
            <a
                href="{{ route('app.content.series.index', ['filter' => $filterKey]) }}"
                class="{{ $activeFilter === $filterKey ? 'bg-surfaceMuted text-textPrimary' : 'text-textSecondary' }} rounded border border-border px-3 py-1.5 text-sm"
            >
                {{ $filterLabels[$filterKey] ?? ucfirst($filterKey) }}
            </a>
        @endforeach
    </div>

    <div class="rounded-lg border border-border bg-surface p-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
            <tr class="text-left text-textSecondary">
                <th class="pb-2 pr-3 font-medium">Chain name</th>
                <th class="pb-2 pr-3 font-medium">Site</th>
                <th class="pb-2 pr-3 font-medium">Progress</th>
                <th class="pb-2 pr-3 font-medium">Locales</th>
                <th class="pb-2 pr-3 font-medium">Pillar</th>
                <th class="pb-2 pr-3 font-medium">Status</th>
                <th class="pb-2 pr-3 font-medium">Updated</th>
                <th class="pb-2 font-medium">Actions</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-border">
            @forelse ($series as $item)
                @php
                    $statusKey = $item->normalizedStatus();
                    $badge = $badgeMap[$statusKey] ?? [ucfirst($statusKey), 'border-border text-textSecondary'];
                    $isPublished = $statusKey === \App\Models\ContentSeries::STATUS_PUBLISHED;
                    $isDraft = $statusKey === \App\Models\ContentSeries::STATUS_DRAFT;
                @endphp
                @php
                    $contentCount = $item->contents_count ?? 0;
                    $totalCount = $item->articles_count ?? 1;
                    $completeness = $totalCount > 0 ? round(($contentCount / $totalCount) * 100) : 0;
                    $localeCount = $item->unique_locales_count ?? 0;
                @endphp
                <tr class="{{ $isPublished ? 'bg-surfaceMuted/30' : '' }}">
                    <td class="py-3 pr-3 text-textPrimary">
                        <a href="{{ route('app.content.series.show', $item) }}" class="underline-offset-2 hover:underline">
                            {{ $item->name }}
                        </a>
                    </td>
                    <td class="py-3 pr-3 text-textSecondary">{{ $item->site?->name ?? 'n/a' }}</td>
                    <td class="py-3 pr-3">
                        <div class="flex items-center gap-2">
                            <div class="h-1.5 w-16 overflow-hidden rounded-full bg-surfaceMuted">
                                <div class="h-full rounded-full {{ $completeness === 100 ? 'bg-emerald-500' : 'bg-primary' }}" style="width: {{ $completeness }}%"></div>
                            </div>
                            <span class="text-xs text-textSecondary">{{ $contentCount }}/{{ $totalCount }}</span>
                        </div>
                    </td>
                    <td class="py-3 pr-3 text-textSecondary">
                        @if ($localeCount > 0)
                            {{ $localeCount }} locale{{ $localeCount > 1 ? 's' : '' }}
                        @else
                            <span class="text-textFaint">1</span>
                        @endif
                    </td>
                    <td class="py-3 pr-3 text-textSecondary truncate max-w-[10rem]">
                        {{ $item->getPillarArticle()?->title ?: $item->getPillarArticle()?->content?->title ?: 'Not set' }}
                    </td>
                    <td class="py-3 pr-3">
                        <span class="inline-flex items-center rounded border px-2 py-0.5 text-xs {{ $badge[1] }}">{{ $badge[0] }}</span>
                    </td>
                    <td class="py-3 pr-3 text-textSecondary">{{ $item->updated_at?->format('M j, Y') }}</td>
                    <td class="py-3">
                        <details class="relative inline-block">
                            <summary class="cursor-pointer list-none rounded border border-border px-2 py-1 text-xs text-textPrimary">Actions</summary>
                            <div class="absolute right-0 z-10 mt-1 min-w-40 rounded border border-border bg-surface p-1">
                                <a href="{{ route('app.content.series.show', $item) }}" class="block rounded px-2 py-1 text-sm text-textPrimary hover:bg-surfaceMuted">View</a>
                                <form method="POST" action="{{ route('app.content.series.duplicate', $item) }}">
                                    @csrf
                                    <button class="block w-full rounded px-2 py-1 text-left text-sm text-textPrimary hover:bg-surfaceMuted">Duplicate</button>
                                </form>
                                @if ($isPublished)
                                    <form method="POST" action="{{ route('app.content.series.archive', $item) }}">
                                        @csrf
                                        <button class="block w-full rounded px-2 py-1 text-left text-sm text-textPrimary hover:bg-surfaceMuted">Archive</button>
                                    </form>
                                @endif
                                @if ($isDraft)
                                    <form method="POST" action="{{ route('app.content.series.destroy', $item) }}" onsubmit="return confirm('Delete this draft series?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="block w-full rounded px-2 py-1 text-left text-sm text-rose-700 hover:bg-rose-50">Delete</button>
                                    </form>
                                @endif
                            </div>
                        </details>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="py-6 text-center text-textSecondary">No content chains yet.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $series->links() }}</div>
@endsection
