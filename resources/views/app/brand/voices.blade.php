@extends('layouts.app', ['title' => app()->getLocale() === 'nl' ? __('app.runtime.Brand Voices') : 'Brand Voices'])

@php
    $rt = function (string $value, array $replace = []): string {
        $key = 'app.runtime.'.$value;
        $translated = __($key, $replace);

        return $translated === $key ? strtr($value, collect($replace)->mapWithKeys(fn ($replacement, $placeholder) => [':'.$placeholder => $replacement])->all()) : $translated;
    };
    $textProviders = collect((array) config('llm.capabilities', []))
        ->filter(fn ($caps) => in_array('text', (array) $caps, true))
        ->keys()
        ->values()
        ->all();
    $providerLabels = [
        'openai' => 'OpenAI',
        'anthropic' => 'Claude (Anthropic)',
        'gemini' => 'Gemini (Google)',
        'mistral' => 'Mistral',
    ];
@endphp

@section('pageHeader')
    <x-page-header :title="$rt('Brand Voices')" :eyebrow="$rt('Brand')">
        <x-slot:description>{{ $rt('Generate distinct voice cards with AI, then refine descriptions, style guidance and examples manually.') }}</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    @include('app.brand.partials.tabs')

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <div class="space-y-6">
        @include('app.brand.partials.ai-entry', [
            'section' => 'brand_voices',
            'manualTarget' => 'manual',
            'latestBrandContext' => $latestBrandContext,
            'title' => $rt('Generate brand voices with AI'),
            'description' => $rt('Create 2 to 4 reusable voices with tone, do and don’t guidance, and example copy for content generation.'),
            'rt' => $rt,
        ])

        <div id="manual" class="rounded-lg border border-border bg-surface p-5">
            <div class="mb-5 flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-textPrimary">{{ $rt('Voice library') }}</h2>
                    <p class="mt-1 text-sm text-textSecondary">{{ $rt('Set a default voice and keep alternative voices for different content angles.') }}</p>
                </div>
                <span class="text-xs text-textSecondary">{{ $rt('Workspace') }}: {{ $workspace?->display_name ?? 'n/a' }}</span>
            </div>

            @php($canManageBrandVoices = auth()->user()?->can('manage-organization'))

            <div class="grid gap-4 2xl:grid-cols-2">
                @forelse ($brandVoices as $voice)
                    <div class="rounded-lg border border-border bg-background p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <h3 class="text-base font-semibold text-textPrimary">{{ $voice->name }}</h3>
                                    @if ($voice->is_default)
                                        <span class="rounded-full bg-emerald-500/10 px-2 py-1 text-xs font-medium text-emerald-700">{{ $rt('Default') }}</span>
                                    @endif
                                </div>
                                <p class="mt-1 text-sm text-textSecondary">{{ $voice->tone_of_voice ?: $voice->default_tone ?: $rt('No tone defined yet.') }}</p>
                            </div>
                            @if ($canManageBrandVoices)
                                <div class="flex flex-wrap items-center gap-2">
                                    @if (! $voice->is_default)
                                        <form method="POST" action="{{ route('app.brand.voices.default', $voice) }}">
                                            @csrf
                                            <button class="inline-flex items-center justify-center rounded-md border border-border px-3 py-1.5 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">{{ $rt('Set default') }}</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('app.brand.voices.delete', $voice) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="inline-flex items-center justify-center rounded-md border border-border px-3 py-1.5 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">{{ $rt('Delete') }}</button>
                                    </form>
                                </div>
                            @endif
                        </div>

                        @if ($canManageBrandVoices)
                            <form method="POST" action="{{ route('app.brand.voices.update', $voice) }}" class="mt-4 space-y-4">
                                @csrf
                                <div class="grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <label class="text-xs text-textSecondary">{{ $rt('Voice name') }}</label>
                                        <input name="name" value="{{ $voice->name }}" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" required>
                                    </div>
                                    <div>
                                        <label class="text-xs text-textSecondary">{{ $rt('Default language') }}</label>
                                        <input name="default_language" value="{{ $voice->default_language }}" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" required>
                                    </div>
                                </div>

                                <div class="grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <label class="text-xs text-textSecondary">{{ $rt('Tone of voice') }}</label>
                                        <textarea id="voice-tone-{{ $voice->id }}" name="tone_of_voice" rows="2" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">{{ $voice->tone_of_voice ?: $voice->default_tone }}</textarea>
                                        <x-app.ai-field-actions target="#voice-tone-{{ $voice->id }}" context="{{ $rt('Brand voice tone of voice') }}" />
                                    </div>
                                    <div>
                                        <label class="text-xs text-textSecondary">{{ $rt('Writing style / description') }}</label>
                                        <textarea id="voice-style-{{ $voice->id }}" name="writing_style" rows="2" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">{{ $voice->writing_style ?: $voice->style_guide }}</textarea>
                                        <x-app.ai-field-actions target="#voice-style-{{ $voice->id }}" context="{{ $rt('Brand voice writing style') }}" />
                                    </div>
                                </div>

                                <div class="grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <label class="text-xs text-textSecondary">{{ $rt('Do rules') }}</label>
                                        <textarea id="voice-do-{{ $voice->id }}" name="do_rules" rows="4" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">{{ $voice->do_rules ?: $voice->formatting_rules }}</textarea>
                                        <x-app.ai-field-actions target="#voice-do-{{ $voice->id }}" context="{{ $rt('Brand voice do rules') }}" />
                                    </div>
                                    <div>
                                        <label class="text-xs text-textSecondary">{{ $rt('Don’t rules') }}</label>
                                        <textarea id="voice-dont-{{ $voice->id }}" name="dont_rules" rows="4" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">{{ $voice->dont_rules ?: $voice->disallowed_terminology }}</textarea>
                                        <x-app.ai-field-actions target="#voice-dont-{{ $voice->id }}" context="{{ $rt('Brand voice dont rules') }}" />
                                    </div>
                                </div>

                                <div>
                                    <label class="text-xs text-textSecondary">{{ $rt('Example paragraph') }}</label>
                                    <textarea id="voice-example-{{ $voice->id }}" name="example_paragraph" rows="4" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">{{ $voice->example_paragraph }}</textarea>
                                    <x-app.ai-field-actions target="#voice-example-{{ $voice->id }}" context="{{ $rt('Brand voice example paragraph') }}" />
                                </div>

                                <div class="grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <label class="text-xs text-textSecondary">{{ $rt('Preferred terminology') }}</label>
                                        <textarea name="preferred_terminology" rows="3" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">{{ $voice->preferred_terminology }}</textarea>
                                    </div>
                                    <div>
                                        <label class="text-xs text-textSecondary">{{ $rt('Disallowed terminology') }}</label>
                                        <textarea name="disallowed_terminology" rows="3" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">{{ $voice->disallowed_terminology }}</textarea>
                                    </div>
                                </div>

                                <div class="grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <label class="text-xs text-textSecondary">{{ $rt('AI provider override') }}</label>
                                        <select name="ai_provider_override" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                            <option value="">{{ $rt('Default provider') }}</option>
                                            @foreach ($textProviders as $provider)
                                                <option value="{{ $provider }}" @selected($voice->ai_provider_override === $provider)>{{ $providerLabels[$provider] ?? \Illuminate\Support\Str::headline($provider) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-xs text-textSecondary">{{ $rt('AI model override') }}</label>
                                        <input name="ai_model_override" value="{{ $voice->ai_model_override }}" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                    </div>
                                </div>

                                <label class="inline-flex items-center gap-2 text-xs text-textSecondary">
                                    <input type="checkbox" name="is_default" value="1" @checked($voice->is_default)>
                                    {{ $rt('Set as default voice') }}
                                </label>

                                <button class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">{{ $rt('Update voice') }}</button>
                            </form>
                        @else
                            <div class="mt-4 grid gap-3 text-sm text-textSecondary">
                                <div><strong class="text-textPrimary">{{ $rt('Writing style') }}:</strong> {{ $voice->writing_style ?: $voice->style_guide ?: $rt('Not set.') }}</div>
                                <div><strong class="text-textPrimary">{{ $rt('Example') }}:</strong> {{ $voice->example_paragraph ?: $rt('Not set.') }}</div>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-border bg-background px-4 py-6 text-sm text-textSecondary">
                        {{ $rt('No brand voices yet. Use Generate with AI to create the first voice set, or add one manually below.') }}
                    </div>
                @endforelse
            </div>

            @can('manage-organization')
                <div class="mt-6 rounded-lg border border-border bg-background p-5">
                    <h3 class="text-sm font-semibold text-textPrimary">{{ $rt('Add brand voice manually') }}</h3>
                    <form method="POST" action="{{ route('app.brand.voices.store') }}" class="mt-4 space-y-4">
                        @csrf
                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="text-xs text-textSecondary">{{ $rt('Voice name') }}</label>
                                <input name="name" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" required>
                            </div>
                            <div>
                                <label class="text-xs text-textSecondary">{{ $rt('Default language') }}</label>
                                <input name="default_language" value="en" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" required>
                            </div>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="text-xs text-textSecondary">{{ $rt('Tone of voice') }}</label>
                                <textarea id="new-voice-tone" name="tone_of_voice" rows="2" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm"></textarea>
                                <x-app.ai-field-actions target="#new-voice-tone" context="{{ $rt('Brand voice tone of voice') }}" />
                            </div>
                            <div>
                                <label class="text-xs text-textSecondary">{{ $rt('Writing style / description') }}</label>
                                <textarea id="new-voice-style" name="writing_style" rows="2" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm"></textarea>
                                <x-app.ai-field-actions target="#new-voice-style" context="{{ $rt('Brand voice writing style') }}" />
                            </div>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="text-xs text-textSecondary">{{ $rt('Do rules') }}</label>
                                <textarea id="new-voice-do" name="do_rules" rows="4" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm"></textarea>
                                <x-app.ai-field-actions target="#new-voice-do" context="{{ $rt('Brand voice do rules') }}" />
                            </div>
                            <div>
                                <label class="text-xs text-textSecondary">{{ $rt('Don’t rules') }}</label>
                                <textarea id="new-voice-dont" name="dont_rules" rows="4" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm"></textarea>
                                <x-app.ai-field-actions target="#new-voice-dont" context="{{ $rt('Brand voice dont rules') }}" />
                            </div>
                        </div>

                        <div>
                            <label class="text-xs text-textSecondary">{{ $rt('Example paragraph') }}</label>
                            <textarea id="new-voice-example" name="example_paragraph" rows="4" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm"></textarea>
                            <x-app.ai-field-actions target="#new-voice-example" context="{{ $rt('Brand voice example paragraph') }}" />
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="text-xs text-textSecondary">{{ $rt('Preferred terminology') }}</label>
                                <textarea name="preferred_terminology" rows="3" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm"></textarea>
                            </div>
                            <div>
                                <label class="text-xs text-textSecondary">{{ $rt('Disallowed terminology') }}</label>
                                <textarea name="disallowed_terminology" rows="3" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm"></textarea>
                            </div>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <label class="text-xs text-textSecondary">{{ $rt('AI provider override') }}</label>
                                <select name="ai_provider_override" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                    <option value="">{{ $rt('Default provider') }}</option>
                                    @foreach ($textProviders as $provider)
                                        <option value="{{ $provider }}">{{ $providerLabels[$provider] ?? \Illuminate\Support\Str::headline($provider) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="text-xs text-textSecondary">{{ $rt('AI model override') }}</label>
                                <input name="ai_model_override" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                            </div>
                        </div>

                        <label class="inline-flex items-center gap-2 text-xs text-textSecondary">
                            <input type="checkbox" name="is_default" value="1">
                            {{ $rt('Set as default voice') }}
                        </label>

                        <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">{{ $rt('Add brand voice') }}</button>
                    </form>
                </div>
            @endcan
        </div>
    </div>
@endsection
