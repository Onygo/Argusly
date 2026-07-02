@extends('layouts.app', ['title' => 'Drafts'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Drafts</x-slot:title>
        <x-slot:description>All drafts for your organization.</x-slot:description>
    </x-page-header>
@endsection

@section('content')

    <x-data-table label="Drafts" description="All drafts for your organization with brief, language, type, status, and creation time.">
        <x-data-table.header>
            <x-data-table.row>
                <x-data-table.cell heading>Title</x-data-table.cell>
                <x-data-table.cell heading>Brief</x-data-table.cell>
                <x-data-table.cell heading>Language</x-data-table.cell>
                <x-data-table.cell heading>Type</x-data-table.cell>
                <x-data-table.cell heading>Status</x-data-table.cell>
                <x-data-table.cell heading>Created</x-data-table.cell>
            </x-data-table.row>
        </x-data-table.header>
        <tbody>
            @forelse ($drafts as $draft)
                @php
                    $draftResourceKey = 'draft:'.$draft->getKey();
                    $draftResource = $interactionResourcesByKey[$draftResourceKey] ?? null;
                    $draftOpenAction = $interactionActionsByKey[$draftResourceKey]['app.draft.open'] ?? null;
                    $draftShowHref = route('app.drafts.show', $draft);
                    $draftDrawerDescriptor = null;

                    if (is_array($draftResource) && is_array($draftOpenAction)) {
                        $draftDrawerDescriptor = \App\Support\Interaction\DrawerMetadataBuilder::make()->build(
                            \App\Support\Interaction\DrawerTarget::make(
                                'draft.inspect',
                                \App\Support\Interaction\DrawerState::MODE_INSPECT,
                                'md',
                            )
                                ->forResource(\App\Support\Interaction\ResourceType::DRAFT, $draft->getKey(), $draftResourceKey)
                                ->forAction('app.draft.open')
                                ->withHref($draftShowHref),
                            [
                                'resource' => $draftResource,
                                'action' => $draftOpenAction,
                                'metadata' => [
                                    'adoption' => 'app.drafts.index:first-additive-drawer',
                                    'renders_production_content' => false,
                                ],
                            ],
                        );
                    }
                @endphp
                <x-data-table.row>
                    <x-data-table.cell label="Title">
                        <div class="flex flex-col gap-2">
                            <a class="text-textPrimary hover:underline" href="{{ route('app.drafts.show', $draft) }}">{{ $draft->title }}</a>

                            @if ($draftDrawerDescriptor)
                                <x-drawer-button
                                    :descriptor="$draftDrawerDescriptor"
                                    :href="$draftShowHref"
                                    class="w-fit px-2 py-1 text-xs"
                                    aria-label="Inspect {{ $draft->title }}"
                                >
                                    Inspect
                                </x-drawer-button>
                            @endif
                        </div>
                    </x-data-table.cell>
                    <x-data-table.cell label="Brief">{{ $draft->brief?->title }}</x-data-table.cell>
                    <x-data-table.cell label="Language">
                        <x-data-table.badge :label="strtoupper((string) $draft->language->value)" />
                    </x-data-table.cell>
                    <x-data-table.cell label="Type">
                        <x-data-table.badge :label="$draft->draft_type->label()" />
                    </x-data-table.cell>
                    <x-data-table.cell label="Status">
                        <x-data-table.badge :label="ucfirst(str_replace('_', ' ', $draft->status))" />
                    </x-data-table.cell>
                    <x-data-table.cell label="Created">{{ $draft->created_at?->format('Y-m-d H:i') }}</x-data-table.cell>
                </x-data-table.row>
            @empty
                <x-data-table.empty colspan="6" title="No drafts yet" description="Drafts are generated from briefs using AI. Create a brief first, then generate a draft to see it here." icon="pen-tool">
                    <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                        <a href="{{ route('app.briefs.create') }}" class="inline-flex items-center gap-2 rounded bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90">
                            <i data-lucide="plus" class="h-4 w-4"></i>
                            Create a brief
                        </a>
                        <a href="{{ route('app.content.index') }}" class="inline-flex items-center gap-2 rounded border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                            <i data-lucide="file-text" class="h-4 w-4"></i>
                            View content
                        </a>
                    </div>
                </x-data-table.empty>
            @endforelse
        </tbody>
        <x-slot:pagination>{{ $drafts->links() }}</x-slot:pagination>
    </x-data-table>
@endsection

@section('detailDrawer')
    <x-drawer.drawer
        :open="false"
        :drawer="[
            'key' => 'draft.inspect',
            'mode' => 'inspect',
            'modal' => false,
            'width' => 'md',
            'title' => 'Draft inspect',
            'subtitle' => 'Draft',
            'description' => 'Select Inspect on a draft row to open drawer metadata when drawer JavaScript is available.',
            'tabs' => [],
            'sections' => [],
            'footer_actions' => [],
            'empty_state' => [
                'title' => 'No draft selected',
                'description' => 'Draft detail pages remain the canonical destination.',
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
