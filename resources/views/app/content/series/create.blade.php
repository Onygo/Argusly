@extends('layouts.app', ['title' => 'Create Content Series', 'pageWidth' => 'constrained'])

@section('content')
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Step 1: Series setup</h1>
            <p class="mt-1 text-textSecondary">Define the core topic, targeting, and article count for your chained content plan.</p>
        </div>
        <a href="{{ route('app.content.series.index') }}" class="rounded border border-border px-3 py-2 text-sm">Back to series</a>
    </div>

    <form method="POST" action="{{ route('app.content.series.store') }}" class="space-y-5 rounded-lg border border-border bg-surface p-4 sm:p-5">
        @csrf

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Series name</label>
                <input type="text" name="name" value="{{ old('name', $prefill['name'] ?? '') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Site</label>
                <select name="site_id" class="pl-select bg-background" required>
                    <option value="">Select a site</option>
                    @foreach($sites as $site)
                        <option value="{{ $site->id }}" @selected(old('site_id', $prefill['site_id'] ?? '') === (string) $site->id)>
                            {{ $site->name }} ({{ $site->type }})
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Content type</label>
                <select name="content_type" class="pl-select bg-background">
                    @foreach($contentTypeOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('content_type', $prefill['content_type'] ?? 'post') === $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-textSecondary">Determines WordPress post type and URL structure (e.g. /blog/ vs /knowledge-base/)</p>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Main topic</label>
                <input type="text" name="main_topic" value="{{ old('main_topic', $prefill['main_topic'] ?? '') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Primary keyword</label>
                <input type="text" name="primary_keyword" value="{{ old('primary_keyword', $prefill['primary_keyword'] ?? '') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required>
            </div>
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Supporting keywords</label>
            <textarea name="supporting_keywords" rows="4" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" placeholder="One keyword per line or comma-separated">{{ old('supporting_keywords', $prefill['supporting_keywords'] ?? '') }}</textarea>
        </div>

        <x-forms.tag-multi-select
            name="intents"
            label="Content intent"
            :options="$contentIntentOptions"
            :selected="old('intents', old('intent_keys', $prefill['intent_keys'] ?? []))"
            placeholder="Select one or more intents"
            help="Optional. When left empty, PublishLayer auto-detects likely intents from your keywords."
        />

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Audience</label>
                <input type="text" name="audience" value="{{ old('audience', $prefill['audience'] ?? '') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Tone</label>
                <input type="text" name="tone" value="{{ old('tone', $prefill['tone'] ?? '') }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Funnel stage</label>
                <select name="funnel_stage" class="pl-select bg-background">
                    <option value="">Select stage</option>
                    @foreach(['awareness', 'consideration', 'decision', 'retention'] as $stage)
                        <option value="{{ $stage }}" @selected(old('funnel_stage', $prefill['funnel_stage'] ?? '') === $stage)>{{ ucfirst($stage) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Articles count</label>
                <input type="number" name="articles_count" min="1" max="20" value="{{ old('articles_count', $prefill['articles_count'] ?? 5) }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
            </div>
        </div>

        <div class="flex justify-end">
            <button class="rounded border border-border px-3 py-2 text-sm">Create series</button>
        </div>
    </form>
@endsection
