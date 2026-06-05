@extends('layouts.app', ['title' => 'Company Intelligence'])

@php
    $listValue = static fn ($profile, string $field): string => implode("\n", (array) old($field, $profile?->{$field} ?? []));
@endphp

@section('content')
    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <nav class="mb-2 text-sm text-textSecondary">
                <span>Brand</span>
                <span class="mx-1">/</span>
                <span class="text-textPrimary">Company Intelligence</span>
            </nav>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Company Intelligence</h1>
            <p class="mt-1 text-textSecondary">Multi-brand company context for Agentic Marketing planning, content opportunities, AI visibility and localization.</p>
        </div>
    </div>

    @include('app.brand.partials.tabs')

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->any())
        <x-alert type="error" class="mb-4">{{ $errors->first() }}</x-alert>
    @endif

    <div class="grid gap-4 xl:grid-cols-3">
        @foreach ($profiles as $profile)
            <section class="rounded-lg border border-border bg-surface p-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-semibold text-textPrimary">{{ $profile->company_name }}</h2>
                            @if ($profile->is_default)
                                <span class="rounded-full bg-primarySoftBg px-2 py-0.5 text-xs font-medium text-primary">Default</span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-textSecondary">{{ $profile->brand_key }} · {{ $profile->market_category ?: 'Uncategorized' }}</p>
                    </div>
                    <span class="text-sm font-semibold text-textPrimary">{{ $profile->completeness_score }}%</span>
                </div>

                <div class="mt-4 h-2 rounded-full bg-surfaceMuted">
                    <div class="h-2 rounded-full bg-primary" style="width: {{ max(0, min(100, (int) $profile->completeness_score)) }}%"></div>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-3 text-xs">
                    <div>
                        <dt class="text-textSecondary">Locales</dt>
                        <dd class="mt-1 text-textPrimary">{{ implode(', ', array_slice((array) $profile->locales, 0, 4)) ?: 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="text-textSecondary">Embedding</dt>
                        <dd class="mt-1 text-textPrimary">{{ str($profile->embedding_status)->headline() }}</dd>
                    </div>
                </dl>

                <details class="mt-4">
                    <summary class="cursor-pointer text-sm font-medium text-primary">Edit profile</summary>
                    <form method="POST" action="{{ route('app.brand.company-intelligence.update', $profile) }}" class="mt-4 space-y-4">
                        @csrf
                        @include('app.brand.company-intelligence.partials.form', ['profile' => $profile])
                        <div class="flex flex-wrap gap-2">
                            <button class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Update</button>
                            <a href="{{ route('app.brand.company-intelligence.json', $profile) }}" class="rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">AI JSON</a>
                        </div>
                    </form>
                    @can('manage-organization')
                        <form method="POST" action="{{ route('app.brand.company-intelligence.delete', $profile) }}" class="mt-2">
                            @csrf
                            @method('DELETE')
                            <button class="text-sm font-medium text-red-700">Archive profile</button>
                        </form>
                    @endcan
                </details>
            </section>
        @endforeach
    </div>

    <section class="mt-6 rounded-lg border border-border bg-surface p-5">
        <div class="mb-5">
            <h2 class="text-lg font-semibold text-textPrimary">Create intelligence profile</h2>
            <p class="mt-1 text-sm text-textSecondary">Use one profile for the primary brand, or add separate profiles for products, markets, or sub-brands.</p>
        </div>

        @can('manage-organization')
            <form method="POST" action="{{ route('app.brand.company-intelligence.store') }}" class="space-y-4">
                @csrf
                @include('app.brand.company-intelligence.partials.form', ['profile' => null])
                <button class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Create profile</button>
            </form>
        @else
            <p class="text-sm text-textSecondary">Read-only. Workspace admins can manage company intelligence.</p>
        @endcan
    </section>
@endsection
