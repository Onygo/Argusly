@extends('layouts.app', ['title' => 'Briefs'])

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Briefs</h1>
            <p class="text-textSecondary mt-1">Create and manage briefs from the client dashboard.</p>
        </div>
        <a href="{{ route('app.briefs.create') }}" class="rounded border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
            New Brief
        </a>
    </div>

    <form method="GET" class="mb-4 grid gap-2 rounded-lg border border-border bg-surface p-3 text-sm md:grid-cols-6">
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="rounded border border-border px-3 py-2 md:col-span-2" placeholder="Search title or keyword">

        <select name="site" class="rounded border border-border px-3 py-2">
            <option value="">All sites</option>
            @foreach ($sites as $site)
                <option value="{{ $site->id }}" @selected(($filters['site'] ?? '') === (string) $site->id)>{{ $site->name }}</option>
            @endforeach
        </select>

        <select name="status" class="rounded border border-border px-3 py-2">
            <option value="">All statuses</option>
            @foreach ($statusOptions as $value => $label)
                <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <select name="source" class="rounded border border-border px-3 py-2">
            <option value="">All sources</option>
            @foreach ($sourceOptions as $value => $label)
                <option value="{{ $value }}" @selected(($filters['source'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <div class="grid grid-cols-2 gap-2">
            <select name="language" class="rounded border border-border px-3 py-2">
                <option value="">All lang</option>
                <option value="nl" @selected(($filters['language'] ?? '') === 'nl')>NL</option>
                <option value="en" @selected(($filters['language'] ?? '') === 'en')>EN</option>
            </select>
            <select name="content_type" class="rounded border border-border px-3 py-2">
                <option value="">All types</option>
                @foreach ($contentTypeOptions as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['content_type'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="md:col-span-6 flex items-center gap-2">
            <button class="rounded border border-border px-3 py-2">Filter</button>
            <a href="{{ route('app.briefs') }}" class="rounded border border-border px-3 py-2">Reset</a>
        </div>
    </form>

    <div class="rounded-lg border border-border bg-surface p-4">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-textSecondary">
                    <th class="pb-2 font-medium">Title</th>
                    <th class="pb-2 font-medium">Site</th>
                    <th class="pb-2 font-medium">Type</th>
                    <th class="pb-2 font-medium">Source</th>
                    <th class="pb-2 font-medium">Status</th>
                    <th class="pb-2 font-medium">Updated</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($briefs as $brief)
                    <tr>
                        <td class="py-3">
                            <a class="text-textPrimary hover:underline" href="{{ route('app.briefs.show', $brief) }}">{{ $brief->title }}</a>
                            @if (!empty($brief->primary_keyword))
                                <div class="text-xs text-textSecondary">{{ $brief->primary_keyword }}</div>
                            @endif
                        </td>
                        <td class="py-3">{{ $brief->clientSite?->name }}</td>
                        <td class="py-3">{{ $brief->content_type ?: 'blog' }}</td>
                        <td class="py-3">{{ $sourceOptions[$brief->source] ?? $brief->source }}</td>
                        <td class="py-3">{{ $brief->status }}</td>
                        <td class="py-3">{{ $brief->updated_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <div class="py-12 text-center">
                                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-primary/10">
                                    <i data-lucide="clipboard-list" class="h-8 w-8 text-primary"></i>
                                </div>
                                @if (empty($filters['q']) && empty($filters['status']) && empty($filters['site']))
                                    <h3 class="text-lg font-semibold text-textPrimary">No briefs yet</h3>
                                    <p class="mt-2 max-w-sm mx-auto text-textSecondary">Briefs are the starting point for your content. Define your topic, keywords, and audience to generate high-quality drafts.</p>
                                    <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                                        <a href="{{ route('app.briefs.create') }}" class="inline-flex items-center gap-2 rounded bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90">
                                            <i data-lucide="plus" class="h-4 w-4"></i>
                                            Create your first brief
                                        </a>
                                        <a href="{{ route('app.content.batches.create') }}" class="inline-flex items-center gap-2 rounded border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                            <i data-lucide="layers" class="h-4 w-4"></i>
                                            Generate multiple articles
                                        </a>
                                    </div>
                                @else
                                    <h3 class="text-lg font-semibold text-textPrimary">No briefs match your filters</h3>
                                    <p class="mt-2 text-textSecondary">Try adjusting your search or filter criteria.</p>
                                    <a href="{{ route('app.briefs') }}" class="mt-4 inline-flex items-center gap-2 rounded border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                        <i data-lucide="x" class="h-4 w-4"></i>
                                        Clear filters
                                    </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $briefs->links() }}</div>
@endsection
