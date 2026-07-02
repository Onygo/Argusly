@extends('layouts.admin', ['title' => 'Query Intent Intelligence'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Query Intent Intelligence</x-slot:title>
        <x-slot:description>Debug reusable intent, funnel, audience, urgency, and impact classification.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="space-y-6">

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        <div class="grid gap-6 xl:grid-cols-2">
            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Classifier input</h2>
                <form method="POST" action="{{ route('admin.query-intent.debug') }}" class="mt-4 space-y-4">
                    @csrf
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Title</label>
                            <input name="title" value="{{ old('title', $input['title'] ?? '') }}" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Query</label>
                            <input name="query" value="{{ old('query', $input['query'] ?? '') }}" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Opportunity text</label>
                        <textarea name="text" rows="8" required class="w-full rounded border border-border bg-background px-2 py-2 text-sm">{{ old('text', $input['text'] ?? '') }}</textarea>
                    </div>
                    <div class="grid gap-3 md:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Locale</label>
                            <input name="locale" value="{{ old('locale', $input['locale'] ?? 'en') }}" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Source type</label>
                            <input name="source_type" value="{{ old('source_type', $input['source_type'] ?? 'admin_debug') }}" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Source key</label>
                            <input name="source_key" value="{{ old('source_key', $input['source_key'] ?? '') }}" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-xs text-textSecondary">
                        <input type="checkbox" name="persist" value="1" @checked(old('persist', false))>
                        Persist this debug classification
                    </label>
                    <button class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary">Classify</button>
                </form>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Result</h2>
                @if ($result)
                    <div class="mt-4 grid gap-3 text-sm md:grid-cols-2">
                        <div>
                            <p class="text-xs text-textSecondary">Intent</p>
                            <p class="font-medium text-textPrimary">{{ $result['primary_intent'] ?? '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-textSecondary">Funnel stage</p>
                            <p class="font-medium text-textPrimary">{{ $result['funnel_stage'] ?? '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-textSecondary">Buyer role</p>
                            <p class="font-medium text-textPrimary">{{ str_replace('_', ' ', $result['buyer_role'] ?? '-') }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-textSecondary">Urgency</p>
                            <p class="font-medium text-textPrimary">{{ $result['urgency'] ?? '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-textSecondary">Business impact</p>
                            <p class="font-medium text-textPrimary">{{ $result['business_impact'] ?? '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-textSecondary">Priority score</p>
                            <p class="font-medium text-textPrimary">{{ number_format((float) ($result['priority_score'] ?? 0), 1) }}</p>
                        </div>
                    </div>
                    <pre class="mt-4 max-h-96 overflow-auto rounded-md border border-border bg-background p-3 text-xs text-textSecondary">{{ json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                @else
                    <p class="mt-4 text-sm text-textSecondary">Run a debug classification to inspect signals.</p>
                @endif
            </div>
        </div>

        <x-data-table label="Recent persisted classifications" description="Recent persisted query intent classifications with intent, stage, audience, impact, priority, and classification time." density="compact">
                <x-slot:toolbar>
                    <x-data-table.toolbar title="Recent persisted classifications" />
                </x-slot:toolbar>

                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Title / query</x-data-table.cell>
                        <x-data-table.cell heading>Intent</x-data-table.cell>
                        <x-data-table.cell heading>Stage</x-data-table.cell>
                        <x-data-table.cell heading>Audience</x-data-table.cell>
                        <x-data-table.cell heading>Impact</x-data-table.cell>
                        <x-data-table.cell heading>Priority</x-data-table.cell>
                        <x-data-table.cell heading>Classified</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                        @forelse ($classifications as $classification)
                            <x-data-table.row>
                                <x-data-table.cell label="Title / query" class="text-textPrimary">
                                    {{ $classification->title ?: $classification->query ?: $classification->source_key ?: $classification->id }}
                                    <p class="text-xs text-textSecondary">{{ $classification->source_type }}</p>
                                </x-data-table.cell>
                                <x-data-table.cell label="Intent" class="text-textSecondary">{{ $classification->primary_intent }}</x-data-table.cell>
                                <x-data-table.cell label="Stage" class="text-textSecondary">{{ $classification->funnel_stage }}</x-data-table.cell>
                                <x-data-table.cell label="Audience" class="text-textSecondary">{{ str_replace('_', ' ', $classification->buyer_role) }}</x-data-table.cell>
                                <x-data-table.cell label="Impact" class="text-textSecondary">{{ $classification->business_impact }}</x-data-table.cell>
                                <x-data-table.cell label="Priority" class="text-textPrimary">{{ number_format((float) $classification->priority_score, 1) }}</x-data-table.cell>
                                <x-data-table.cell label="Classified" class="text-textSecondary">{{ optional($classification->classified_at)->format('Y-m-d H:i') ?: '-' }}</x-data-table.cell>
                            </x-data-table.row>
                        @empty
                            <x-data-table.empty colspan="7" title="No persisted classifications yet" />
                        @endforelse
                </tbody>
            <x-slot:pagination>{{ $classifications->links() }}</x-slot:pagination>
        </x-data-table>
    </div>
@endsection
