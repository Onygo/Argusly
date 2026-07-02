@extends('layouts.admin', ['title' => 'Edit Product Update', 'pageWidth' => 'constrained'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Edit product update</x-slot:title>
        <x-slot:description>Update content, visibility, tags, and publication timing.</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
        <a href="{{ route('admin.product-updates.index') }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Back to updates</a>
@endsection

@section('content')

    @if (session('status'))
        <div class="mb-4 rounded border border-border bg-surface px-3 py-2 text-sm text-textPrimary">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded border border-danger/30 bg-danger/5 px-3 py-2 text-sm text-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div class="rounded-lg border border-border bg-surface p-4">
            <form method="POST" action="{{ route('admin.product-updates.update', $productUpdate) }}" class="grid gap-3 md:grid-cols-2">
                @csrf

                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs text-textSecondary">Title</label>
                    <input type="text" name="title" required maxlength="180" value="{{ old('title', $productUpdate->title) }}" class="pl-input w-full" />
                </div>

                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs text-textSecondary">Summary</label>
                    <input type="text" name="summary" required maxlength="280" value="{{ old('summary', $productUpdate->summary) }}" class="pl-input w-full" />
                </div>

                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs text-textSecondary">Body (Markdown)</label>
                    <textarea name="body_markdown" rows="12" required class="pl-textarea w-full">{{ old('body_markdown', $productUpdate->body_markdown) }}</textarea>
                </div>

                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Version</label>
                    <input type="text" name="version" maxlength="30" value="{{ old('version', $productUpdate->version) }}" class="pl-input w-full" placeholder="v0.3.1" />
                </div>

                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Tags (comma separated)</label>
                    <input type="text" name="tags_input" maxlength="500" value="{{ old('tags_input', implode(', ', (array) $productUpdate->tags)) }}" class="pl-input w-full" placeholder="release, connector, billing" />
                </div>

                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Published at</label>
                    <input type="datetime-local" name="published_at" required value="{{ old('published_at', optional($productUpdate->published_at)->format('Y-m-d\\TH:i')) }}" class="pl-input w-full" />
                </div>

                <div class="flex items-center gap-2 pt-6">
                    <input id="is_public" type="checkbox" name="is_public" value="1" @checked(old('is_public', $productUpdate->is_public)) class="h-4 w-4 rounded border-border">
                    <label for="is_public" class="text-sm text-textPrimary">Mark as public release (for future use)</label>
                </div>

                <div class="md:col-span-2 flex flex-wrap gap-2">
                    <button type="submit" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Save update</button>
                </div>
            </form>
        </div>

        <aside class="rounded-lg border border-border bg-surface p-4">
            <h2 class="text-sm font-semibold text-textPrimary">Preview</h2>
            <p class="mt-1 text-xs text-textSecondary">Rendered output as seen on the public page.</p>

            <article class="mt-3 rounded border border-border bg-background p-3">
                <p class="text-xs text-textSecondary">
                    {{ optional($productUpdate->published_at)->format('Y-m-d') }}
                    @if ($productUpdate->version)
                        · {{ $productUpdate->version }}
                    @endif
                </p>
                <h3 class="mt-1 text-sm font-semibold text-textPrimary">{{ $productUpdate->title }}</h3>
                <p class="mt-1 text-xs text-textSecondary">{{ $productUpdate->summary }}</p>
                @if (!empty($productUpdate->tags))
                    <div class="mt-2 flex flex-wrap gap-1">
                        @foreach ((array) $productUpdate->tags as $tag)
                            <span class="rounded border border-border px-2 py-0.5 text-[11px] text-textSecondary">{{ $tag }}</span>
                        @endforeach
                    </div>
                @endif
                <div class="mt-3 text-xs leading-5 text-textSecondary [&_h1]:mt-3 [&_h1]:text-sm [&_h1]:font-semibold [&_h2]:mt-3 [&_h2]:text-sm [&_h2]:font-semibold [&_h3]:mt-3 [&_h3]:text-sm [&_h3]:font-semibold [&_ul]:ml-5 [&_ul]:list-disc [&_ol]:ml-5 [&_ol]:list-decimal [&_a]:text-link [&_a]:underline">
                    {!! $productUpdate->body_html !!}
                </div>
            </article>
        </aside>
    </div>
@endsection
