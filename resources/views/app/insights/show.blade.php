@extends('layouts.app', ['title' => 'Insights Overview'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Insights Overview</x-slot:title>
        <x-slot:description>Visibility, analytics, audit, and competitor workflows for this site.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="space-y-6">
        <x-app.insights-header
            :site="$site"
            title="Insights Overview"
            description="Visibility, analytics, audit, and competitor workflows for this site."
            active="overview"
        >
            <a href="{{ route('app.insights.index') }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">All sites</a>
            <a href="{{ route('app.sites.show', $site) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Site setup</a>
        </x-app.insights-header>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-800">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($overviewCards as $card)
                <article class="rounded-lg border border-border bg-surface p-6">
                    <div class="flex items-start justify-between gap-3">
                        <span class="inline-flex rounded px-2 py-1 text-xs {{ $card['available'] ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                            {{ $card['available'] ? 'Available' : 'Limited' }}
                        </span>
                    </div>

                    <div class="mt-4 rounded-lg border border-border bg-background p-4 text-sm text-textPrimary">
                        {{ $card['status'] }}
                    </div>

                    <div class="mt-5">
                        @if ($card['available'])
                            <a href="{{ $card['url'] }}" class="inline-flex items-center rounded-md border border-border px-4 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">
                                {{ $card['cta'] }}
                            </a>
                        @else
                            <p class="text-xs text-textSecondary">Upgrade or enable the required workspace feature to open this area.</p>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </div>
@endsection
