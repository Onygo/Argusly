@extends('layouts.app', ['title' => 'Briefs'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Briefs</x-slot:title>
        <x-slot:description>Create and manage briefs from the client dashboard.</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
        <a href="{{ route('app.briefs.create') }}" class="rounded border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
            New Brief
        </a>
@endsection

@section('content')

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

    <x-data-table label="Briefs" description="Briefs with site, content type, source, status, keyword, and update time.">
        <x-data-table.header>
            <x-data-table.row>
                <x-data-table.cell heading>Title</x-data-table.cell>
                <x-data-table.cell heading>Site</x-data-table.cell>
                <x-data-table.cell heading>Type</x-data-table.cell>
                <x-data-table.cell heading>Source</x-data-table.cell>
                <x-data-table.cell heading>Status</x-data-table.cell>
                <x-data-table.cell heading>Updated</x-data-table.cell>
            </x-data-table.row>
        </x-data-table.header>
        <tbody>
            @forelse ($briefs as $brief)
                @php
                    $briefResourceKey = 'brief:'.$brief->getKey();
                    $briefResource = $interactionResourcesByKey[$briefResourceKey] ?? null;
                    $briefOpenAction = $interactionActionsByKey[$briefResourceKey]['app.brief.open'] ?? null;
                    $briefShowHref = route('app.briefs.show', $brief);
                    $briefDrawerDescriptor = null;

                    if (is_array($briefResource) && is_array($briefOpenAction)) {
                        $briefDrawerDescriptor = \App\Support\Interaction\DrawerMetadataBuilder::make()->build(
                            \App\Support\Interaction\DrawerTarget::make(
                                'brief.inspect',
                                \App\Support\Interaction\DrawerState::MODE_INSPECT,
                                'md',
                            )
                                ->forResource(\App\Support\Interaction\ResourceType::BRIEF, $brief->getKey(), $briefResourceKey)
                                ->forAction('app.brief.open')
                                ->withHref($briefShowHref),
                            [
                                'resource' => $briefResource,
                                'action' => $briefOpenAction,
                                'metadata' => [
                                    'adoption' => 'app.briefs.index:second-additive-drawer',
                                    'renders_production_content' => false,
                                ],
                            ],
                        );
                    }
                @endphp
                <x-data-table.row>
                    <x-data-table.cell label="Title">
                        <div class="flex flex-col gap-2">
                            <a class="text-textPrimary hover:underline" href="{{ route('app.briefs.show', $brief) }}">{{ $brief->title }}</a>

                            @if ($briefDrawerDescriptor)
                                <x-drawer-button
                                    :descriptor="$briefDrawerDescriptor"
                                    :href="$briefShowHref"
                                    class="w-fit px-2 py-1 text-xs"
                                    aria-label="Inspect {{ $brief->title }}"
                                >
                                    Inspect
                                </x-drawer-button>
                            @endif

                            @if (!empty($brief->primary_keyword))
                                <div class="text-xs text-textSecondary">{{ $brief->primary_keyword }}</div>
                            @endif
                        </div>
                    </x-data-table.cell>
                    <x-data-table.cell label="Site">{{ $brief->clientSite?->name }}</x-data-table.cell>
                    <x-data-table.cell label="Type">{{ $brief->content_type ?: 'blog' }}</x-data-table.cell>
                    <x-data-table.cell label="Source">{{ $sourceOptions[$brief->source] ?? $brief->source }}</x-data-table.cell>
                    <x-data-table.cell label="Status">
                        <x-data-table.badge :label="$brief->status" />
                    </x-data-table.cell>
                    <x-data-table.cell label="Updated">{{ $brief->updated_at?->format('Y-m-d H:i') }}</x-data-table.cell>
                </x-data-table.row>
            @empty
                @if (empty($filters['q']) && empty($filters['status']) && empty($filters['site']))
                    <x-data-table.empty colspan="6" title="No briefs yet" description="Briefs are the starting point for your content. Define your topic, keywords, and audience to generate high-quality drafts." icon="clipboard-list">
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
                    </x-data-table.empty>
                @else
                    <x-data-table.empty colspan="6" title="No briefs match your filters" description="Try adjusting your search or filter criteria." icon="clipboard-list">
                        <a href="{{ route('app.briefs') }}" class="mt-4 inline-flex items-center gap-2 rounded border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                            <i data-lucide="x" class="h-4 w-4"></i>
                            Clear filters
                        </a>
                    </x-data-table.empty>
                @endif
            @endforelse
        </tbody>
        <x-slot:pagination>{{ $briefs->links() }}</x-slot:pagination>
    </x-data-table>
@endsection

@section('detailDrawer')
    <x-drawer.drawer
        :open="false"
        :drawer="[
            'key' => 'brief.inspect',
            'mode' => 'inspect',
            'modal' => false,
            'width' => 'md',
            'title' => 'Brief inspect',
            'subtitle' => 'Brief',
            'description' => 'Select Inspect on a brief row to open drawer metadata when drawer JavaScript is available.',
            'tabs' => [],
            'sections' => [],
            'footer_actions' => [],
            'empty_state' => [
                'title' => 'No brief selected',
                'description' => 'Brief detail pages remain the canonical destination.',
            ],
            'state' => [
                'mode' => 'inspect',
                'open' => false,
                'loading' => false,
                'empty' => true,
                'error' => false,
                'message' => null,
                'interactive' => false,
                'can_edit' => false,
                'metadata' => [
                    'renders_production_content' => false,
                ],
            ],
        ]"
    />
@endsection
