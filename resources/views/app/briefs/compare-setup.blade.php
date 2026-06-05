@extends('layouts.app', ['title' => 'Compare AI Drafts'])

@section('content')
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3 mb-1">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br from-primary/20 to-primary/10 text-primary">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                </div>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Compare AI Drafts</h1>
                    <p class="text-sm text-textSecondary">Configure a multi-model comparison for this content item.</p>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('app.content.workspace.show', $brief) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm hover:bg-surfaceSubtle transition-colors">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                Back to content workspace
            </a>
        </div>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->has('draft_compare'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('draft_compare') }}</div>
    @endif

    @php
        $compareEnabled = (bool) data_get($draftCompareCapabilities ?? [], 'enabled', true);
        $maxModels = (int) data_get($draftCompareCapabilities ?? [], 'max_models', 6);
        $scoringEnabled = (bool) data_get($draftCompareCapabilities ?? [], 'scoring_enabled', true);
        $premiumModelsEnabled = (bool) data_get($draftCompareCapabilities ?? [], 'premium_models_enabled', true);
        $hybridEnabled = (bool) data_get($draftCompareCapabilities ?? [], 'hybrid_enabled', true);
    @endphp

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Left column: Brief Summary --}}
        <div class="space-y-4">
            <div class="rounded-lg border border-border bg-surface p-5">
                <div class="flex items-center gap-2 mb-4">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold text-textPrimary">Content context</h2>
                        <p class="text-xs text-textSecondary">Brief details used for this comparison run</p>
                    </div>
                </div>
                <div class="rounded-lg bg-gradient-to-br from-background to-surfaceSubtle border border-border/50 p-4 mb-4">
                    <h3 class="text-base font-semibold text-textPrimary leading-tight">{{ $brief->title }}</h3>
                    @if ($brief->primary_keyword)
                        <div class="mt-2 inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-1 text-xs font-medium text-primary">
                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" /></svg>
                            {{ $brief->primary_keyword }}
                        </div>
                    @endif
                </div>
                <dl class="space-y-2.5 text-sm">
                    <div class="flex items-center justify-between gap-2 py-1.5 border-b border-border/30">
                        <dt class="flex items-center gap-2 text-textSecondary">
                            <svg class="h-3.5 w-3.5 text-textFaint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                            Audience
                        </dt>
                        <dd class="text-textPrimary font-medium text-right max-w-[60%] truncate">{{ $brief->target_audience ?: $brief->audience ?: 'General' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2 py-1.5 border-b border-border/30">
                        <dt class="flex items-center gap-2 text-textSecondary">
                            <svg class="h-3.5 w-3.5 text-textFaint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z" /></svg>
                            Content type
                        </dt>
                        <dd class="text-textPrimary font-medium text-right">{{ \Illuminate\Support\Str::headline($brief->content_type ?: 'blog') }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2 py-1.5 border-b border-border/30">
                        <dt class="flex items-center gap-2 text-textSecondary">
                            <svg class="h-3.5 w-3.5 text-textFaint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129" /></svg>
                            Language
                        </dt>
                        <dd class="text-textPrimary font-medium text-right">{{ strtoupper($brief->language ?: 'en') }}</dd>
                    </div>
                    @if ($brief->tone_of_voice)
                        <div class="flex items-center justify-between gap-2 py-1.5">
                            <dt class="flex items-center gap-2 text-textSecondary">
                                <svg class="h-3.5 w-3.5 text-textFaint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" /></svg>
                                Tone
                            </dt>
                            <dd class="text-textPrimary font-medium text-right max-w-[60%] truncate">{{ \Illuminate\Support\Str::limit($brief->tone_of_voice, 25) }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- What happens next --}}
            <div class="rounded-lg border border-border bg-surface p-5">
                <div class="flex items-center gap-2 mb-4">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" /></svg>
                    </div>
                    <h3 class="text-sm font-semibold text-textPrimary">How it works</h3>
                </div>
                <ol class="space-y-3 text-sm">
                    <li class="flex items-start gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary text-[10px] font-bold text-white">1</span>
                        <div>
                            <p class="font-medium text-textPrimary">Select AI models</p>
                            <p class="text-xs text-textSecondary mt-0.5">Choose which models to compare side-by-side</p>
                        </div>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary text-[10px] font-bold text-white">2</span>
                        <div>
                            <p class="font-medium text-textPrimary">Generate in parallel</p>
                            <p class="text-xs text-textSecondary mt-0.5">All models generate drafts simultaneously</p>
                        </div>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary text-[10px] font-bold text-white">3</span>
                        <div>
                            <p class="font-medium text-textPrimary">Compare and score</p>
                            <p class="text-xs text-textSecondary mt-0.5">Review quality, SEO, and brand voice scores</p>
                        </div>
                    </li>
                    @if ($hybridEnabled)
                        <li class="flex items-start gap-3">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary to-violet-600 text-[10px] font-bold text-white">4</span>
                            <div>
                                <p class="font-medium text-textPrimary">Create hybrid draft</p>
                                <p class="text-xs text-textSecondary mt-0.5">Combine the best parts into one final version</p>
                            </div>
                        </li>
                    @endif
                </ol>
            </div>
        </div>

        {{-- Right columns: Compare Setup --}}
        <div class="lg:col-span-2 space-y-4">
            <div class="rounded-lg border border-border bg-surface p-5">
                <div class="flex items-center justify-between gap-4 mb-5">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br from-primary/20 to-primary/10 text-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                        </div>
                        <div>
                            <h2 class="text-base font-semibold text-textPrimary">Configure comparison</h2>
                            <p class="text-sm text-textSecondary">Select AI models and generation settings</p>
                        </div>
                    </div>
                </div>

                @if ($compareEnabled && !empty($draftCompareModes) && !empty($draftCompareModelOptions))
                    <form
                        method="POST"
                        action="{{ route('app.content.workspace.compare.store', $brief) }}"
                        class="space-y-5"
                        data-draft-compare-setup
                        data-estimate-endpoint="{{ route('app.content.workspace.compare.estimate', $brief) }}"
                        data-max-models="{{ $maxModels }}"
                    >
                        @csrf
                        <input type="hidden" name="compare_scope" value="full_draft">

                        {{-- Generation settings --}}
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-textSecondary">Compare mode</label>
                                <select name="mode" class="w-full rounded-lg border border-border bg-background px-3 py-2.5 text-sm focus:border-primary focus:ring-1 focus:ring-primary/20 transition-colors" data-compare-mode>
                                    @foreach (($draftCompareModes ?? []) as $modeKey => $modeLabel)
                                        <option value="{{ $modeKey }}" @selected(old('mode', array_key_first($draftCompareModes ?? ['compare_two' => 'Compare 2 models'])) === $modeKey)>{{ $modeLabel }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-medium text-textSecondary">Output length</label>
                                <select name="requested_max_output_tokens" class="w-full rounded-lg border border-border bg-background px-3 py-2.5 text-sm focus:border-primary focus:ring-1 focus:ring-primary/20 transition-colors" data-compare-tokens>
                                    <option value="{{ $outputTokenOptions['standard'] }}" @selected((int) old('requested_max_output_tokens', $outputTokenOptions['standard']) === (int) $outputTokenOptions['standard'])>
                                        Standard (~{{ number_format((int) $outputTokenOptions['standard']) }} tokens)
                                    </option>
                                    <option value="{{ $outputTokenOptions['long'] }}" @selected((int) old('requested_max_output_tokens', $outputTokenOptions['standard']) === (int) $outputTokenOptions['long'])>
                                        Long (~{{ number_format((int) $outputTokenOptions['long']) }} tokens)
                                    </option>
                                    @if ((int) $outputTokenOptions['max'] !== (int) $outputTokenOptions['long'])
                                        <option value="{{ $outputTokenOptions['max'] }}" @selected((int) old('requested_max_output_tokens', $outputTokenOptions['standard']) === (int) $outputTokenOptions['max'])>
                                            Extended (~{{ number_format((int) $outputTokenOptions['max']) }} tokens)
                                        </option>
                                    @endif
                                </select>
                            </div>
                        </div>

                        {{-- Model selection with cards --}}
                        <div>
                            <div class="mb-3 flex items-center justify-between">
                                <label class="text-sm font-medium text-textPrimary">Model selection</label>
                                <span class="text-xs text-textSecondary px-2 py-1 rounded-full bg-background border border-border">Up to {{ $maxModels }} models</span>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2 max-h-80 overflow-y-auto rounded-lg border border-border bg-gradient-to-br from-background to-surfaceSubtle p-4">
                                @php
                                    $modelDescriptions = [
                                        'anthropic' => 'Strong natural writing and creative structure',
                                        'openai' => 'Excellent instruction following and SEO formatting',
                                        'google' => 'Good for alternative angles and variation',
                                        'mistral' => 'Fast generation with competitive quality',
                                        'deepseek' => 'Cost-effective with strong reasoning',
                                    ];
                                    $providerIcons = [
                                        'anthropic' => 'M19.5 9.5l-6-6h-3l6 6h-3l-6-6h-3l6 6h-3l-6 6h3l6-6h3l6 6h3z',
                                        'openai' => 'M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729z',
                                        'google' => 'M12 0C5.372 0 0 5.372 0 12s5.372 12 12 12c6.627 0 12-5.372 12-12S18.627 0 12 0z',
                                        'mistral' => 'M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
                                        'deepseek' => 'M9.5 2A7.5 7.5 0 0 0 2 9.5v5A7.5 7.5 0 0 0 9.5 22h5a7.5 7.5 0 0 0 7.5-7.5v-5A7.5 7.5 0 0 0 14.5 2h-5z',
                                    ];
                                    $providerColors = [
                                        'anthropic' => 'bg-orange-500/10 text-orange-600 border-orange-500/20',
                                        'openai' => 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20',
                                        'google' => 'bg-blue-500/10 text-blue-600 border-blue-500/20',
                                        'mistral' => 'bg-violet-500/10 text-violet-600 border-violet-500/20',
                                        'deepseek' => 'bg-cyan-500/10 text-cyan-600 border-cyan-500/20',
                                    ];
                                @endphp
                                @forelse (($draftCompareModelOptions ?? []) as $option)
                                    @php
                                        $optionKey = (string) ($option['key'] ?? '');
                                        $providerKey = strtolower((string) ($option['provider'] ?? 'openai'));
                                        $modelDescription = $modelDescriptions[$providerKey] ?? 'AI-powered content generation';
                                        $providerColor = $providerColors[$providerKey] ?? 'bg-gray-500/10 text-gray-600 border-gray-500/20';
                                        $isChecked = in_array($optionKey, old('model_keys', $draftCompareDefaultModelKeys ?? []), true);
                                    @endphp
                                    <label class="group relative flex cursor-pointer rounded-lg border-2 border-border bg-surface p-4 transition-all hover:border-primary/40 hover:shadow-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:checked]:shadow-md has-[:checked]:shadow-primary/10">
                                        <input
                                            type="checkbox"
                                            name="model_keys[]"
                                            value="{{ $optionKey }}"
                                            class="sr-only"
                                            data-compare-model
                                            @checked($isChecked)
                                        >
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 mb-1.5">
                                                <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $providerColor }}">
                                                    {{ \Illuminate\Support\Str::headline($providerKey) }}
                                                </span>
                                                @if ((bool) ($option['is_premium'] ?? false))
                                                    <span class="shrink-0 inline-flex items-center gap-1 rounded-md border border-amber-500/30 bg-gradient-to-r from-amber-500/10 to-amber-500/5 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">
                                                        <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                                        Premium
                                                    </span>
                                                @endif
                                            </div>
                                            <p class="text-sm font-semibold text-textPrimary mb-1">{{ (string) ($option['label'] ?? $optionKey) }}</p>
                                            <p class="text-xs text-textSecondary leading-relaxed">{{ $modelDescription }}</p>
                                        </div>
                                        <div class="ml-3 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 border-border bg-background group-has-[:checked]:border-primary group-has-[:checked]:bg-primary transition-all duration-200">
                                            <svg class="h-3.5 w-3.5 text-white opacity-0 group-has-[:checked]:opacity-100 transition-opacity duration-200" fill="currentColor" viewBox="0 0 12 12">
                                                <path d="M3.707 5.293a1 1 0 00-1.414 1.414l2.5 2.5a1 1 0 001.414 0l4.5-4.5a1 1 0 00-1.414-1.414L5.5 7.086 3.707 5.293z"/>
                                            </svg>
                                        </div>
                                    </label>
                                @empty
                                    <div class="col-span-2 text-center py-8">
                                        <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-background">
                                            <svg class="h-6 w-6 text-textSecondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                                        </div>
                                        <p class="text-sm font-medium text-textPrimary">No AI models available</p>
                                        <p class="text-xs text-textSecondary mt-1">Contact support if this is unexpected.</p>
                                    </div>
                                @endforelse
                            </div>
                        </div>

                        {{-- Estimate summary --}}
                        <div class="rounded-lg border-2 border-primary/20 bg-gradient-to-br from-primary/5 via-surface to-background p-5">
                            <div class="flex items-center gap-2 mb-4">
                                <svg class="h-4 w-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                                <span class="text-sm font-semibold text-textPrimary">Cost estimate</span>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-3 mb-4">
                                <div class="rounded-lg bg-background/80 border border-border/50 p-3 text-center">
                                    <p class="text-[11px] uppercase tracking-wide text-textSecondary mb-1">Models</p>
                                    <p class="text-2xl font-bold text-textPrimary" data-selected-model-summary>0</p>
                                </div>
                                <div class="rounded-lg bg-background/80 border border-border/50 p-3 text-center">
                                    <p class="text-[11px] uppercase tracking-wide text-textSecondary mb-1">Est. credits</p>
                                    <p class="text-2xl font-bold text-primary"><span data-estimate-credits>0</span></p>
                                </div>
                                <div class="rounded-lg bg-background/80 border border-border/50 p-3 text-center">
                                    <p class="text-[11px] uppercase tracking-wide text-textSecondary mb-1">Available</p>
                                    <p class="text-2xl font-bold text-textPrimary" data-available-credits>{{ (int) ($availableCredits ?? 0) }}</p>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-x-4 gap-y-2 text-xs" data-compare-hint>
                                @if ($scoringEnabled)
                                    <span class="inline-flex items-center gap-1.5 text-emerald-700">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                        Quality scoring included
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 text-amber-700">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                                        Scoring not on plan
                                    </span>
                                @endif
                                @if ($hybridEnabled)
                                    <span class="inline-flex items-center gap-1.5 text-emerald-700">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                        Hybrid draft available
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 text-amber-700">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                                        Hybrid not on plan
                                    </span>
                                @endif
                                @unless ($premiumModelsEnabled)
                                    <span class="inline-flex items-center gap-1.5 text-amber-700">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                                        Premium models locked
                                    </span>
                                @endunless
                            </div>
                            <div class="hidden mt-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2" data-compare-error-container>
                                <p class="text-sm text-rose-700 font-medium flex items-center gap-2">
                                    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                    <span data-compare-error></span>
                                </p>
                            </div>
                        </div>

                        <button class="w-full rounded-lg bg-gradient-to-r from-primary to-primary/90 px-5 py-4 text-base font-semibold text-white shadow-lg shadow-primary/20 hover:from-primary/95 hover:to-primary/85 hover:shadow-xl hover:shadow-primary/30 focus:ring-2 focus:ring-primary/30 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed disabled:shadow-none transition-all duration-200" data-compare-submit>
                            <span class="flex items-center justify-center gap-2">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                                Start comparison
                            </span>
                        </button>
                    </form>
                @else
                    <div class="text-center py-10">
                        <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-lg bg-gradient-to-br from-amber-500/20 to-amber-500/10">
                            <svg class="h-8 w-8 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                        <h3 class="text-lg font-semibold text-textPrimary mb-2">
                            @if (! $compareEnabled)
                                Unlock AI Draft Comparison
                            @else
                                No Models Available
                            @endif
                        </h3>
                        <p class="text-sm text-textSecondary mb-6 max-w-md mx-auto leading-relaxed">
                            @if (! $compareEnabled)
                                {{ (string) data_get($draftCompareCapabilities ?? [], 'blocked_reason', 'Compare multiple AI models side-by-side to find the best output for your content. See which model produces the highest quality, best SEO, and strongest brand voice match.') }}
                            @else
                                No AI models are currently available for your plan. This might be a temporary issue or a plan limitation.
                            @endif
                        </p>
                        <a href="{{ route('app.billing.index', ['tab' => 'subscriptions']) }}" class="inline-flex items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-primary to-primary/90 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-primary/20 hover:shadow-xl hover:shadow-primary/30 transition-all">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                            View upgrade options
                        </a>
                    </div>
                @endif
            </div>

            {{-- Recent compare runs --}}
            @if ($brief->draftComparisons->isNotEmpty())
                <div class="rounded-lg border border-border bg-surface p-5">
                    <div class="flex items-center justify-between gap-2 mb-4">
                        <div class="flex items-center gap-2">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-background text-textSecondary">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            </div>
                    <h3 class="text-sm font-semibold text-textPrimary">Recent compare runs</h3>
                        </div>
                        <span class="text-xs text-textSecondary">{{ $brief->draftComparisons->count() }} total</span>
                    </div>
                    <div class="space-y-2">
                        @foreach ($brief->draftComparisons->take(5) as $comparison)
                            @php
                                $runStatusConfig = match ((string) $comparison->status) {
                                    'completed' => ['class' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700', 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'label' => 'Completed'],
                                    'partially_failed' => ['class' => 'border-amber-500/30 bg-amber-500/10 text-amber-700', 'icon' => 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z', 'label' => 'Partial'],
                                    'failed', 'cancelled' => ['class' => 'border-rose-500/30 bg-rose-500/10 text-rose-700', 'icon' => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z', 'label' => 'Failed'],
                                    'processing' => ['class' => 'border-sky-500/30 bg-sky-500/10 text-sky-700', 'icon' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15', 'label' => 'Running'],
                                    'queued', 'pending' => ['class' => 'border-amber-500/30 bg-amber-500/10 text-amber-700', 'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'label' => 'Queued'],
                                    default => ['class' => 'border-border bg-background text-textSecondary', 'icon' => 'M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'label' => 'Unknown'],
                                };
                            @endphp
                            <a class="group flex items-center justify-between gap-3 rounded-lg border border-border px-4 py-3 text-sm hover:bg-surfaceSubtle hover:border-primary/30 transition-all" href="{{ route('app.content.workspace.compare.show', [$brief, $comparison]) }}">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-background text-textSecondary group-hover:bg-primary/10 group-hover:text-primary transition-colors">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="font-medium text-textPrimary truncate">
                                            {{ $comparison->items_total }} model{{ $comparison->items_total === 1 ? '' : 's' }} compared
                                        </div>
                                        <div class="text-xs text-textSecondary">
                                            {{ optional($comparison->created_at)->diffForHumans() }}
                                        </div>
                                    </div>
                                </div>
                                <span class="shrink-0 inline-flex items-center gap-1 rounded-full border px-2 py-1 text-[10px] font-medium {{ $runStatusConfig['class'] }}">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $runStatusConfig['icon'] }}" /></svg>
                                    {{ $runStatusConfig['label'] }}
                                </span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    <script>
        (() => {
            const form = document.querySelector('[data-draft-compare-setup]');
            if (!form) return;

            const modeSelect = form.querySelector('[data-compare-mode]');
            const tokensSelect = form.querySelector('[data-compare-tokens]');
            const modelInputs = Array.from(form.querySelectorAll('[data-compare-model]'));
            const summaryLabel = form.querySelector('[data-selected-model-summary]');
            const creditsLabel = form.querySelector('[data-estimate-credits]');
            const availableLabel = form.querySelector('[data-available-credits]');
            const errorContainer = form.querySelector('[data-compare-error-container]');
            const errorLabel = form.querySelector('[data-compare-error]');
            const submitButton = form.querySelector('[data-compare-submit]');
            const endpoint = form.getAttribute('data-estimate-endpoint');
            const csrf = form.querySelector('input[name="_token"]')?.value || '';

            const selectedKeys = () => modelInputs.filter((input) => input.checked).map((input) => input.value);

            const maxModels = Math.max(1, Number(form.getAttribute('data-max-models') || 6));

            const modeConstraints = () => {
                const mode = modeSelect?.value || 'compare_two';
                if (mode === 'compare_two') return { min: 2, max: Math.min(2, maxModels) };
                return { min: 2, max: maxModels };
            };

            const updateModelSummary = () => {
                const selected = selectedKeys();
                if (summaryLabel) {
                    summaryLabel.textContent = String(selected.length);
                }
            };

            const setError = (message = '') => {
                const hasError = String(message).trim() !== '';
                if (errorLabel) {
                    errorLabel.textContent = hasError ? message : '';
                }
                if (errorContainer) {
                    errorContainer.classList.toggle('hidden', !hasError);
                }
                if (submitButton) {
                    submitButton.disabled = hasError;
                }
            };

            let requestTimer = null;
            let requestCounter = 0;

            const updateEstimate = () => {
                updateModelSummary();

                const selected = selectedKeys();
                const { min, max } = modeConstraints();

                if (selected.length < min) {
                    setError(`Select at least ${min} model${min === 1 ? '' : 's'} for this mode.`);
                    if (creditsLabel) creditsLabel.textContent = '0';
                    return;
                }

                if (selected.length > max) {
                    setError(`Maximum ${max} model${max === 1 ? '' : 's'} allowed for this mode.`);
                    if (creditsLabel) creditsLabel.textContent = '0';
                    return;
                }

                setError('');

                if (!endpoint || !csrf) {
                    return;
                }

                const currentRequest = ++requestCounter;
                if (requestTimer) {
                    window.clearTimeout(requestTimer);
                }

                requestTimer = window.setTimeout(() => {
                    fetch(endpoint, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({
                            mode: modeSelect?.value || 'compare_two',
                            model_keys: selected,
                            requested_max_output_tokens: Number(tokensSelect?.value || 0) || null,
                            compare_scope: form.querySelector('input[name="compare_scope"]')?.value || 'full_draft',
                        }),
                    })
                        .then(async (response) => {
                            const payload = await response.json().catch(() => ({}));
                            if (!response.ok) {
                                throw payload;
                            }
                            return payload;
                        })
                        .then((payload) => {
                            if (currentRequest !== requestCounter) return;

                            const estimateCredits = Number(payload?.data?.estimated_credit_cost || 0);
                            const availableCredits = Number(payload?.available_credits || 0);

                            if (creditsLabel) creditsLabel.textContent = String(estimateCredits);
                            if (availableLabel) availableLabel.textContent = String(availableCredits);

                            if (estimateCredits > 0 && availableCredits < estimateCredits) {
                                setError(`Insufficient credits: need ${estimateCredits}, have ${availableCredits}.`);
                            } else {
                                setError('');
                            }
                        })
                        .catch((errorPayload) => {
                            if (currentRequest !== requestCounter) return;

                            const firstError = errorPayload?.errors
                                ? Object.values(errorPayload.errors).flat()[0]
                                : null;

                            setError(firstError || 'Could not estimate credits. Try submitting to validate.');
                        });
                }, 220);
            };

            modeSelect?.addEventListener('change', updateEstimate);
            tokensSelect?.addEventListener('change', updateEstimate);
            modelInputs.forEach((input) => input.addEventListener('change', updateEstimate));

            updateEstimate();
        })();
    </script>
@endsection
