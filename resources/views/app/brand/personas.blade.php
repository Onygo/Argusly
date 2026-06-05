@extends('layouts.app', ['title' => 'Buyer Personas'])

@section('content')
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <nav class="mb-2 text-sm text-textSecondary">
                <span>Brand</span>
                <span class="mx-1">/</span>
                <span class="text-textPrimary">Buyer Personas</span>
            </nav>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Buyer Personas</h1>
            <p class="mt-1 text-textSecondary">Generate audience personas with AI, then refine goals, objections and content preferences in a reusable workspace library.</p>
        </div>
        <a href="{{ route('app.workspace-intelligence.index') }}" class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
            Workspace Intelligence
        </a>
    </div>

    @include('app.brand.partials.tabs')

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <div class="space-y-6">
        @include('app.brand.partials.ai-entry', [
            'section' => 'buyer_personas',
            'manualTarget' => 'manual',
            'latestBrandContext' => $latestBrandContext,
            'title' => 'Generate buyer personas with AI',
            'description' => 'Create audience segments from brand context, then generate only missing personas or add another angle with AI.',
        ])

        <div class="rounded-lg border border-border bg-surface p-5">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-textPrimary">Persona library</h2>
                    <p class="mt-1 text-sm text-textSecondary">Approved personas can be reused during content generation and SEO planning.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3 text-xs text-textSecondary">
                    <span>{{ $personas->count() }} total</span>
                    <a href="{{ route('app.brand.wizard', ['section' => 'buyer_personas', 'mode' => 'regenerate']) }}" class="text-primary hover:underline">Add another persona with AI</a>
                </div>
            </div>

            <div class="mt-4 space-y-3">
                @forelse ($personas as $persona)
                    <details class="rounded-lg border border-border bg-background p-4" @open($loop->first)>
                        <summary class="cursor-pointer list-none">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="font-medium text-textPrimary">{{ $persona->name }}</div>
                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-textSecondary">
                                        <span class="rounded-full bg-surfaceSubtle px-2 py-1">{{ str_replace('_', ' ', $persona->type) }}</span>
                                        @foreach ((array) data_get($persona->profile_data, 'tags.industry', []) as $tag)
                                            <span class="rounded-full bg-surfaceSubtle px-2 py-1">{{ $tag }}</span>
                                        @endforeach
                                        @foreach ((array) data_get($persona->profile_data, 'tags.seniority', []) as $tag)
                                            <span class="rounded-full bg-surfaceSubtle px-2 py-1">{{ $tag }}</span>
                                        @endforeach
                                    </div>
                                </div>
                                <span class="rounded-full bg-emerald-500/10 px-2 py-1 text-xs font-medium text-emerald-700">{{ ucfirst($persona->status) }}</span>
                            </div>
                        </summary>

                        @can('manage-organization')
                            <form method="POST" action="{{ route('app.brand.personas.update', $persona) }}" class="mt-4 space-y-4">
                                @csrf
                                <div class="grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <label class="text-xs text-textSecondary">Persona name</label>
                                        <input name="name" value="{{ $persona->name }}" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" required>
                                    </div>
                                    <div>
                                        <label class="text-xs text-textSecondary">Persona type</label>
                                        <select name="type" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                            @foreach (['buyer' => 'Buyer', 'user' => 'User', 'influencer' => 'Influencer', 'decision_maker' => 'Decision maker'] as $value => $label)
                                                <option value="{{ $value }}" @selected($persona->type === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div class="grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <label class="text-xs text-textSecondary">Role</label>
                                        <input name="role" value="{{ data_get($persona->profile_data, 'role') }}" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                    </div>
                                    <div>
                                        <label class="text-xs text-textSecondary">Summary</label>
                                        <textarea id="persona-summary-{{ $persona->id }}" name="summary" rows="3" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">{{ data_get($persona->profile_data, 'summary') }}</textarea>
                                        <x-app.ai-field-actions target="#persona-summary-{{ $persona->id }}" context="Buyer persona summary" />
                                    </div>
                                </div>

                                <div class="grid gap-4 xl:grid-cols-2">
                                    <div>
                                        <label class="text-xs text-textSecondary">Goals (one per line)</label>
                                        <textarea id="persona-goals-{{ $persona->id }}" name="goals" rows="4" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">{{ implode("\n", (array) data_get($persona->profile_data, 'goals', [])) }}</textarea>
                                        <x-app.ai-field-actions target="#persona-goals-{{ $persona->id }}" context="Buyer persona goals" />
                                    </div>
                                    <div>
                                        <label class="text-xs text-textSecondary">Pain points (one per line)</label>
                                        <textarea id="persona-pains-{{ $persona->id }}" name="pain_points" rows="4" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">{{ implode("\n", (array) data_get($persona->profile_data, 'pain_points', [])) }}</textarea>
                                        <x-app.ai-field-actions target="#persona-pains-{{ $persona->id }}" context="Buyer persona pain points" />
                                    </div>
                                </div>

                                <div class="grid gap-4 xl:grid-cols-3">
                                    <div>
                                        <label class="text-xs text-textSecondary">Buying triggers</label>
                                        <textarea id="persona-triggers-{{ $persona->id }}" name="buying_triggers" rows="4" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">{{ implode("\n", (array) data_get($persona->profile_data, 'buying_triggers', [])) }}</textarea>
                                        <x-app.ai-field-actions target="#persona-triggers-{{ $persona->id }}" context="Buyer persona buying triggers" />
                                    </div>
                                    <div>
                                        <label class="text-xs text-textSecondary">Objections</label>
                                        <textarea id="persona-objections-{{ $persona->id }}" name="objections" rows="4" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">{{ implode("\n", (array) data_get($persona->profile_data, 'objections', [])) }}</textarea>
                                        <x-app.ai-field-actions target="#persona-objections-{{ $persona->id }}" context="Buyer persona objections" />
                                    </div>
                                    <div>
                                        <label class="text-xs text-textSecondary">Content preferences</label>
                                        <textarea id="persona-content-{{ $persona->id }}" name="content_preferences" rows="4" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">{{ implode("\n", (array) data_get($persona->profile_data, 'content_preferences', [])) }}</textarea>
                                        <x-app.ai-field-actions target="#persona-content-{{ $persona->id }}" context="Buyer persona content preferences" />
                                    </div>
                                </div>

                                <div class="grid gap-4 lg:grid-cols-2">
                                    <div>
                                        <label class="text-xs text-textSecondary">Industry tags</label>
                                        <input name="industry_tags" value="{{ implode(', ', (array) data_get($persona->profile_data, 'tags.industry', [])) }}" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" placeholder="SaaS, fintech">
                                    </div>
                                    <div>
                                        <label class="text-xs text-textSecondary">Seniority tags</label>
                                        <input name="seniority_tags" value="{{ implode(', ', (array) data_get($persona->profile_data, 'tags.seniority', [])) }}" class="mt-1 w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" placeholder="Manager, director">
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-3">
                                    <button class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">Save persona</button>
                                    <a href="{{ route('app.brand.wizard', ['section' => 'buyer_personas', 'mode' => 'regenerate']) }}" class="text-xs text-primary hover:underline">Regenerate this persona with AI</a>
                                </div>
                            </form>
                        @else
                            <div class="mt-4 space-y-2 text-sm text-textSecondary">
                                <p>{{ data_get($persona->profile_data, 'summary') ?: 'No summary available.' }}</p>
                            </div>
                        @endcan
                    </details>
                @empty
                    <div class="rounded-lg border border-dashed border-border bg-background px-4 py-6 text-sm text-textSecondary">
                        No approved personas yet. Generate them with AI or create the first one manually below.
                    </div>
                @endforelse
            </div>
        </div>

        @can('manage-organization')
            <div id="manual" class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-lg font-semibold text-textPrimary">Add persona manually</h2>
                <p class="mt-1 text-sm text-textSecondary">Use this when you already know the ideal customer profile and want to capture it directly.</p>

                <form method="POST" action="{{ route('app.brand.personas.store') }}" class="mt-4 space-y-4">
                    @csrf
                    <div class="grid gap-4 lg:grid-cols-2">
                        <div>
                            <label class="text-xs text-textSecondary">Persona name</label>
                            <input name="name" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" required>
                        </div>
                        <div>
                            <label class="text-xs text-textSecondary">Persona type</label>
                            <select name="type" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                @foreach (['buyer' => 'Buyer', 'user' => 'User', 'influencer' => 'Influencer', 'decision_maker' => 'Decision maker'] as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div>
                            <label class="text-xs text-textSecondary">Role</label>
                            <input name="role" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="text-xs text-textSecondary">Summary</label>
                            <textarea id="new-persona-summary" name="summary" rows="3" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"></textarea>
                            <x-app.ai-field-actions target="#new-persona-summary" context="Buyer persona summary" />
                        </div>
                    </div>

                    <div class="grid gap-4 xl:grid-cols-2">
                        <div>
                            <label class="text-xs text-textSecondary">Goals</label>
                            <textarea id="new-persona-goals" name="goals" rows="4" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"></textarea>
                            <x-app.ai-field-actions target="#new-persona-goals" context="Buyer persona goals" />
                        </div>
                        <div>
                            <label class="text-xs text-textSecondary">Pain points</label>
                            <textarea id="new-persona-pains" name="pain_points" rows="4" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"></textarea>
                            <x-app.ai-field-actions target="#new-persona-pains" context="Buyer persona pain points" />
                        </div>
                    </div>

                    <div class="grid gap-4 xl:grid-cols-3">
                        <div>
                            <label class="text-xs text-textSecondary">Buying triggers</label>
                            <textarea id="new-persona-triggers" name="buying_triggers" rows="4" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"></textarea>
                            <x-app.ai-field-actions target="#new-persona-triggers" context="Buyer persona buying triggers" />
                        </div>
                        <div>
                            <label class="text-xs text-textSecondary">Objections</label>
                            <textarea id="new-persona-objections" name="objections" rows="4" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"></textarea>
                            <x-app.ai-field-actions target="#new-persona-objections" context="Buyer persona objections" />
                        </div>
                        <div>
                            <label class="text-xs text-textSecondary">Content preferences</label>
                            <textarea id="new-persona-content" name="content_preferences" rows="4" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"></textarea>
                            <x-app.ai-field-actions target="#new-persona-content" context="Buyer persona content preferences" />
                        </div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div>
                            <label class="text-xs text-textSecondary">Industry tags</label>
                            <input name="industry_tags" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="SaaS, e-commerce">
                        </div>
                        <div>
                            <label class="text-xs text-textSecondary">Seniority tags</label>
                            <input name="seniority_tags" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Manager, VP">
                        </div>
                    </div>

                    <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Add persona</button>
                </form>
            </div>
        @endcan

        @if ($latestPersonaRun)
            <div class="rounded-lg border border-border bg-surface p-4 text-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <div class="font-medium text-textPrimary">Latest legacy persona run</div>
                        <div class="text-textSecondary">{{ ucfirst(str_replace('_', ' ', $latestPersonaRun->status)) }}</div>
                    </div>
                    <a href="{{ route('app.workspace-intelligence.index') }}" class="text-primary hover:underline">Review proposals</a>
                </div>
            </div>
        @endif
    </div>
@endsection
