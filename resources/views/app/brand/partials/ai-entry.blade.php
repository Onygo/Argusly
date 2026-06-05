@props([
    'section',
    'manualTarget' => 'manual',
    'latestBrandContext' => null,
    'title' => 'AI-assisted setup',
    'description' => 'Generate and enrich this section from website content, brand notes, or a short guided input.',
])

@php
    $rt = $rt ?? function (string $value, array $replace = []): string {
        $key = 'app.runtime.'.$value;
        $translated = __($key, $replace);

        return $translated === $key ? strtr($value, collect($replace)->mapWithKeys(fn ($replacement, $placeholder) => [':'.$placeholder => $replacement])->all()) : $translated;
    };
@endphp

<div class="rounded-lg border border-border bg-surface p-5">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="max-w-3xl">
            <h2 class="text-lg font-semibold text-textPrimary">{{ $title }}</h2>
            <p class="mt-1 text-sm text-textSecondary">{{ $description }}</p>
            @if ($latestBrandContext)
                <p class="mt-3 text-xs text-textSecondary">
                    {{ $rt('Latest AI context: :source on :date', ['source' => $latestBrandContext->source_type, 'date' => optional($latestBrandContext->created_at)->format('Y-m-d H:i')]) }}
                </p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a
                href="{{ route('app.brand.wizard', ['section' => $section]) }}"
                class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse hover:bg-primaryHover"
            >
                {{ $rt('Generate with AI') }}
            </a>
            <a
                href="#{{ $manualTarget }}"
                class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle"
            >
                {{ $rt('Fill manually') }}
            </a>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3 text-xs">
        <a href="{{ route('app.brand.wizard', ['section' => $section, 'mode' => 'missing_only']) }}" class="text-primary hover:underline">
            {{ $rt('Generate only missing fields') }}
        </a>
        <a href="{{ route('app.brand.wizard', ['section' => $section, 'mode' => 'regenerate']) }}" class="text-primary hover:underline">
            {{ $rt('Regenerate section') }}
        </a>
    </div>
</div>
