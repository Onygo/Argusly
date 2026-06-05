@extends('layouts.admin', ['title' => 'Create Announcement', 'pageWidth' => 'constrained'])

@section('content')
    <div class="mb-6 flex items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Create Announcement</h1>
            <p class="mt-1 text-textSecondary">Send a workspace notification of type announcement.</p>
        </div>
        <a href="{{ route('admin.announcements.index') }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Back to announcements</a>
    </div>

    <div class="rounded-lg border border-border bg-surface p-4">
        <form method="POST" action="{{ route('admin.announcements.store') }}" class="grid gap-3 md:grid-cols-2">
            @csrf
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Target</label>
                <select name="target" class="pl-select bg-surface w-full">
                    <option value="selected" @selected(old('target', 'selected') === 'selected')>Selected workspace(s)</option>
                    <option value="all" @selected(old('target') === 'all')>All workspaces</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Priority</label>
                <input name="priority" type="number" min="1" max="999" value="{{ old('priority') }}" class="pl-input w-full" placeholder="Default announcement priority" />
            </div>
            <div class="md:col-span-2">
                <label class="mb-1 block text-xs text-textSecondary">Workspace(s)</label>
                <select name="workspace_ids[]" class="pl-select bg-surface w-full" multiple size="10">
                    @foreach ($workspaces as $workspace)
                        <option value="{{ $workspace->id }}" @selected(in_array((string) $workspace->id, (array) old('workspace_ids', []), true))>
                            {{ $workspace->name }} @if($workspace->organization) ({{ $workspace->organization->name }}) @endif
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="mb-1 block text-xs text-textSecondary">Title</label>
                <input type="text" name="title" required maxlength="120" value="{{ old('title') }}" class="pl-input w-full" />
            </div>
            <div class="md:col-span-2">
                <label class="mb-1 block text-xs text-textSecondary">Body</label>
                <textarea name="body" rows="4" maxlength="1000" class="pl-textarea w-full">{{ old('body') }}</textarea>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">CTA label</label>
                <input type="text" name="cta_label" maxlength="40" value="{{ old('cta_label') }}" class="pl-input w-full" />
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">CTA URL</label>
                <input type="text" name="cta_url" maxlength="1000" value="{{ old('cta_url') }}" class="pl-input w-full" placeholder="https://... or /app/path" />
            </div>
            <div class="md:col-span-2">
                <button type="submit" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Publish announcement</button>
            </div>
        </form>
    </div>
@endsection
