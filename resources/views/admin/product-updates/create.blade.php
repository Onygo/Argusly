@extends('layouts.admin', ['title' => 'Create Product Update', 'pageWidth' => 'constrained'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Create product update</x-slot:title>
        <x-slot:description>Create a new public changelog entry.</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
        <a href="{{ route('admin.product-updates.index') }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Back to updates</a>
@endsection

@section('content')

    @if ($errors->any())
        <div class="mb-4 rounded border border-danger/30 bg-danger/5 px-3 py-2 text-sm text-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="rounded-lg border border-border bg-surface p-4">
        <form method="POST" action="{{ route('admin.product-updates.store') }}" class="grid gap-3 md:grid-cols-2">
            @csrf

            <div class="md:col-span-2">
                <label class="mb-1 block text-xs text-textSecondary">Title</label>
                <input type="text" name="title" required maxlength="180" value="{{ old('title') }}" class="pl-input w-full" />
            </div>

            <div class="md:col-span-2">
                <label class="mb-1 block text-xs text-textSecondary">Summary</label>
                <input type="text" name="summary" required maxlength="280" value="{{ old('summary') }}" class="pl-input w-full" />
            </div>

            <div class="md:col-span-2">
                <label class="mb-1 block text-xs text-textSecondary">Body (Markdown)</label>
                <textarea name="body_markdown" rows="10" required class="pl-textarea w-full">{{ old('body_markdown') }}</textarea>
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary">Version</label>
                <input type="text" name="version" maxlength="30" value="{{ old('version') }}" class="pl-input w-full" placeholder="v0.3.1" />
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary">Tags (comma separated)</label>
                <input type="text" name="tags_input" maxlength="500" value="{{ old('tags_input') }}" class="pl-input w-full" placeholder="release, connector, billing" />
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary">Published at</label>
                <input type="datetime-local" name="published_at" required value="{{ old('published_at', now()->format('Y-m-d\\TH:i')) }}" class="pl-input w-full" />
            </div>

            <div class="flex items-center gap-2 pt-6">
                <input id="is_public" type="checkbox" name="is_public" value="1" @checked(old('is_public')) class="h-4 w-4 rounded border-border">
                <label for="is_public" class="text-sm text-textPrimary">Visible on public site</label>
            </div>

            <div class="md:col-span-2">
                <button type="submit" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Create update</button>
            </div>
        </form>
    </div>
@endsection
