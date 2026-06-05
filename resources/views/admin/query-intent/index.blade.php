@extends('layouts.admin', ['title' => 'Query Intent Intelligence'])

@section('content')
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-textPrimary">Query Intent Intelligence</h1>
            <p class="mt-1 text-sm text-textSecondary">Debug reusable intent, funnel, audience, urgency, and impact classification.</p>
        </div>

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

        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-sm font-semibold text-textPrimary">Recent persisted classifications</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-textSecondary">
                            <th class="pb-2 font-medium">Title / query</th>
                            <th class="pb-2 font-medium">Intent</th>
                            <th class="pb-2 font-medium">Stage</th>
                            <th class="pb-2 font-medium">Audience</th>
                            <th class="pb-2 font-medium">Impact</th>
                            <th class="pb-2 font-medium">Priority</th>
                            <th class="pb-2 font-medium">Classified</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($classifications as $classification)
                            <tr>
                                <td class="py-2 text-textPrimary">
                                    {{ $classification->title ?: $classification->query ?: $classification->source_key ?: $classification->id }}
                                    <p class="text-xs text-textSecondary">{{ $classification->source_type }}</p>
                                </td>
                                <td class="py-2 text-textSecondary">{{ $classification->primary_intent }}</td>
                                <td class="py-2 text-textSecondary">{{ $classification->funnel_stage }}</td>
                                <td class="py-2 text-textSecondary">{{ str_replace('_', ' ', $classification->buyer_role) }}</td>
                                <td class="py-2 text-textSecondary">{{ $classification->business_impact }}</td>
                                <td class="py-2 text-textPrimary">{{ number_format((float) $classification->priority_score, 1) }}</td>
                                <td class="py-2 text-textSecondary">{{ optional($classification->classified_at)->format('Y-m-d H:i') ?: '-' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-4 text-textSecondary">No persisted classifications yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $classifications->links() }}</div>
        </div>
    </div>
@endsection
