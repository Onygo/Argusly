@extends('layouts.app', ['title' => 'Learnings'])

@section('content')
    <div class="space-y-6">
        <x-app.insights-header
            :site="$site"
            title="Learnings"
            description="Review content performance, engagement patterns, and AI SEO signals from tracked traffic."
            active="learnings"
        >
            <a href="{{ route('app.insights.index') }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">All sites</a>
            <a href="{{ route('app.sites.show', $site) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Site setup</a>
        </x-app.insights-header>

        <div class="rounded-lg border border-border bg-surface p-8 text-center">
        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-accentYellow-100">
            <i data-lucide="bar-chart-3" class="h-8 w-8 text-accentYellow-900"></i>
        </div>
        <h2 class="text-lg font-semibold text-textPrimary">Analytics Not Configured</h2>

        @if (!$analyticsSite)
            <p class="mt-2 text-textSecondary">Enable analytics to start tracking page views and engagement.</p>
            <a href="{{ route('app.sites.analytics.show', $site) }}" class="mt-4 inline-block rounded bg-primary px-4 py-2 text-sm text-white">
                Enable Analytics
            </a>
        @elseif (!$analyticsSite->is_enabled)
            <p class="mt-2 text-textSecondary">Analytics is disabled for this site.</p>
            <a href="{{ route('app.sites.analytics.show', $site) }}" class="mt-4 inline-block rounded bg-primary px-4 py-2 text-sm text-white">
                Enable Analytics
            </a>
        @elseif (!$analyticsSite->verified_at)
            <p class="mt-2 text-textSecondary">Please verify your domain to start tracking.</p>
            <a href="{{ route('app.sites.analytics.show', $site) }}" class="mt-4 inline-block rounded bg-primary px-4 py-2 text-sm text-white">
                Verify Domain
            </a>
        @endif
        </div>
    </div>
@endsection
