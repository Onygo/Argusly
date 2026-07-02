@extends('layouts.admin', ['title' => 'Pricing Page Content', 'pageWidth' => 'constrained'])

@section('pageHeader')
    <x-page-header title="Pricing Page Content">
        <x-slot:description>Manage the public pricing page copy. Leave fields blank to use default translations.</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <a href="{{ route('admin.billing.index') }}" class="pl-btn-secondary">Back to Billing</a>
    <a href="{{ route('pricing') }}" target="_blank" class="pl-btn-secondary">View public pricing page</a>
@endsection

@section('content')
    @if (session('status'))
        <div class="mb-4 rounded-lg border border-green-300 bg-green-50 p-4 text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif

    @include('admin.faq-intelligence._cms-tab', [
        'pageType' => 'pricing',
        'pageSlug' => 'pricing',
        'locale' => 'en',
        'title' => 'Pricing',
        'metaTitle' => $content['hero_title'] ?? 'Pricing',
        'metaDescription' => $content['hero_subline'] ?? '',
        'h1' => $content['hero_title'] ?? 'Pricing',
        'content' => implode(' ', array_filter([
            $content['hero_subline'] ?? '',
            $content['hero_text_1'] ?? '',
            $content['hero_text_2'] ?? '',
            $content['enterprise_text'] ?? '',
            $content['credit_faq_text'] ?? '',
        ])),
        'solutionType' => 'Pricing',
    ])

    <form method="POST" action="{{ route('admin.billing.pricing-page.update') }}" class="mt-6 space-y-6">
        @csrf

        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="mb-4 text-sm font-semibold text-textPrimary">Hero Section</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">Badge text</label>
                    <input type="text" name="hero_badge" value="{{ $content['hero_badge'] ?? '' }}" placeholder="{{ __('public.landing.pricing_badge') }}" class="w-full rounded border border-border px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">Hero title</label>
                    <input type="text" name="hero_title" value="{{ $content['hero_title'] ?? '' }}" placeholder="{{ __('public.landing.pricing_title') }}" class="w-full rounded border border-border px-3 py-2 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-textSecondary mb-1">Subline</label>
                    <input type="text" name="hero_subline" value="{{ $content['hero_subline'] ?? '' }}" placeholder="{{ __('public.landing.pricing_subline') }}" class="w-full rounded border border-border px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">Intro paragraph 1</label>
                    <textarea name="hero_text_1" rows="2" placeholder="{{ __('public.landing.pricing_text_1') }}" class="w-full rounded border border-border px-3 py-2 text-sm">{{ $content['hero_text_1'] ?? '' }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">Intro paragraph 2</label>
                    <textarea name="hero_text_2" rows="2" placeholder="{{ __('public.landing.pricing_text_2') }}" class="w-full rounded border border-border px-3 py-2 text-sm">{{ $content['hero_text_2'] ?? '' }}</textarea>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-textSecondary mb-1">Credit usage note</label>
                    <input type="text" name="hero_note" value="{{ $content['hero_note'] ?? '' }}" placeholder="{{ __('public.landing.credits_usage_note') }}" class="w-full rounded border border-border px-3 py-2 text-sm">
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="mb-4 text-sm font-semibold text-textPrimary">Plan Cards</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">Pricing note</label>
                    <input type="text" name="monthly_no_setup_text" value="{{ $content['monthly_no_setup_text'] ?? '' }}" placeholder="{{ __('public.landing.pricing_monthly_no_setup') }}" class="w-full rounded border border-border px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">Includes badges (one per line)</label>
                    <textarea name="includes" rows="3" placeholder="{{ implode("\n", __('public.landing.pricing_includes')) }}" class="w-full rounded border border-border px-3 py-2 text-sm font-mono text-xs">{{ implode("\n", $content['includes'] ?? []) }}</textarea>
                    <p class="mt-1 text-xs text-textMuted">Enter each badge on a new line.</p>
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="mb-4 text-sm font-semibold text-textPrimary">Why Section</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">Section title</label>
                    <input type="text" name="why_title" value="{{ $content['why_title'] ?? '' }}" placeholder="{{ __('public.landing.why_title') }}" class="w-full rounded border border-border px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">Bullet points (one per line)</label>
                    <textarea name="why_points" rows="5" placeholder="{{ implode("\n", trans('public.landing.why_points')) }}" class="w-full rounded border border-border px-3 py-2 text-sm font-mono text-xs">{{ implode("\n", $content['why_points'] ?? []) }}</textarea>
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="mb-4 text-sm font-semibold text-textPrimary">Credit FAQ Section</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">FAQ title</label>
                    <input type="text" name="credit_faq_title" value="{{ $content['credit_faq_title'] ?? '' }}" placeholder="{{ __('public.landing.credit_faq_title') }}" class="w-full rounded border border-border px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">FAQ intro text</label>
                    <textarea name="credit_faq_text" rows="2" placeholder="{{ __('public.landing.credit_faq_text') }}" class="w-full rounded border border-border px-3 py-2 text-sm">{{ $content['credit_faq_text'] ?? '' }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">Credit examples (one per line)</label>
                    <textarea name="credit_examples" rows="4" placeholder="{{ implode("\n", trans('public.landing.credit_examples')) }}" class="w-full rounded border border-border px-3 py-2 text-sm font-mono text-xs">{{ implode("\n", $content['credit_examples'] ?? []) }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">Failure note</label>
                    <textarea name="credit_failure_note" rows="2" placeholder="{{ __('public.landing.credit_failure_note') }}" class="w-full rounded border border-border px-3 py-2 text-sm">{{ $content['credit_failure_note'] ?? '' }}</textarea>
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="mb-4 text-sm font-semibold text-textPrimary">Enterprise Section</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">Section title</label>
                    <input type="text" name="enterprise_title" value="{{ $content['enterprise_title'] ?? '' }}" placeholder="{{ __('public.landing.enterprise_title') }}" class="w-full rounded border border-border px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">Description text</label>
                    <textarea name="enterprise_text" rows="2" placeholder="{{ __('public.landing.enterprise_text') }}" class="w-full rounded border border-border px-3 py-2 text-sm">{{ $content['enterprise_text'] ?? '' }}</textarea>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-medium text-textSecondary mb-1">Bullet points (one per line)</label>
                    <textarea name="enterprise_points" rows="4" placeholder="{{ implode("\n", trans('public.landing.enterprise_points')) }}" class="w-full rounded border border-border px-3 py-2 text-sm font-mono text-xs">{{ implode("\n", $content['enterprise_points'] ?? []) }}</textarea>
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="mb-4 text-sm font-semibold text-textPrimary">Bottom CTA Block (Optional)</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">CTA title</label>
                    <input type="text" name="bottom_cta_title" value="{{ $content['bottom_cta_title'] ?? '' }}" placeholder="e.g., Ready to get started?" class="w-full rounded border border-border px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">CTA text</label>
                    <textarea name="bottom_cta_text" rows="2" placeholder="e.g., Start with Argusly today..." class="w-full rounded border border-border px-3 py-2 text-sm">{{ $content['bottom_cta_text'] ?? '' }}</textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">Button label</label>
                    <input type="text" name="bottom_cta_button_label" value="{{ $content['bottom_cta_button_label'] ?? '' }}" placeholder="e.g., Get started" class="w-full rounded border border-border px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-textSecondary mb-1">Button URL</label>
                    <input type="text" name="bottom_cta_button_url" value="{{ $content['bottom_cta_button_url'] ?? '' }}" placeholder="e.g., /register" class="w-full rounded border border-border px-3 py-2 text-sm">
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.billing.index') }}" class="inline-flex items-center rounded border border-border px-4 py-2 text-sm">Cancel</a>
            <button type="submit" class="inline-flex items-center rounded bg-textPrimary px-4 py-2 text-sm font-medium text-textInverse">Save changes</button>
        </div>
    </form>
@endsection
