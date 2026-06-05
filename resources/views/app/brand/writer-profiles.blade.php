@extends('layouts.app', ['title' => 'Writer Profiles'])

@section('content')
    <div class="mb-6">
        <nav class="mb-2 text-sm text-textSecondary">
            <span>Brand</span>
            <span class="mx-1">/</span>
            <span class="text-textPrimary">Writer Profiles</span>
        </nav>
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Writer Profiles</h1>
        <p class="mt-1 text-textSecondary">Dit profiel helpt Argusly schrijven in een herkenbare stijl zonder teksten letterlijk over te nemen.</p>
    </div>

    @include('app.brand.partials.tabs')

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->any())
        <x-alert variant="error" class="mb-4">{{ $errors->first() }}</x-alert>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
        <div class="space-y-4">
            @forelse ($writerProfiles as $profile)
                <div class="rounded-lg border border-border bg-surface p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg font-semibold text-textPrimary">{{ $profile->name }}</h2>
                                <span class="rounded-full border border-border px-2 py-1 text-xs text-textSecondary">{{ $profile->profile_scope }}</span>
                                <span class="rounded-full border border-border px-2 py-1 text-xs text-textSecondary">{{ $profile->status }}</span>
                            </div>
                            <p class="mt-1 text-sm text-textSecondary">{{ $profile->description ?: 'No description yet.' }}</p>
                        </div>
                        <div class="text-right text-xs text-textSecondary">
                            <div>{{ $profile->sources_count }} sources</div>
                            <div>{{ number_format((float) $profile->confidence_score * 100, 0) }}% confidence</div>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 text-sm text-textSecondary lg:grid-cols-2">
                        <div><strong class="text-textPrimary">Tone:</strong> {{ $profile->tone_summary ?: 'Not analyzed yet.' }}</div>
                        <div><strong class="text-textPrimary">Style:</strong> {{ $profile->writing_style_summary ?: 'Not analyzed yet.' }}</div>
                        <div><strong class="text-textPrimary">Structure:</strong> {{ $profile->structure_summary ?: 'Not analyzed yet.' }}</div>
                        <div><strong class="text-textPrimary">Vocabulary:</strong> {{ $profile->vocabulary_notes ?: 'Not analyzed yet.' }}</div>
                    </div>

                    @can('update', $profile)
                        <details class="mt-4 rounded-lg border border-border bg-background p-4">
                            <summary class="cursor-pointer text-sm font-semibold text-textPrimary">Edit and re-analyze</summary>
                            <form method="POST" action="{{ route('app.brand.writer-profiles.update', $profile) }}" class="mt-4 space-y-4">
                                @csrf
                                <div class="grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <label class="text-xs text-textSecondary">Name</label>
                                        <input name="name" value="{{ $profile->name }}" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" required>
                                    </div>
                                    <div>
                                        <label class="text-xs text-textSecondary">Linked brand voice</label>
                                        <select name="brand_id" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                            <option value="">No brand voice link</option>
                                            @foreach ($brandVoices as $voice)
                                                <option value="{{ $voice->id }}" @selected($profile->brand_id === $voice->id)>{{ $voice->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="grid gap-4 lg:grid-cols-2">
                                    <select name="source_type" class="rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                        @foreach (['manual', 'uploaded_texts', 'content_history', 'mixed'] as $sourceType)
                                            <option value="{{ $sourceType }}" @selected($profile->source_type === $sourceType)>{{ str_replace('_', ' ', $sourceType) }}</option>
                                        @endforeach
                                    </select>
                                    <select name="profile_scope" class="rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                        @foreach (['author', 'brand', 'company', 'campaign'] as $scope)
                                            <option value="{{ $scope }}" @selected($profile->profile_scope === $scope)>{{ $scope }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <textarea name="description" rows="2" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" placeholder="Description">{{ $profile->description }}</textarea>
                                <div class="grid gap-4 lg:grid-cols-2">
                                    <textarea name="tone_summary" rows="3" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" placeholder="Tone">{{ $profile->tone_summary }}</textarea>
                                    <textarea name="writing_style_summary" rows="3" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" placeholder="Writing style">{{ $profile->writing_style_summary }}</textarea>
                                    <textarea name="structure_summary" rows="3" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" placeholder="Structure">{{ $profile->structure_summary }}</textarea>
                                    <textarea name="vocabulary_notes" rows="3" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" placeholder="Vocabulary">{{ $profile->vocabulary_notes }}</textarea>
                                </div>
                                <textarea name="formatting_preferences" rows="2" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" placeholder="Formatting">{{ $profile->formatting_preferences }}</textarea>
                                <div class="grid gap-4 lg:grid-cols-3">
                                    <textarea name="do_rules_text" rows="4" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" placeholder="Do rules">{{ implode("\n", (array) $profile->do_rules) }}</textarea>
                                    <textarea name="dont_rules_text" rows="4" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" placeholder="Don't rules">{{ implode("\n", (array) $profile->dont_rules) }}</textarea>
                                    <textarea name="example_patterns_text" rows="4" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" placeholder="Example patterns">{{ implode("\n", (array) $profile->example_patterns) }}</textarea>
                                </div>
                                <div class="flex flex-wrap gap-4 text-xs text-textSecondary">
                                    <label><input type="checkbox" name="retain_source_text" value="1" @checked($profile->retain_source_text)> Store source text</label>
                                    <label><input type="checkbox" name="default_blog" value="1" @checked(data_get($profile->channel_defaults, 'blog'))> Default blog</label>
                                    <label><input type="checkbox" name="default_linkedin" value="1" @checked(data_get($profile->channel_defaults, 'linkedin'))> Default LinkedIn</label>
                                    <label><input type="checkbox" name="default_newsletter" value="1" @checked(data_get($profile->channel_defaults, 'newsletter'))> Default newsletter</label>
                                    <label><input type="checkbox" name="default_landing_page" value="1" @checked(data_get($profile->channel_defaults, 'landing_page'))> Default landing page</label>
                                </div>
                                <button class="rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">Save profile</button>
                            </form>

                            <form method="POST" action="{{ route('app.brand.writer-profiles.analyze', $profile) }}" class="mt-4 space-y-3 border-t border-border pt-4">
                                @csrf
                                <p class="text-xs text-textSecondary">Source texts are used for style analysis. Disable source storage when texts should not be retained after analysis.</p>
                                <textarea name="source_texts" rows="5" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" placeholder="Paste one or more sample texts. Separate samples with a line containing ---"></textarea>
                                <select name="content_ids[]" multiple class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                    @foreach ($contents as $content)
                                        <option value="{{ $content->id }}">{{ $content->title }}</option>
                                    @endforeach
                                </select>
                                <button class="rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">Run analysis</button>
                            </form>
                        </details>

                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($profile->status !== 'active')
                                <form method="POST" action="{{ route('app.brand.writer-profiles.activate', $profile) }}">@csrf<button class="rounded-md border border-border px-3 py-1.5 text-xs text-textPrimary hover:bg-surfaceSubtle">Activate</button></form>
                            @endif
                            @if ($profile->status !== 'archived')
                                <form method="POST" action="{{ route('app.brand.writer-profiles.archive', $profile) }}">@csrf<button class="rounded-md border border-border px-3 py-1.5 text-xs text-textPrimary hover:bg-surfaceSubtle">Archive</button></form>
                            @endif
                        </div>
                    @endcan
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-border bg-surface p-6 text-sm text-textSecondary">No writer profiles yet.</div>
            @endforelse
        </div>

        @can('manage-organization')
            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-lg font-semibold text-textPrimary">Create writer profile</h2>
                <p class="mt-1 text-sm text-textSecondary">Paste examples or select existing content. Argusly abstracts tone, structure, vocabulary, and rules.</p>
                <form method="POST" action="{{ route('app.brand.writer-profiles.store') }}" class="mt-4 space-y-4">
                    @csrf
                    <input name="name" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Profile name" required>
                    <textarea name="description" rows="2" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Description"></textarea>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <select name="source_type" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
                            <option value="manual">manual</option>
                            <option value="uploaded_texts">uploaded texts</option>
                            <option value="content_history">content history</option>
                            <option value="mixed">mixed</option>
                        </select>
                        <select name="profile_scope" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
                            <option value="author">author</option>
                            <option value="brand">brand</option>
                            <option value="company">company</option>
                            <option value="campaign">campaign</option>
                        </select>
                    </div>
                    <select name="brand_id" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                        <option value="">Link brand voice later</option>
                        @foreach ($brandVoices as $voice)
                            <option value="{{ $voice->id }}">{{ $voice->name }}</option>
                        @endforeach
                    </select>
                    <div class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">Teksten worden gebruikt voor stijlanalyse. Zet bronopslag alleen aan als je deze teksten permanent bij het profiel wilt bewaren.</div>
                    <textarea name="source_texts" rows="8" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Paste source texts. Separate samples with a line containing ---"></textarea>
                    <select name="content_ids[]" multiple class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                        @foreach ($contents as $content)
                            <option value="{{ $content->id }}">{{ $content->title }}</option>
                        @endforeach
                    </select>
                    <label class="inline-flex items-center gap-2 text-xs text-textSecondary"><input type="checkbox" name="retain_source_text" value="1"> Store source text after analysis</label>
                    <button class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-transparent bg-textPrimary px-4 py-3 text-sm font-medium text-white">
                        <i data-lucide="pen-tool" class="h-4 w-4" aria-hidden="true"></i>
                        <span>Create profile</span>
                    </button>
                </form>
            </div>
        @endcan
    </div>
@endsection
