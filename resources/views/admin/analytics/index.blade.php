@extends('layouts.admin', ['pageWidth' => 'constrained'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Analytics Settings</x-slot:title>
        <x-slot:description>Configure tracking scripts for public pages.</x-slot:description>
    </x-page-header>
@endsection

@section('content')

    @if (session('status'))
        <div class="mb-6 rounded-lg border border-accentGreen-200 bg-accentGreen-50 p-4">
            <div class="flex items-center gap-2">
                <i data-lucide="check-circle" class="h-4 w-4 text-accentGreen-600"></i>
                <p class="text-sm font-medium text-accentGreen-800">{{ session('status') }}</p>
            </div>
        </div>
    @endif

    @if (! $isTrackingAllowed)
        <div class="mb-6 rounded-lg border border-accentYellow-200 bg-accentYellow-50 p-4">
            <div class="flex items-start gap-2">
                <i data-lucide="alert-triangle" class="mt-0.5 h-4 w-4 text-accentYellow-600"></i>
                <div>
                    <p class="text-sm font-medium text-accentYellow-800">Tracking disabled in this environment</p>
                    <p class="mt-1 text-sm text-accentYellow-700">
                        Analytics tracking is currently disabled for the <code class="rounded bg-accentYellow-100 px-1 py-0.5 text-xs">{{ $currentEnvironment }}</code> environment.
                        Settings can be saved but tracking will not render until the environment configuration allows it.
                    </p>
                </div>
            </div>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-6 rounded-lg border border-danger/20 bg-danger/5 p-4">
            <div class="flex items-start gap-2">
                <i data-lucide="alert-circle" class="mt-0.5 h-4 w-4 text-danger"></i>
                <div>
                    <p class="text-sm font-medium text-danger">Please fix the following errors:</p>
                    <ul class="mt-2 list-inside list-disc text-sm text-danger">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.analytics.update') }}" class="space-y-6">
        @csrf

        <div class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-5 py-4">
                <h3 class="text-sm font-semibold text-textPrimary">General Settings</h3>
            </div>
            <div class="space-y-4 p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <label for="analytics_enabled" class="text-sm font-medium text-textPrimary">Enable analytics</label>
                        <p class="text-xs text-textSecondary">Turn on tracking for public pages</p>
                    </div>
                    <label class="relative inline-flex cursor-pointer items-center">
                        <input type="hidden" name="analytics_enabled" value="0">
                        <input
                            type="checkbox"
                            name="analytics_enabled"
                            id="analytics_enabled"
                            value="1"
                            class="peer sr-only"
                            {{ old('analytics_enabled', $settings['analytics_enabled'] ?? false) ? 'checked' : '' }}
                        >
                        <div class="peer h-6 w-11 rounded-full bg-surfaceMuted after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-border after:bg-white after:transition-all after:content-[''] peer-checked:bg-primary peer-checked:after:translate-x-full peer-checked:after:border-white"></div>
                    </label>
                </div>

                <div class="flex items-center justify-between">
                    <div>
                        <label for="analytics_public_only" class="text-sm font-medium text-textPrimary">Only inject on public pages</label>
                        <p class="text-xs text-textSecondary">Tracking will not appear in the app or admin areas</p>
                    </div>
                    <label class="relative inline-flex cursor-pointer items-center">
                        <input type="hidden" name="analytics_public_only" value="0">
                        <input
                            type="checkbox"
                            name="analytics_public_only"
                            id="analytics_public_only"
                            value="1"
                            class="peer sr-only"
                            {{ old('analytics_public_only', $settings['analytics_public_only'] ?? true) ? 'checked' : '' }}
                        >
                        <div class="peer h-6 w-11 rounded-full bg-surfaceMuted after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-border after:bg-white after:transition-all after:content-[''] peer-checked:bg-primary peer-checked:after:translate-x-full peer-checked:after:border-white"></div>
                    </label>
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-5 py-4">
                <h3 class="text-sm font-semibold text-textPrimary">Tracking Provider</h3>
            </div>
            <div class="space-y-4 p-5">
                <div>
                    <label for="analytics_provider" class="mb-1 block text-sm font-medium text-textPrimary">Provider</label>
                    <select
                        name="analytics_provider"
                        id="analytics_provider"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        onchange="toggleProviderFields()"
                    >
                        <option value="">Select a provider...</option>
                        @foreach ($providers as $value => $label)
                            <option value="{{ $value }}" {{ old('analytics_provider', $settings['analytics_provider'] ?? '') === $value ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div id="ga4_fields" class="hidden space-y-4">
                    <div>
                        <label for="analytics_measurement_id" class="mb-1 block text-sm font-medium text-textPrimary">Google Analytics Measurement ID</label>
                        <input
                            type="text"
                            name="analytics_measurement_id"
                            id="analytics_measurement_id"
                            value="{{ old('analytics_measurement_id', $settings['analytics_measurement_id'] ?? '') }}"
                            placeholder="G-XXXXXXXXXX"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary placeholder:text-textMuted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                        <p class="mt-1 text-xs text-textSecondary">
                            Find this in your Google Analytics property settings. Format: <code class="rounded bg-surfaceMuted px-1 py-0.5">G-XXXXXXXXXX</code>
                        </p>
                    </div>
                </div>

                <div id="gtm_fields" class="hidden space-y-4">
                    <div>
                        <label for="analytics_container_id" class="mb-1 block text-sm font-medium text-textPrimary">Google Tag Manager Container ID</label>
                        <input
                            type="text"
                            name="analytics_container_id"
                            id="analytics_container_id"
                            value="{{ old('analytics_container_id', $settings['analytics_container_id'] ?? '') }}"
                            placeholder="GTM-XXXXXXX"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary placeholder:text-textMuted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                        <p class="mt-1 text-xs text-textSecondary">
                            Find this in your GTM workspace. Format: <code class="rounded bg-surfaceMuted px-1 py-0.5">GTM-XXXXXXX</code>
                        </p>
                    </div>
                </div>

                <div id="custom_fields" class="hidden space-y-4">
                    <div>
                        <label for="analytics_custom_head_script" class="mb-1 block text-sm font-medium text-textPrimary">Custom head script</label>
                        <textarea
                            name="analytics_custom_head_script"
                            id="analytics_custom_head_script"
                            rows="8"
                            placeholder="<script>...</script>"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 font-mono text-sm text-textPrimary placeholder:text-textMuted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >{{ old('analytics_custom_head_script', $settings['analytics_custom_head_script'] ?? '') }}</textarea>
                        <p class="mt-1 text-xs text-textSecondary">
                            This script will be injected into the <code class="rounded bg-surfaceMuted px-1 py-0.5">&lt;head&gt;</code> of public pages.
                        </p>
                    </div>
                    <div class="rounded-lg border border-accentYellow-200 bg-accentYellow-50 p-3">
                        <div class="flex items-start gap-2">
                            <i data-lucide="alert-triangle" class="mt-0.5 h-4 w-4 text-accentYellow-600"></i>
                            <p class="text-xs text-accentYellow-800">
                                <strong>Security notice:</strong> Only use this for trusted analytics or tag scripts. This code is injected directly into the public site head without sanitization.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if ($settings['analytics_updated_at'] ?? null)
            <div class="text-xs text-textMuted">
                Last updated: {{ \Carbon\Carbon::parse($settings['analytics_updated_at'])->format('M j, Y \a\t g:i A') }}
                @if ($settings['analytics_updated_by'] ?? null)
                    @php($updater = \App\Models\User::find($settings['analytics_updated_by']))
                    @if ($updater)
                        by {{ $updater->name }}
                    @endif
                @endif
            </div>
        @endif

        <div class="flex items-center gap-3">
            <button type="submit" class="inline-flex h-9 items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-white hover:bg-primary/90">
                Save settings
            </button>
        </div>
    </form>

    <script>
        function toggleProviderFields() {
            var provider = document.getElementById('analytics_provider').value;
            var ga4Fields = document.getElementById('ga4_fields');
            var gtmFields = document.getElementById('gtm_fields');
            var customFields = document.getElementById('custom_fields');

            ga4Fields.classList.add('hidden');
            gtmFields.classList.add('hidden');
            customFields.classList.add('hidden');

            if (provider === 'google_analytics_gtag') {
                ga4Fields.classList.remove('hidden');
            } else if (provider === 'google_tag_manager') {
                gtmFields.classList.remove('hidden');
            } else if (provider === 'custom_head_script') {
                customFields.classList.remove('hidden');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            toggleProviderFields();
        });
    </script>
@endsection
