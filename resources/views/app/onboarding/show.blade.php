@extends('layouts.app', ['title' => 'Onboarding'])

@section('content')
    <div class="space-y-6">
        <div class="rounded-lg border border-border bg-surface p-5">
            <h1 class="text-xl font-semibold text-textPrimary">Welcome to Argusly</h1>
            <p class="mt-1 text-sm text-textSecondary">Complete setup to unlock your first content outcome.</p>

            <div class="mt-4 grid gap-2 sm:grid-cols-3">
                <div class="rounded border border-border px-3 py-2 text-xs {{ $steps['intent'] ? 'bg-primarySoftBg text-textPrimary' : 'text-textSecondary' }}">
                    1. Choose intent
                </div>
                <div class="rounded border border-border px-3 py-2 text-xs {{ $steps['company_profile'] ? 'bg-primarySoftBg text-textPrimary' : 'text-textSecondary' }}">
                    2. Company profile
                </div>
                <div class="rounded border border-border px-3 py-2 text-xs {{ $steps['connect_site'] ? 'bg-primarySoftBg text-textPrimary' : 'text-textSecondary' }}">
                    3. Connect site
                </div>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Step 1: Choose intent</h2>
                <form method="POST" action="{{ route('app.onboarding.intent') }}" class="mt-4 space-y-3">
                    @csrf
                    <select name="intent" class="pl-select w-full" required>
                        <option value="">Select your intent</option>
                        @foreach($intents as $intentKey => $intentLabel)
                            <option value="{{ $intentKey }}" @selected(($state->intent ?? '') === $intentKey)>{{ $intentLabel }}</option>
                        @endforeach
                    </select>
                    <button class="pl-btn-primary" type="submit">Save intent</button>
                </form>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Step 2: Complete company profile</h2>
                <form method="POST" action="{{ route('app.onboarding.company-profile') }}" class="mt-4 space-y-3">
                    @csrf
                    <input name="company_name" class="pl-input" placeholder="Company name" value="{{ old('company_name', $steps['company_profile'] ? ($companyProfile?->company_name ?? '') : '') }}" required>
                    <input name="industry" class="pl-input" placeholder="Industry" value="{{ old('industry', $steps['company_profile'] ? ($companyProfile?->industry ?? '') : '') }}">
                    <textarea name="target_audience" class="pl-textarea" rows="3" placeholder="Target audience">{{ old('target_audience', $steps['company_profile'] ? ($companyProfile?->target_audience ?? '') : '') }}</textarea>
                    <textarea name="value_propositions" class="pl-textarea" rows="3" placeholder="Value propositions">{{ old('value_propositions', $steps['company_profile'] ? ($companyProfile?->value_propositions ?? '') : '') }}</textarea>
                    <button class="pl-btn-primary" type="submit">Save company profile</button>
                </form>

                <div class="mt-5 rounded-md border border-border bg-background p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-textPrimary">Suggest with AI</h3>
                            <p class="mt-1 text-xs text-textSecondary">Start a reusable Workspace Intelligence brand proposal during onboarding.</p>
                        </div>
                        <a href="{{ route('app.workspace-intelligence.index') }}" class="text-xs text-primary hover:underline">Open Workspace Intelligence</a>
                    </div>
                    @can('manage-organization')
                        <form method="POST" action="{{ route('app.workspace-intelligence.organization.store') }}" class="mt-3 space-y-3">
                            @csrf
                            <select name="source_type" class="pl-select w-full">
                                <option value="website_url">Website URL</option>
                                <option value="company_name_and_industry">Company name and industry</option>
                                <option value="manual_text">Manual text</option>
                            </select>
                            <input name="website_url" class="pl-input" placeholder="https://example.com">
                            <textarea name="manual_text" class="pl-textarea" rows="3" placeholder="Paste your positioning, audience, and offerings if you do not want to scan a website."></textarea>
                            <button class="pl-btn-secondary" type="submit">Suggest company profile</button>
                        </form>
                    @endcan
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-sm font-semibold text-textPrimary">Step 3: Connect your website</h2>
            <p class="mt-2 text-sm text-textSecondary">Download the Argusly WordPress plugin, generate a site key, paste the key, and run connection test.</p>
            <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-textSecondary">
                <li>Download and install the Argusly WordPress plugin</li>
                <li>Go to Sites and add your website</li>
                <li>Generate a site key and copy it</li>
                <li>Open the plugin in WordPress and paste the key</li>
                <li>Click Connect and confirm status is connected</li>
            </ol>
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="{{ route('app.sites.wordpress-plugin.download') }}" class="pl-btn-secondary">Download WP plugin</a>
                <a href="{{ route('app.sites') }}" class="pl-btn-secondary">Go to Sites</a>
                <form method="POST" action="{{ route('app.onboarding.connect-site') }}">
                    @csrf
                    <button class="pl-btn-primary" type="submit">I connected a site ({{ $siteCount }})</button>
                </form>
            </div>
        </div>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if($errors->any())
            <div class="rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger">
                {{ $errors->first() }}
            </div>
        @endif
    </div>
@endsection
