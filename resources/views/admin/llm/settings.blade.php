@extends('layouts.admin', ['title' => 'LLM Settings'])

@section('pageHeader')
    <x-page-header title="LLM Settings">
        <x-slot:description>Configure default providers, feature routing, and workspace overrides with full audit history.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-rose-400/30 bg-rose-500/5 px-4 py-3 text-sm text-rose-700">
            <ul class="list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-2">
        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-lg font-semibold mb-3">Global defaults</h2>
            <form method="POST" action="{{ route('admin.llm.settings.global.update') }}" class="grid gap-3 md:grid-cols-2">
                @csrf
                <label class="text-sm">Default text provider
                    <select name="default_text_provider" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                        @foreach($providers as $provider)
                            <option value="{{ $provider }}" @selected($globalSettings['default_text_provider'] === $provider)>{{ $providerOptions[$provider] ?? $provider }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm">Default image provider
                    <select name="default_image_provider" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                        @foreach($providers as $provider)
                            <option value="{{ $provider }}" @selected($globalSettings['default_image_provider'] === $provider)>{{ $providerOptions[$provider] ?? $provider }}</option>
                        @endforeach
                    </select>
                </label>

                @foreach($providers as $provider)
                    <label class="text-sm">Text model ({{ $provider }})
                        <input name="default_text_model_map[{{ $provider }}]" value="{{ data_get($globalSettings, 'default_text_model_map.'.$provider, '') }}" list="llm-models-{{ $provider }}-text" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Select or type model-id">
                    </label>
                @endforeach

                @foreach($providers as $provider)
                    <label class="text-sm">Image model ({{ $provider }})
                        <input name="default_image_model_map[{{ $provider }}]" value="{{ data_get($globalSettings, 'default_image_model_map.'.$provider, '') }}" list="llm-models-{{ $provider }}-image" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Select or type model-id">
                    </label>
                @endforeach

                <label class="text-sm">Timeout seconds
                    <input type="number" min="5" max="900" name="timeout_seconds" value="{{ $globalSettings['timeout_seconds'] }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                </label>
                <label class="text-sm">Retry max
                    <input type="number" min="0" max="10" name="retry_max" value="{{ $globalSettings['retry_max'] }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                </label>
                <label class="text-sm">Retry backoff ms
                    <input type="number" min="50" max="10000" name="retry_backoff_ms" value="{{ $globalSettings['retry_backoff_ms'] }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                </label>

                <div class="md:col-span-2">
                    <button class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Save global settings</button>
                </div>
            </form>
        </div>

        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-lg font-semibold mb-3">Test connection</h2>
            <form method="POST" action="{{ route('admin.llm.settings.test-connection') }}" class="grid gap-3 md:grid-cols-2">
                @csrf
                <label class="text-sm">Provider
                    <select name="provider" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                        @foreach($providers as $provider)
                            <option value="{{ $provider }}">{{ $providerOptions[$provider] ?? $provider }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm">Modality
                    <select name="modality" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                        <option value="text">text</option>
                        <option value="image">image</option>
                    </select>
                </label>
                <label class="text-sm md:col-span-2">Model override (optional)
                    <input name="model" list="llm-models-all" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Select or type model-id">
                </label>
                <div class="md:col-span-2">
                    <button class="inline-flex items-center rounded-md border border-border px-4 py-2 text-sm font-medium">Run connection test</button>
                </div>
            </form>
        </div>
    </div>

    <div class="mt-6 rounded-lg border border-border bg-surface p-5">
        <h2 class="text-lg font-semibold mb-3">Global feature routing</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-textSecondary">
                <tr>
                    <th class="py-2">Feature</th>
                    <th class="py-2">Modality</th>
                    <th class="py-2">Rule</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-border">
                @foreach($features as $featureKey => $featureDef)
                    @php($rule = $globalRules->get($featureKey))
                    <tr>
                        <td class="py-3 align-top">{{ $featureDef['label'] ?? $featureKey }}<br><span class="text-xs text-textSecondary">{{ $featureKey }}</span></td>
                        <td class="py-3 align-top">{{ $featureDef['modality'] ?? 'text' }}</td>
                        <td class="py-3">
                            <form method="POST" action="{{ route('admin.llm.settings.rules.upsert') }}" class="grid gap-2 md:grid-cols-3">
                                @csrf
                                <input type="hidden" name="scope_type" value="global">
                                <input type="hidden" name="feature" value="{{ $featureKey }}">
                                <input type="hidden" name="modality" value="{{ $featureDef['modality'] ?? 'text' }}">

                                <label class="text-xs">Inherit global defaults
                                    <select name="inherit_global" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                        <option value="1" @selected(($rule?->inherit_global ?? true) === true)>Yes</option>
                                        <option value="0" @selected(($rule?->inherit_global ?? true) === false)>No</option>
                                    </select>
                                </label>
                                <label class="text-xs">Provider override
                                    <select name="provider" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                        <option value="">None</option>
                                        @foreach($providers as $provider)
                                            <option value="{{ $provider }}" @selected(($rule?->provider ?? '') === $provider)>{{ $providerOptions[$provider] ?? $provider }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="text-xs">Model override
                                    <input name="model" value="{{ $rule?->model ?? '' }}" list="llm-models-all" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Select or type model-id">
                                </label>

                                <label class="text-xs">Fallback enabled
                                    <select name="fallback_enabled" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                        <option value="0" @selected(($rule?->fallback_enabled ?? false) === false)>Off</option>
                                        <option value="1" @selected(($rule?->fallback_enabled ?? false) === true)>On</option>
                                    </select>
                                </label>
                                <label class="text-xs">Fallback provider
                                    <select name="fallback_provider" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                        <option value="">None</option>
                                        @foreach($providers as $provider)
                                            <option value="{{ $provider }}" @selected(($rule?->fallback_provider ?? '') === $provider)>{{ $providerOptions[$provider] ?? $provider }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="text-xs">Fallback model
                                    <input name="fallback_model" value="{{ $rule?->fallback_model ?? '' }}" list="llm-models-all" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Select or type model-id">
                                </label>

                                <input type="hidden" name="is_enabled" value="1">

                                <div class="md:col-span-3 flex items-center gap-2 pt-1">
                                    <button class="inline-flex items-center rounded-md border border-border px-3 py-1.5 text-xs">Save rule</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6 rounded-lg border border-border bg-surface p-5">
        <div class="flex flex-wrap items-center gap-3 mb-3">
            <h2 class="text-lg font-semibold">Workspace overrides</h2>
            <form method="GET" class="inline-flex items-center gap-2">
                <select name="workspace_id" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
                    <option value="">Select workspace</option>
                    @foreach($workspaces as $workspace)
                        <option value="{{ $workspace->id }}" @selected($selectedWorkspaceId === (string) $workspace->id)>{{ $workspace->display_name ?: $workspace->name }}</option>
                    @endforeach
                </select>
                <button class="inline-flex items-center rounded-md border border-border px-3 py-2 text-sm">Load</button>
            </form>
        </div>

        @if($selectedWorkspaceId === '')
            <p class="text-sm text-textSecondary">Select a workspace to manage override rules.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-textSecondary">
                    <tr>
                        <th class="py-2">Feature</th>
                        <th class="py-2">Modality</th>
                        <th class="py-2">Rule</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                    @foreach($features as $featureKey => $featureDef)
                        @php($rule = $workspaceRules->get($featureKey))
                        <tr>
                            <td class="py-3 align-top">{{ $featureDef['label'] ?? $featureKey }}<br><span class="text-xs text-textSecondary">{{ $featureKey }}</span></td>
                            <td class="py-3 align-top">{{ $featureDef['modality'] ?? 'text' }}</td>
                            <td class="py-3">
                                <form method="POST" action="{{ route('admin.llm.settings.rules.upsert') }}" class="grid gap-2 md:grid-cols-3">
                                    @csrf
                                    <input type="hidden" name="scope_type" value="workspace">
                                    <input type="hidden" name="scope_id" value="{{ $selectedWorkspaceId }}">
                                    <input type="hidden" name="feature" value="{{ $featureKey }}">
                                    <input type="hidden" name="modality" value="{{ $featureDef['modality'] ?? 'text' }}">

                                    <label class="text-xs">Inherit global defaults
                                        <select name="inherit_global" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                            <option value="1" @selected(($rule?->inherit_global ?? true) === true)>Yes</option>
                                            <option value="0" @selected(($rule?->inherit_global ?? true) === false)>No</option>
                                        </select>
                                    </label>
                                    <label class="text-xs">Provider override
                                        <select name="provider" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                            <option value="">None</option>
                                            @foreach($providers as $provider)
                                                <option value="{{ $provider }}" @selected(($rule?->provider ?? '') === $provider)>{{ $providerOptions[$provider] ?? $provider }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="text-xs">Model override
                                        <input name="model" value="{{ $rule?->model ?? '' }}" list="llm-models-all" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Select or type model-id">
                                    </label>

                                    <label class="text-xs">Fallback enabled
                                        <select name="fallback_enabled" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                            <option value="0" @selected(($rule?->fallback_enabled ?? false) === false)>Off</option>
                                            <option value="1" @selected(($rule?->fallback_enabled ?? false) === true)>On</option>
                                        </select>
                                    </label>
                                    <label class="text-xs">Fallback provider
                                        <select name="fallback_provider" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                            <option value="">None</option>
                                            @foreach($providers as $provider)
                                                <option value="{{ $provider }}" @selected(($rule?->fallback_provider ?? '') === $provider)>{{ $providerOptions[$provider] ?? $provider }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="text-xs">Fallback model
                                        <input name="fallback_model" value="{{ $rule?->fallback_model ?? '' }}" list="llm-models-all" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Select or type model-id">
                                    </label>

                                    <input type="hidden" name="is_enabled" value="1">

                                    <div class="md:col-span-3 flex items-center gap-2 pt-1">
                                        <button class="inline-flex items-center rounded-md border border-border px-3 py-1.5 text-xs">Save workspace rule</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="mt-6 rounded-lg border border-border bg-surface p-5">
        <h2 class="text-lg font-semibold mb-3">Settings audit log</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-textSecondary">
                <tr>
                    <th class="py-2">At</th>
                    <th class="py-2">Actor</th>
                    <th class="py-2">Scope</th>
                    <th class="py-2">Action</th>
                    <th class="py-2">Diff</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-border">
                @forelse($auditLogs as $log)
                    <tr>
                        <td class="py-2">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                        <td class="py-2">{{ $log->actor?->name ?? 'system' }}</td>
                        <td class="py-2">{{ $log->scope_type }} {{ $log->scope_id ? '('.$log->scope_id.')' : '' }}</td>
                        <td class="py-2">{{ $log->action }}</td>
                        <td class="py-2">
                            <details>
                                <summary class="cursor-pointer underline">View</summary>
                                <pre class="mt-2 max-h-60 overflow-auto rounded-md border border-border bg-background p-2 text-xs">{{ json_encode(['before' => $log->before, 'after' => $log->after], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 text-center text-textSecondary">No settings changes recorded yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <datalist id="llm-models-all">
        @foreach($allModelOptions as $model)
            <option value="{{ $model }}"></option>
        @endforeach
    </datalist>

    @foreach($providers as $provider)
        <datalist id="llm-models-{{ $provider }}-text">
            @foreach(data_get($modelOptions, $provider.'.text', []) as $model)
                <option value="{{ $model }}"></option>
            @endforeach
        </datalist>

        <datalist id="llm-models-{{ $provider }}-image">
            @foreach(data_get($modelOptions, $provider.'.image', []) as $model)
                <option value="{{ $model }}"></option>
            @endforeach
        </datalist>
    @endforeach
@endsection
