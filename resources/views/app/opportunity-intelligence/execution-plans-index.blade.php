@extends('layouts.app', ['title' => 'Opportunity Execution Plans'])

@section('pageHeader')
    <x-page-header title="Opportunity Execution Plans">
        <x-slot:description>{{ $opportunity->title }}</x-slot:description>
    </x-page-header>
@endsection

@section('content')
@php
    $status = (string) ($opportunity->status?->value ?? $opportunity->status);
    $canPlanFromStatus = in_array($status, [\App\Enums\OpportunityStatus::APPROVED->value, \App\Enums\OpportunityStatus::REVIEWING->value], true);
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('app.opportunity-intelligence.opportunities.show', $opportunity) }}" class="inline-flex items-center gap-2 text-sm font-medium text-textSecondary hover:text-textPrimary">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>
                Linked opportunity
            </a>
            <h2 class="mt-3 text-2xl font-semibold tracking-tight text-textPrimary">Execution planning</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-textSecondary">{{ $opportunity->summary }}</p>
        </div>
        @if ($canCreateExecutionPlan)
            <form method="POST" action="{{ route('app.opportunity-intelligence.opportunities.execution-plans.store', $opportunity) }}">
                @csrf
                <button class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                    <i data-lucide="clipboard-list" class="h-4 w-4"></i>
                    Create Execution Plan
                </button>
            </form>
        @endif
    </div>

    @if (session('status'))
        <x-alert class="md:items-center" :icon="true">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->any())
        <x-alert class="border-danger/30 bg-danger/5 text-danger" :icon="true">{{ $errors->first() }}</x-alert>
    @endif

    <x-first-value-celebrations :items="$firstValueCelebrations ?? collect()" />

    <div class="rounded-md border border-border bg-surface p-6">
        <div class="flex items-start gap-4">
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-md {{ $canPlanFromStatus ? 'bg-primarySoftBg text-primary' : 'bg-amber-50 text-amber-700' }}">
                <i data-lucide="{{ $canPlanFromStatus ? 'clipboard-list' : 'circle-alert' }}" class="h-5 w-5"></i>
            </span>
            <div class="min-w-0 flex-1">
                <h3 class="text-base font-semibold text-textPrimary">
                    {{ $canPlanFromStatus ? 'No execution plan yet' : 'Execution plan is not available yet' }}
                </h3>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-textSecondary">
                    {{ $canPlanFromStatus ? 'Create the first execution plan to turn this approved opportunity into concrete steps, channels, and briefing context.' : 'Approve this opportunity or mark it as reviewing before creating an execution plan.' }}
                </p>
                @if ($canCreateExecutionPlan)
                    <form method="POST" action="{{ route('app.opportunity-intelligence.opportunities.execution-plans.store', $opportunity) }}" class="mt-4">
                        @csrf
                        <button class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                            <i data-lucide="clipboard-list" class="h-4 w-4"></i>
                            Create Execution Plan
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
