@extends('layouts.app', ['title' => $mode === 'edit' ? 'Edit Agentic Marketing Objective' : 'New Agentic Marketing Objective', 'pageWidth' => 'constrained'])

@php
    $isEdit = $mode === 'edit';
    $competitorsValue = old('competitors', implode("\n", (array) ($objective->competitors ?? [])));
    $selectedWorkspace = old('workspace_id', $objective->workspace_id);
    $selectedSite = old('client_site_id', $objective->client_site_id);
    $labels = [
        'ai_visibility' => 'AI visibility',
        'organic_traffic' => 'Organic traffic',
        'conversions' => 'Conversions',
        'content_velocity' => 'Content velocity',
        'pipeline_influence' => 'Pipeline influence',
        'manual' => 'Manual approval',
        'approval_required' => 'Approval required',
        'policy_engine' => 'Policy engine later',
    ];
@endphp

@section('content')
    <div class="space-y-6">
        <header class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ $isEdit ? route('app.agentic-marketing.objectives.show', $objective) : route('app.agentic-marketing.index') }}" class="text-sm text-textSecondary hover:text-textPrimary">Agentic Marketing</a>
                <h1 class="mt-2 text-xl font-semibold text-textPrimary">{{ $isEdit ? 'Edit objective' : 'New objective' }}</h1>
                <p class="mt-1 max-w-3xl text-sm text-textSecondary">Store the strategy and governance settings for supervised Agentic Marketing work. Approval mode is captured now for the future policy engine.</p>
            </div>
        </header>

        @if ($errors->any())
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                Review the highlighted fields and try again.
            </div>
        @endif

        <form method="POST" action="{{ $isEdit ? route('app.agentic-marketing.objectives.update', $objective) : route('app.agentic-marketing.objectives.store') }}" class="space-y-5 rounded-lg border border-border bg-surface p-5">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-1.5 sm:col-span-2">
                    <span class="text-sm font-medium text-textPrimary">Name</span>
                    <input name="name" value="{{ old('name', $objective->name) }}" class="pl-input w-full" required maxlength="160">
                    @error('name') <span class="text-xs text-rose-700">{{ $message }}</span> @enderror
                </label>

                <label class="space-y-1.5 sm:col-span-2">
                    <span class="text-sm font-medium text-textPrimary">Goal</span>
                    <textarea name="goal" rows="3" class="pl-input w-full" required maxlength="500">{{ old('goal', $objective->goal) }}</textarea>
                    @error('goal') <span class="text-xs text-rose-700">{{ $message }}</span> @enderror
                </label>

                <label class="space-y-1.5">
                    <span class="text-sm font-medium text-textPrimary">KPI</span>
                    <select name="kpi_type" class="pl-input w-full" required>
                        @foreach ($kpiTypes as $kpi)
                            <option value="{{ $kpi }}" @selected(old('kpi_type', $objective->kpi_type) === $kpi)>{{ $labels[$kpi] ?? str_replace('_', ' ', $kpi) }}</option>
                        @endforeach
                    </select>
                    @error('kpi_type') <span class="text-xs text-rose-700">{{ $message }}</span> @enderror
                </label>

                <label class="space-y-1.5">
                    <span class="text-sm font-medium text-textPrimary">Locale</span>
                    <select name="locale" class="pl-input w-full" required>
                        @foreach ($locales as $locale)
                            <option value="{{ $locale['value'] }}" @selected(old('locale', $objective->locale ?: 'en') === $locale['value'])>{{ $locale['label'] }}</option>
                        @endforeach
                    </select>
                    @error('locale') <span class="text-xs text-rose-700">{{ $message }}</span> @enderror
                </label>

                <label class="space-y-1.5">
                    <span class="text-sm font-medium text-textPrimary">Workspace</span>
                    <select name="workspace_id" class="pl-input w-full" required>
                        <option value="">Select workspace</option>
                        @foreach ($workspaces as $workspace)
                            <option value="{{ $workspace->id }}" @selected($selectedWorkspace === $workspace->id)>{{ $workspace->name }}</option>
                        @endforeach
                    </select>
                    @error('workspace_id') <span class="text-xs text-rose-700">{{ $message }}</span> @enderror
                </label>

                <label class="space-y-1.5">
                    <span class="text-sm font-medium text-textPrimary">Site</span>
                    <select name="client_site_id" class="pl-input w-full">
                        <option value="">No specific site</option>
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}" @selected($selectedSite === $site->id)>{{ $site->name }} @if($site->workspace) - {{ $site->workspace->name }} @endif</option>
                        @endforeach
                    </select>
                    @error('client_site_id') <span class="text-xs text-rose-700">{{ $message }}</span> @enderror
                </label>

                <label class="space-y-1.5 sm:col-span-2">
                    <span class="text-sm font-medium text-textPrimary">Audience</span>
                    <textarea name="audience" rows="3" class="pl-input w-full" maxlength="1000">{{ old('audience', $objective->audience) }}</textarea>
                    @error('audience') <span class="text-xs text-rose-700">{{ $message }}</span> @enderror
                </label>

                <label class="space-y-1.5 sm:col-span-2">
                    <span class="text-sm font-medium text-textPrimary">Competitors</span>
                    <textarea name="competitors" rows="3" class="pl-input w-full" maxlength="2000">{{ $competitorsValue }}</textarea>
                    @error('competitors') <span class="text-xs text-rose-700">{{ $message }}</span> @enderror
                </label>

                <label class="space-y-1.5">
                    <span class="text-sm font-medium text-textPrimary">Approval mode</span>
                    <select name="approval_mode" class="pl-input w-full" required>
                        @foreach ($approvalModes as $approvalMode)
                            <option value="{{ $approvalMode }}" @selected(old('approval_mode', $objective->approval_mode ?: 'manual') === $approvalMode)>{{ $labels[$approvalMode] ?? str_replace('_', ' ', $approvalMode) }}</option>
                        @endforeach
                    </select>
                    @error('approval_mode') <span class="text-xs text-rose-700">{{ $message }}</span> @enderror
                </label>

                <label class="space-y-1.5">
                    <span class="text-sm font-medium text-textPrimary">Monthly credit budget</span>
                    <input name="monthly_credit_budget" type="number" min="0" max="1000000" value="{{ old('monthly_credit_budget', $objective->monthly_credit_budget) }}" class="pl-input w-full">
                    @error('monthly_credit_budget') <span class="text-xs text-rose-700">{{ $message }}</span> @enderror
                </label>

                <label class="space-y-1.5">
                    <span class="text-sm font-medium text-textPrimary">Status</span>
                    <select name="status" class="pl-input w-full" required>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(old('status', $objective->status ?: 'active') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                    @error('status') <span class="text-xs text-rose-700">{{ $message }}</span> @enderror
                </label>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-border pt-4">
                <a href="{{ $isEdit ? route('app.agentic-marketing.objectives.show', $objective) : route('app.agentic-marketing.index') }}" class="pl-btn-ghost">Cancel</a>
                <button type="submit" class="pl-btn-primary">
                    <i data-lucide="save" class="h-4 w-4"></i>
                    <span>{{ $isEdit ? 'Save changes' : 'Create objective' }}</span>
                </button>
            </div>
        </form>
    </div>
@endsection
