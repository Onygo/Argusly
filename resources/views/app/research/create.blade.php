@extends('layouts.app', ['title' => 'Create Research Project', 'pageWidth' => 'constrained'])

@section('content')
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Create research project</h1>
            <p class="mt-1 text-textSecondary">Workspace: {{ $workspace->display_name }}</p>
        </div>
        <a href="{{ route('app.research.index', ['workspace_id' => $workspace->id]) }}" class="rounded border border-border px-3 py-2 text-sm">Back to research</a>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first() }}</div>
    @endif

    <div class="rounded-lg border border-border bg-surface p-4">
        <form method="POST" action="{{ route('app.research.store') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="workspace_id" value="{{ $workspace->id }}">

            <div>
                <label class="mb-1 block text-xs text-textSecondary">Project name</label>
                <input name="name" value="{{ old('name') }}" required maxlength="191" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary">Target keywords (comma or newline)</label>
                <textarea name="target_keywords" rows="3" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ is_array(old('target_keywords')) ? implode("\n", old('target_keywords')) : old('target_keywords') }}</textarea>
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary">Source URLs (one per line, max {{ $maxSources }})</label>
                <textarea name="source_urls" rows="6" required class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ is_array(old('source_urls')) ? implode("\n", old('source_urls')) : old('source_urls') }}</textarea>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Optional brief link</label>
                    <select name="brief_id" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
                        <option value="">None</option>
                        @foreach ($briefs as $brief)
                            <option value="{{ $brief->id }}" @selected(old('brief_id') === (string) $brief->id)>
                                {{ $brief->title ?: 'Untitled brief' }} ({{ optional($brief->created_at)->toDateString() }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Optional site link</label>
                    <select name="client_site_id" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
                        <option value="">None</option>
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}" @selected(old('client_site_id') === (string) $site->id)>{{ $site->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <button class="rounded border border-border px-4 py-2 text-sm">Create research project</button>
        </form>
    </div>
@endsection
