@extends('layouts.app', ['title' => 'Drafts'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Drafts</h1>
        <p class="text-textSecondary mt-1">All drafts for your organization.</p>
    </div>

    <div class="rounded-lg border border-border bg-surface p-4">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-textSecondary">
                    <th class="pb-2 font-medium">Title</th>
                    <th class="pb-2 font-medium">Brief</th>
                    <th class="pb-2 font-medium">Language</th>
                    <th class="pb-2 font-medium">Type</th>
                    <th class="pb-2 font-medium">Status</th>
                    <th class="pb-2 font-medium">Created</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($drafts as $draft)
                    <tr>
                        <td class="py-3">
                            <a class="text-textPrimary hover:underline" href="{{ route('app.drafts.show', $draft) }}">{{ $draft->title }}</a>
                        </td>
                        <td class="py-3">{{ $draft->brief?->title }}</td>
                        <td class="py-3">
                            <span class="inline-flex items-center rounded border border-border px-2 py-1 text-xs text-textPrimary">{{ strtoupper((string) $draft->language->value) }}</span>
                        </td>
                        <td class="py-3">
                            <span class="inline-flex items-center rounded border border-border px-2 py-1 text-xs text-textPrimary">{{ $draft->draft_type->label() }}</span>
                        </td>
                        <td class="py-3">
                            <x-status-badge :label="ucfirst(str_replace('_', ' ', $draft->status))" color="slate" />
                        </td>
                        <td class="py-3">{{ $draft->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">
                            <div class="py-12 text-center">
                                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-primary/10">
                                    <i data-lucide="pen-tool" class="h-8 w-8 text-primary"></i>
                                </div>
                                <h3 class="text-lg font-semibold text-textPrimary">No drafts yet</h3>
                                <p class="mt-2 max-w-sm mx-auto text-textSecondary">Drafts are generated from briefs using AI. Create a brief first, then generate a draft to see it here.</p>
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
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $drafts->links() }}</div>
@endsection
