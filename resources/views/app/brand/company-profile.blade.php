@extends('layouts.app', ['title' => app()->getLocale() === 'nl' ? __('app.runtime.Company Profile') : 'Company Profile'])

@php
    $rt = function (string $value): string {
        $key = 'app.runtime.'.$value;

        return app()->getLocale() === 'nl' && \Illuminate\Support\Facades\Lang::has($key)
            ? __($key)
            : $value;
    };
@endphp

@section('pageHeader')
    <x-page-header title="Company Profile" eyebrow="Brand">
        <x-slot:description>Build a reusable company context with AI first, then fine-tune descriptions, positioning, services and audience manually.</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <a href="{{ route('app.workspace-intelligence.index') }}" class="pl-btn-secondary">
        {{ $rt('Workspace Intelligence') }}
    </a>
@endsection

@section('content')
    @include('app.brand.partials.tabs')

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <div class="space-y-6">
        @include('app.brand.partials.ai-entry', [
            'section' => 'company_profile',
            'manualTarget' => 'manual',
            'latestBrandContext' => $latestBrandContext,
            'title' => 'Generate company context with AI',
            'description' => 'Use website content, positioning notes or a guided input to generate descriptions, mission, vision, services and audience context.',
        ])

        @if ($organizationProfile)
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-lg border border-border bg-surface p-5">
                    <div class="text-xs uppercase tracking-wide text-textSecondary">Approved intelligence summary</div>
                    <p class="mt-3 text-sm text-textPrimary">{{ $organizationProfile->brand_summary ?: 'No summary approved yet.' }}</p>
                </div>
                <div class="rounded-lg border border-border bg-surface p-5">
                    <div class="text-xs uppercase tracking-wide text-textSecondary">Approved tone of voice</div>
                    <p class="mt-3 text-sm text-textPrimary">{{ $organizationProfile->tone_of_voice ?: 'No tone approved yet.' }}</p>
                </div>
            </div>
        @endif

        <div id="manual" class="rounded-lg border border-border bg-surface p-5">
            <div class="mb-5 flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-textPrimary">Manual profile</h2>
                    <p class="mt-1 text-sm text-textSecondary">Review or refine the AI-generated profile. This workspace-specific profile feeds content generation and strategy prompts.</p>
                </div>
                <span class="text-xs text-textSecondary">Workspace: {{ $workspace?->display_name ?? 'n/a' }}</span>
            </div>

            @can('manage-organization')
                <form method="POST" action="{{ route('app.brand.company-profile.upsert') }}" class="space-y-5">
                    @csrf

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div>
                            <label class="text-sm text-textSecondary" for="cp_company_name">Company Name</label>
                            <input id="cp_company_name" name="company_name" value="{{ old('company_name', $companyProfile?->company_name ?? $workspace?->organization?->name) }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" required>
                        </div>
                        <div>
                            <label class="text-sm text-textSecondary" for="cp_industry">Industry</label>
                            <input id="cp_industry" name="industry" value="{{ old('industry', $companyProfile?->industry) }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                        </div>
                    </div>

                    <div class="grid gap-4 xl:grid-cols-2">
                        <div>
                            <label class="text-sm text-textSecondary" for="cp_short_description">Short Description</label>
                            <textarea id="cp_short_description" name="short_description" rows="3" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('short_description', $companyProfile?->short_description) }}</textarea>
                            <x-app.ai-field-actions target="#cp_short_description" context="Company profile short description" />
                        </div>
                        <div>
                            <label class="text-sm text-textSecondary" for="cp_value_proposition">Value Proposition</label>
                            <textarea id="cp_value_proposition" name="value_proposition" rows="3" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('value_proposition', $companyProfile?->value_proposition) }}</textarea>
                            <x-app.ai-field-actions target="#cp_value_proposition" context="Company value proposition" />
                        </div>
                    </div>

                    <div>
                        <label class="text-sm text-textSecondary" for="cp_long_description">Long Description</label>
                        <textarea id="cp_long_description" name="long_description" rows="5" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('long_description', $companyProfile?->long_description) }}</textarea>
                        <x-app.ai-field-actions target="#cp_long_description" context="Company profile long description" />
                    </div>

                    <div class="grid gap-4 xl:grid-cols-2">
                        <div>
                            <label class="text-sm text-textSecondary" for="cp_mission">Mission</label>
                            <textarea id="cp_mission" name="mission" rows="3" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('mission', $companyProfile?->mission) }}</textarea>
                            <x-app.ai-field-actions target="#cp_mission" context="Company mission" />
                        </div>
                        <div>
                            <label class="text-sm text-textSecondary" for="cp_vision">Vision</label>
                            <textarea id="cp_vision" name="vision" rows="3" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('vision', $companyProfile?->vision) }}</textarea>
                            <x-app.ai-field-actions target="#cp_vision" context="Company vision" />
                        </div>
                    </div>

                    <div class="grid gap-4 xl:grid-cols-2">
                        <div>
                            <label class="text-sm text-textSecondary" for="cp_key_services">Key Services (one per line)</label>
                            <textarea id="cp_key_services" name="key_services" rows="4" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('key_services', $companyProfile?->key_services) }}</textarea>
                            <x-app.ai-field-actions target="#cp_key_services" context="Company key services" />
                        </div>
                        <div>
                            <label class="text-sm text-textSecondary" for="cp_target_audience">Target Audience</label>
                            <textarea id="cp_target_audience" name="target_audience" rows="4" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('target_audience', $companyProfile?->target_audience) }}</textarea>
                            <x-app.ai-field-actions target="#cp_target_audience" context="Company target audience" />
                        </div>
                    </div>

                    <div class="grid gap-4 xl:grid-cols-2">
                        <div>
                            <label class="text-sm text-textSecondary" for="cp_value_props">Value Propositions (one per line)</label>
                            <textarea id="cp_value_props" name="value_propositions" rows="4" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('value_propositions', $companyProfile?->value_propositions) }}</textarea>
                            <x-app.ai-field-actions target="#cp_value_props" context="Company value propositions" />
                        </div>
                        <div>
                            <label class="text-sm text-textSecondary" for="cp_proof_points">Proof Points (one per line)</label>
                            <textarea id="cp_proof_points" name="proof_points" rows="4" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('proof_points', $companyProfile?->proof_points) }}</textarea>
                            <x-app.ai-field-actions target="#cp_proof_points" context="Company proof points" />
                        </div>
                    </div>

                    <div class="grid gap-4 xl:grid-cols-2">
                        <div>
                            <label class="text-sm text-textSecondary" for="cp_compliance_rules">Compliance Rules</label>
                            <textarea id="cp_compliance_rules" name="compliance_rules" rows="3" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('compliance_rules', $companyProfile?->compliance_rules) }}</textarea>
                            <x-app.ai-field-actions target="#cp_compliance_rules" context="Company compliance rules" />
                        </div>
                        <div>
                            <label class="text-sm text-textSecondary" for="cp_banned_claims">Banned Claims</label>
                            <textarea id="cp_banned_claims" name="banned_claims" rows="3" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('banned_claims', $companyProfile?->banned_claims) }}</textarea>
                            <x-app.ai-field-actions target="#cp_banned_claims" context="Company banned claims" />
                        </div>
                    </div>

                    <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">
                        {{ $companyProfile ? 'Update Company Profile' : 'Create Company Profile' }}
                    </button>
                </form>
            @else
                <p class="text-sm text-textSecondary">Read-only. Workspace admins can edit this profile.</p>
            @endcan
        </div>
    </div>
@endsection
