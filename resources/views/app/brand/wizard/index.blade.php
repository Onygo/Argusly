@extends('layouts.app', ['title' => 'Generate Brand with AI'])

@section('content')
    <div class="mb-6">
        <nav class="mb-2 text-sm text-textSecondary">
            <a href="{{ route('app.brand.company-profile') }}" class="hover:text-textPrimary">Brand</a>
            <span class="mx-1">/</span>
            <span class="text-textPrimary">Generate with AI</span>
        </nav>
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Generate Brand with AI</h1>
        <p class="mt-1 text-textSecondary">Provide source material and let AI generate your company profile, brand voices, buyer personas, and team personas.</p>
    </div>

    @if ($errors->any())
        <x-alert variant="error" class="mb-4">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    <form method="POST" action="{{ route('app.brand.wizard.store') }}" class="space-y-6">
        @csrf

        {{-- Step 1: Input Method Selection --}}
        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-lg font-semibold text-textPrimary mb-4">Step 1: Choose Input Method</h2>

            <div class="grid gap-4 md:grid-cols-3" id="input-method-cards">
                {{-- Text Input --}}
                <label class="relative cursor-pointer">
                    <input type="radio" name="input_type" value="text" class="peer sr-only" checked>
                    <div class="rounded-lg border-2 border-border p-4 transition-all peer-checked:border-primary peer-checked:bg-primarySoftBg">
                        <div class="flex items-center gap-3 mb-2">
                            <i data-lucide="file-text" class="h-5 w-5 text-textSecondary"></i>
                            <span class="font-medium text-textPrimary">Paste Text</span>
                        </div>
                        <p class="text-sm text-textSecondary">Paste website text, about page, or positioning document.</p>
                    </div>
                </label>

                {{-- Website URL --}}
                <label class="relative cursor-pointer">
                    <input type="radio" name="input_type" value="website_url" class="peer sr-only">
                    <div class="rounded-lg border-2 border-border p-4 transition-all peer-checked:border-primary peer-checked:bg-primarySoftBg">
                        <div class="flex items-center gap-3 mb-2">
                            <i data-lucide="globe" class="h-5 w-5 text-textSecondary"></i>
                            <span class="font-medium text-textPrimary">Website URL</span>
                        </div>
                        <p class="text-sm text-textSecondary">We'll crawl your homepage and about page automatically.</p>
                    </div>
                </label>

                {{-- Guided Input --}}
                <label class="relative cursor-pointer">
                    <input type="radio" name="input_type" value="guided" class="peer sr-only">
                    <div class="rounded-lg border-2 border-border p-4 transition-all peer-checked:border-primary peer-checked:bg-primarySoftBg">
                        <div class="flex items-center gap-3 mb-2">
                            <i data-lucide="list-checks" class="h-5 w-5 text-textSecondary"></i>
                            <span class="font-medium text-textPrimary">Guided Input</span>
                        </div>
                        <p class="text-sm text-textSecondary">Answer a few questions about your company.</p>
                    </div>
                </label>
            </div>
        </div>

        {{-- Step 2: Input Fields (conditional based on selection) --}}
        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-lg font-semibold text-textPrimary mb-4">Step 2: Provide Source Material</h2>

            {{-- Text Input Fields --}}
            <div id="input-text" class="input-panel">
                <div>
                    <label class="text-sm text-textSecondary" for="pasted_text">Paste your content</label>
                    <textarea
                        id="pasted_text"
                        name="pasted_text"
                        rows="10"
                        class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        placeholder="Paste website text, about page content, brand positioning, or any relevant company information..."
                    >{{ old('pasted_text') }}</textarea>
                    <p class="mt-1 text-xs text-textMuted">Tip: Include your about page, value propositions, target audience description, and any brand guidelines.</p>
                </div>
            </div>

            {{-- Website URL Fields --}}
            <div id="input-website_url" class="input-panel hidden">
                <div>
                    <label class="text-sm text-textSecondary" for="website_url">Website URL</label>
                    <input
                        type="url"
                        id="website_url"
                        name="website_url"
                        value="{{ old('website_url') }}"
                        class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        placeholder="https://example.com"
                    >
                    <p class="mt-1 text-xs text-textMuted">We'll crawl your homepage and up to 5 internal pages to gather brand information.</p>
                </div>
            </div>

            {{-- Guided Input Fields --}}
            <div id="input-guided" class="input-panel hidden space-y-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="text-sm text-textSecondary" for="company_name">Company Name</label>
                        <input
                            type="text"
                            id="company_name"
                            name="company_name"
                            value="{{ old('company_name', $organization->name ?? '') }}"
                            class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            placeholder="Acme Corporation"
                        >
                    </div>
                    <div>
                        <label class="text-sm text-textSecondary" for="target_audience">Target Audience</label>
                        <input
                            type="text"
                            id="target_audience"
                            name="target_audience"
                            value="{{ old('target_audience') }}"
                            class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            placeholder="B2B SaaS companies, Marketing teams"
                        >
                    </div>
                </div>
                <div>
                    <label class="text-sm text-textSecondary" for="what_you_do">What does your company do?</label>
                    <textarea
                        id="what_you_do"
                        name="what_you_do"
                        rows="4"
                        class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        placeholder="Describe your products, services, and value proposition..."
                    >{{ old('what_you_do') }}</textarea>
                </div>
                <div>
                    <label class="text-sm text-textSecondary" for="tone_description">Describe your brand tone (optional)</label>
                    <input
                        type="text"
                        id="tone_description"
                        name="tone_description"
                        value="{{ old('tone_description') }}"
                        class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        placeholder="Professional but approachable, technical but accessible"
                    >
                </div>
            </div>
        </div>

        {{-- Step 3: Section Selection --}}
        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-lg font-semibold text-textPrimary mb-2">Step 3: Select Sections to Generate</h2>
            <p class="text-sm text-textSecondary mb-4">Choose which sections you want AI to generate. You can review and edit everything before applying.</p>

            <div class="grid gap-3 md:grid-cols-2">
                @foreach ($sections as $section)
                    @php
                        $labels = [
                            'company_profile' => ['Company Profile', 'Company description, value propositions, target audience'],
                            'brand_voices' => ['Brand Voices', '2-4 distinct writing voices with do/don\'t rules'],
                            'buyer_personas' => ['Buyer Personas', '2-5 detailed buyer/user personas'],
                            'team_personas' => ['Team Personas', 'Suggested author personas (Founder, CTO, etc.)'],
                        ];
                        $label = $labels[$section] ?? [$section, ''];
                        $isPreselected = $preselectedSection === $section || old('sections') === null;
                    @endphp
                    <label class="flex items-start gap-3 rounded-lg border border-border p-4 cursor-pointer hover:bg-surfaceSubtle">
                        <input
                            type="checkbox"
                            name="sections[]"
                            value="{{ $section }}"
                            class="mt-0.5"
                            @checked($isPreselected || in_array($section, old('sections', [])))
                        >
                        <div>
                            <span class="font-medium text-textPrimary">{{ $label[0] }}</span>
                            <p class="text-sm text-textSecondary">{{ $label[1] }}</p>
                        </div>
                    </label>
                @endforeach
            </div>

            <div class="mt-4">
                <label class="text-sm text-textSecondary" for="generation_mode">Generation mode</label>
                @php($selectedGenerationMode = old('generation_mode', $preselectedMode ?: 'full'))
                <select id="generation_mode" name="generation_mode" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                    <option value="full" @selected($selectedGenerationMode === 'full')>Full generate</option>
                    <option value="missing_only" @selected($selectedGenerationMode === 'missing_only')>Generate only missing fields</option>
                    <option value="regenerate" @selected($selectedGenerationMode === 'regenerate')>Regenerate selected sections</option>
                </select>
            </div>
        </div>

        {{-- Submit --}}
        <div class="flex items-center justify-between">
            <a href="{{ route('app.brand.company-profile') }}" class="text-sm text-textSecondary hover:text-textPrimary">
                Cancel
            </a>
            <button type="submit" class="inline-flex items-center gap-2 rounded-md bg-primary px-6 py-2.5 text-sm font-medium text-textInverse hover:bg-primaryHover">
                <i data-lucide="sparkles" class="h-4 w-4"></i>
                Generate Brand Content
            </button>
        </div>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const inputTypeInputs = document.querySelectorAll('input[name="input_type"]');
            const inputPanels = document.querySelectorAll('.input-panel');

            function updateVisiblePanel() {
                const selectedType = document.querySelector('input[name="input_type"]:checked').value;
                inputPanels.forEach(panel => {
                    panel.classList.add('hidden');
                });
                const targetPanel = document.getElementById('input-' + selectedType);
                if (targetPanel) {
                    targetPanel.classList.remove('hidden');
                }
            }

            inputTypeInputs.forEach(input => {
                input.addEventListener('change', updateVisiblePanel);
            });

            updateVisiblePanel();
        });
    </script>
@endsection
