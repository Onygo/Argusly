@extends('layouts.app', ['title' => 'Setup'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Setup</x-slot:title>
        <x-slot:description>Prepare this workspace for Signal Intelligence, AI Visibility, Opportunity Intelligence, execution planning and content operations.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="space-y-6">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <form method="GET" action="{{ route('app.setup.index') }}">
                <select name="workspace" class="pl-work-select" onchange="this.form.submit()">
                    @foreach ($workspaces as $option)
                        <option value="{{ $option->id }}" @selected((string) $workspace->id === (string) $option->id)>{{ $option->display_name ?: $option->name }}</option>
                    @endforeach
                </select>
            </form>
        </div>

        <section class="rounded-md border border-border bg-surface p-5">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-textFaint">Platform readiness</p>
                    <p class="mt-2 text-4xl font-semibold text-textPrimary">{{ $score }}%</p>
                    <p class="mt-1 text-sm text-textSecondary">{{ $workspace->display_name ?: $workspace->name }}</p>
                </div>
                <div class="w-full space-y-3 lg:w-96">
                    <x-readiness-progress :value="$score" label="Overall progress" />
                    @if (!empty($activation) && !data_get($activation, 'is_active'))
                        <a href="{{ route('app.activation.index', ['workspace' => $workspace->id]) }}" class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                            <i data-lucide="rocket" class="h-4 w-4"></i>
                            Start First Value Activation
                        </a>
                    @endif
                </div>
            </div>
        </section>

        @if ($quick_actions->isNotEmpty())
            <section class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Recommended next actions</h2>
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($quick_actions as $action)
                        @if ($action->route)
                            <a href="{{ $action->route }}" class="inline-flex h-9 items-center rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">{{ $action->label }}</a>
                        @else
                            <span class="inline-flex h-9 items-center rounded-md border border-border bg-surfaceMuted px-3 text-sm font-medium text-textMuted">{{ $action->label }}</span>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

        <section class="grid gap-5 xl:grid-cols-2">
            @foreach ($modules as $module)
                <x-readiness-card :result="$module" />
            @endforeach
        </section>
    </div>
@endsection
