@php
    $textVariants = collect($successfulVariantRows ?? [])
        ->filter(fn (array $row): bool => trim((string) ($row['draft_html'] ?? '')) !== '')
        ->values();

    $providerColors = [
        'anthropic' => 'bg-orange-500/10 text-orange-600 border-orange-500/20',
        'openai' => 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20',
        'google' => 'bg-blue-500/10 text-blue-600 border-blue-500/20',
        'mistral' => 'bg-violet-500/10 text-violet-600 border-violet-500/20',
        'deepseek' => 'bg-cyan-500/10 text-cyan-600 border-cyan-500/20',
    ];
@endphp

<div id="full-text-compare" class="rounded-lg border border-border bg-surface p-6">
    <div class="flex flex-wrap items-center justify-between gap-4 mb-5">
        <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br from-primary/20 to-primary/10 text-primary">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>
            </div>
            <div>
                <h2 class="text-base font-semibold text-textPrimary">Full Text Comparison</h2>
                <p class="text-sm text-textSecondary">Compare complete draft outputs side by side.</p>
            </div>
        </div>
        @if ($textVariants->count() > 1)
            <div class="flex items-center gap-2 text-xs text-textSecondary">
                <svg class="h-4 w-4 text-textFaint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <span>{{ $textVariants->count() === 2 ? 'Side-by-side view' : 'Tab view' }}</span>
            </div>
        @endif
    </div>

    @if ($textVariants->isEmpty())
        <div class="text-center py-12">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-lg bg-background">
                <svg class="h-7 w-7 text-textSecondary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
            </div>
            <p class="text-sm font-medium text-textPrimary mb-1">Full drafts will appear here</p>
            <p class="text-sm text-textSecondary">Waiting for models to complete content generation.</p>
        </div>
    @elseif ($textVariants->count() === 1)
        @php($row = $textVariants->first())
        @php($providerKey = strtolower((string) ($row['provider'] ?? 'openai')))
        @php($providerColor = $providerColors[$providerKey] ?? 'bg-gray-500/10 text-gray-600 border-gray-500/20')
        <div class="rounded-lg border border-border bg-background overflow-hidden">
            <div class="flex items-center justify-between gap-3 px-5 py-3 border-b border-border bg-surfaceSubtle/50">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $providerColor }}">
                        {{ \Illuminate\Support\Str::headline($providerKey) }}
                    </span>
                    <span class="text-sm font-medium text-textPrimary">{{ $row['display_name'] }}</span>
                </div>
                @if (!empty($row['word_count']))
                    <span class="text-xs text-textSecondary">{{ number_format((int) $row['word_count']) }} words</span>
                @endif
            </div>
            <div class="p-5">
                <x-content.rendered-article :content="$row['draft_html']" compact />
            </div>
        </div>
    @elseif ($textVariants->count() === 2)
        {{-- Side by side for 2 models --}}
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($textVariants as $row)
                @php($providerKey = strtolower((string) ($row['provider'] ?? 'openai')))
                @php($providerColor = $providerColors[$providerKey] ?? 'bg-gray-500/10 text-gray-600 border-gray-500/20')
                <div class="rounded-lg border border-border bg-background overflow-hidden flex flex-col">
                    <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-border bg-surfaceSubtle/50">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $providerColor }}">
                                {{ \Illuminate\Support\Str::headline($providerKey) }}
                            </span>
                            <span class="text-xs font-medium text-textPrimary truncate">{{ $row['model'] }}</span>
                        </div>
                        @if (!empty($row['word_count']))
                            <span class="text-[10px] text-textSecondary shrink-0">{{ number_format((int) $row['word_count']) }} words</span>
                        @endif
                    </div>
                    <div class="p-4 flex-1 overflow-y-auto max-h-[600px]">
                        <x-content.rendered-article :content="$row['draft_html']" compact />
                    </div>
                </div>
            @endforeach
        </div>
    @else
        {{-- Tabs for 3+ models --}}
        <div data-fulltext-tabs>
            <div class="flex flex-wrap gap-2 mb-4 p-1 bg-background rounded-lg border border-border" role="tablist" aria-label="Variant tabs">
                @foreach ($textVariants as $index => $row)
                    @php($providerKey = strtolower((string) ($row['provider'] ?? 'openai')))
                    @php($providerColor = $providerColors[$providerKey] ?? 'bg-gray-500/10 text-gray-600 border-gray-500/20')
                    <button
                        type="button"
                        class="flex items-center gap-2 rounded-lg px-4 py-2.5 text-xs font-medium transition-all {{ $index === 0 ? 'bg-surface shadow-sm text-textPrimary' : 'text-textSecondary hover:text-textPrimary hover:bg-surfaceSubtle' }}"
                        data-fulltext-tab
                        data-target="variant-{{ $row['id'] }}"
                    >
                        <span class="inline-flex items-center rounded border px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide {{ $providerColor }}">
                            {{ \Illuminate\Support\Str::headline($providerKey) }}
                        </span>
                        <span class="hidden sm:inline">{{ $row['model'] }}</span>
                    </button>
                @endforeach
            </div>

            @foreach ($textVariants as $index => $row)
                @php($providerKey = strtolower((string) ($row['provider'] ?? 'openai')))
                @php($providerColor = $providerColors[$providerKey] ?? 'bg-gray-500/10 text-gray-600 border-gray-500/20')
                <div
                    class="rounded-lg border border-border bg-background overflow-hidden {{ $index === 0 ? '' : 'hidden' }}"
                    data-fulltext-panel="variant-{{ $row['id'] }}"
                >
                    <div class="flex items-center justify-between gap-3 px-5 py-3 border-b border-border bg-surfaceSubtle/50">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $providerColor }}">
                                {{ \Illuminate\Support\Str::headline($providerKey) }}
                            </span>
                            <span class="text-sm font-medium text-textPrimary">{{ $row['display_name'] }}</span>
                        </div>
                        <div class="flex items-center gap-4 text-xs text-textSecondary">
                            @if (!empty($row['word_count']))
                                <span>{{ number_format((int) $row['word_count']) }} words</span>
                            @endif
                            @if (!empty($row['reading_time']))
                                <span>{{ (int) $row['reading_time'] }} min read</span>
                            @endif
                        </div>
                    </div>
                    <div class="p-5 max-h-[700px] overflow-y-auto">
                        <x-content.rendered-article :content="$row['draft_html']" compact />
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Quick navigation hint --}}
    @if ($textVariants->isNotEmpty())
        <div class="mt-5 pt-5 border-t border-border/50 flex flex-wrap items-center gap-4 text-xs text-textSecondary">
            <span class="flex items-center gap-1.5">
                <svg class="h-4 w-4 text-textFaint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                Scroll to compare structure, introduction, body, and conclusion across models.
            </span>
            @if ($textVariants->count() > 2)
                <span class="flex items-center gap-1.5">
                    <svg class="h-4 w-4 text-textFaint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7" /></svg>
                    Click tabs to switch between models.
                </span>
            @endif
        </div>
    @endif
</div>
