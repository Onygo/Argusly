@php
    $status = (string) ($variant['status'] ?? 'pending');
    $isWinner = (bool) ($isWinner ?? false);
    $isSuccess = !empty($variant['is_success']);
    $isProcessing = !empty($variant['is_processing']);
    $isFailed = !empty($variant['is_failed']);
    $providerKey = strtolower((string) ($variant['provider'] ?? 'openai'));

    $statusConfig = match ($status) {
        'completed' => ['class' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700', 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'label' => 'Completed'],
        'failed', 'cancelled' => ['class' => 'border-rose-500/30 bg-rose-500/10 text-rose-700', 'icon' => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z', 'label' => ucfirst($status)],
        'processing' => ['class' => 'border-sky-500/30 bg-sky-500/10 text-sky-700', 'icon' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15', 'label' => 'Generating...'],
        'queued', 'pending' => ['class' => 'border-amber-500/30 bg-amber-500/10 text-amber-700', 'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'label' => 'Queued'],
        default => ['class' => 'border-border bg-background text-textSecondary', 'icon' => 'M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'label' => ucfirst($status)],
    };

    $providerColors = [
        'anthropic' => 'bg-orange-500/10 text-orange-600 border-orange-500/20',
        'openai' => 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20',
        'google' => 'bg-blue-500/10 text-blue-600 border-blue-500/20',
        'mistral' => 'bg-violet-500/10 text-violet-600 border-violet-500/20',
        'deepseek' => 'bg-cyan-500/10 text-cyan-600 border-cyan-500/20',
    ];
    $providerColor = $providerColors[$providerKey] ?? 'bg-gray-500/10 text-gray-600 border-gray-500/20';
@endphp

<div class="rounded-lg border-2 {{ $isWinner ? 'border-emerald-500/50 bg-gradient-to-br from-emerald-500/5 via-surface to-emerald-500/5 shadow-lg shadow-emerald-500/10' : 'border-border bg-surface hover:border-primary/30' }} p-6 transition-all" data-variant-id="{{ $variant['id'] }}">
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
        <div class="flex items-start gap-4">
            {{-- Provider icon --}}
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg {{ $isSuccess ? 'bg-gradient-to-br from-primary/20 to-primary/10 text-primary' : ($isFailed ? 'bg-rose-500/10 text-rose-600' : ($isProcessing ? 'bg-sky-500/10 text-sky-600' : 'bg-background text-textSecondary')) }}">
                @if ($isProcessing)
                    <svg class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                @elseif ($isFailed)
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                @elseif ($isSuccess)
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                @else
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                @endif
            </div>

            <div>
                <div class="flex flex-wrap items-center gap-2 mb-1">
                    <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $providerColor }}">
                        {{ \Illuminate\Support\Str::headline($providerKey) }}
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-medium {{ $statusConfig['class'] }}">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $statusConfig['icon'] }}" /></svg>
                        {{ $statusConfig['label'] }}
                    </span>
                    @if ($isWinner)
                        <span class="inline-flex items-center gap-1 rounded-full bg-gradient-to-r from-emerald-500/20 to-emerald-500/10 border border-emerald-500/30 px-2.5 py-0.5 text-[10px] font-semibold text-emerald-700 uppercase tracking-wide">
                            <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                            Winner
                        </span>
                    @endif
                </div>
                <h3 class="text-base font-semibold text-textPrimary">{{ $variant['display_name'] }}</h3>
                <p class="mt-0.5 text-xs text-textSecondary">
                    {{ $variant['model'] }}
                </p>
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex flex-wrap gap-2">
            @if (!empty($variant['draft_id']))
                @if ($isSuccess)
                    <a href="{{ route('app.drafts.show', $variant['draft_id']) }}" class="rounded-lg bg-gradient-to-r from-primary to-primary/90 px-4 py-2 text-xs font-semibold text-white shadow-md shadow-primary/20 hover:shadow-lg hover:shadow-primary/30 transition-all flex items-center gap-1.5">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        Use this draft
                    </a>
                    @unless ($isWinner)
                        <form method="POST" action="{{ route('app.content.workspace.compare.select-winner', [$brief, $comparison]) }}">
                            @csrf
                            <input type="hidden" name="draft_id" value="{{ $variant['draft_id'] }}">
                            <button class="rounded-lg border border-border px-4 py-2 text-xs font-medium hover:bg-surfaceSubtle hover:border-primary/30 transition-all flex items-center gap-1.5">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" /></svg>
                                Set as winner
                            </button>
                        </form>
                    @endunless
                @endif
                <a href="{{ route('app.drafts.show', $variant['draft_id']) }}" class="rounded-lg border border-border px-4 py-2 text-xs font-medium hover:bg-surfaceSubtle transition-colors flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                    View full draft
                </a>
            @endif
        </div>
    </div>

    {{-- Draft title preview --}}
    @if ($isSuccess && !empty($variant['draft_title']) && $variant['draft_title'] !== 'Untitled draft')
        <div class="mb-4 rounded-lg bg-gradient-to-r from-primary/5 to-transparent border-l-4 border-primary px-4 py-3">
            <p class="text-sm font-medium text-textPrimary">{{ $variant['draft_title'] }}</p>
        </div>
    @endif

    {{-- Metrics grid --}}
    @if ($isSuccess || $isProcessing)
        <div class="grid grid-cols-5 gap-2 mb-5">
            <div class="rounded-lg border border-border bg-background/80 px-3 py-2.5 text-center">
                <p class="text-lg font-bold text-textPrimary">{{ number_format((int) ($variant['word_count'] ?? 0)) }}</p>
                <p class="text-[10px] font-medium text-textSecondary uppercase tracking-wide">Words</p>
            </div>
            <div class="rounded-lg border border-border bg-background/80 px-3 py-2.5 text-center">
                <p class="text-lg font-bold text-textPrimary">
                    {{ isset($variant['reading_time']) && $variant['reading_time'] !== null ? (int) $variant['reading_time'] : '-' }}
                </p>
                <p class="text-[10px] font-medium text-textSecondary uppercase tracking-wide">Min read</p>
            </div>
            <div class="rounded-lg border border-border bg-background/80 px-3 py-2.5 text-center">
                <p class="text-lg font-bold text-textPrimary">
                    {{ $variant['input_tokens'] !== null ? number_format((int) $variant['input_tokens']) : '-' }}
                </p>
                <p class="text-[10px] font-medium text-textSecondary uppercase tracking-wide">In tokens</p>
            </div>
            <div class="rounded-lg border border-border bg-background/80 px-3 py-2.5 text-center">
                <p class="text-lg font-bold text-textPrimary">
                    {{ $variant['output_tokens'] !== null ? number_format((int) $variant['output_tokens']) : '-' }}
                </p>
                <p class="text-[10px] font-medium text-textSecondary uppercase tracking-wide">Out tokens</p>
            </div>
            <div class="rounded-lg border border-border bg-background/80 px-3 py-2.5 text-center">
                <p class="text-lg font-bold text-primary">
                    {{ $variant['credit_cost'] !== null ? (int) $variant['credit_cost'] : '-' }}
                </p>
                <p class="text-[10px] font-medium text-textSecondary uppercase tracking-wide">Credits</p>
            </div>
        </div>
    @endif

    {{-- Score chips --}}
    @if (!empty($variant['score_chips']))
        <div class="mb-4">
            <div class="flex items-center gap-2 mb-2.5">
                <svg class="h-4 w-4 text-textFaint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                <span class="text-xs font-medium text-textSecondary">Quality scores</span>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach ($variant['score_chips'] as $chip)
                    @php
                        $chipValue = is_numeric($chip['value']) ? (float) $chip['value'] : 0;
                        $chipColorClass = match (true) {
                            $chipValue >= 80 => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700',
                            $chipValue >= 60 => 'border-sky-500/30 bg-sky-500/10 text-sky-700',
                            $chipValue >= 40 => 'border-amber-500/30 bg-amber-500/10 text-amber-700',
                            default => 'border-border bg-background text-textSecondary',
                        };
                    @endphp
                    <span class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-medium {{ $chipColorClass }}">
                        {{ $chip['label'] }}
                        <span class="font-bold">{{ $chip['value'] }}</span>
                    </span>
                @endforeach
            </div>
            @if (!empty($variant['score_source_summary']))
                <p class="mt-2 text-[10px] text-textFaint flex items-center gap-1.5">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    Scoring basis:
                    {{ collect($variant['score_source_summary'])->map(fn($count, $source) => $count . ' ' . str_replace('_', ' ', $source))->implode(' · ') }}
                </p>
            @endif
        </div>
    @elseif ($isSuccess)
        <div class="mb-4 flex items-center gap-2 text-xs text-textSecondary rounded-lg bg-background/50 border border-border/50 px-3 py-2">
            <svg class="h-4 w-4 animate-pulse text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <span>Quality scores are being calculated...</span>
        </div>
    @endif

    {{-- Error message --}}
    @if (!empty($variant['error_message']))
        <div class="flex items-start gap-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 mb-4">
            <svg class="h-5 w-5 shrink-0 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <div>
                <p class="text-sm font-medium text-rose-800">Generation failed</p>
                <p class="text-xs text-rose-700 mt-0.5">{{ $variant['error_message'] }}</p>
            </div>
        </div>
    @endif

    {{-- Content preview --}}
    <div class="rounded-lg border border-border bg-gradient-to-br from-background to-surfaceSubtle p-4">
        @if (!empty($variant['draft_excerpt']))
            <p class="text-sm text-textPrimary leading-relaxed">{{ $variant['draft_excerpt'] }}</p>
            @if ($isSuccess && !empty($variant['draft_id']))
                <a href="#full-text-compare" class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:text-primary/80 transition-colors">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>
                    Compare full text
                </a>
            @endif
        @elseif ($isProcessing)
            <div class="flex items-center gap-3 text-sm text-textSecondary">
                <svg class="h-5 w-5 animate-spin text-primary" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                <span>Generating content with this model...</span>
            </div>
        @elseif ($isFailed)
            <p class="text-sm text-textSecondary">Content generation failed for this model. Try running a new comparison.</p>
        @else
            <p class="text-sm text-textSecondary">Content preview will appear here once generation completes.</p>
        @endif
    </div>

    {{-- Prompt snapshot (subtle) --}}
    @if (!empty($variant['prompt_snapshot_summary']))
        <div class="mt-3 flex flex-wrap items-center gap-2 text-[10px] text-textFaint">
            <span class="inline-flex items-center gap-1 rounded bg-background/50 px-1.5 py-0.5">
                <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129" /></svg>
                {{ data_get($variant, 'prompt_snapshot_summary.language', 'n/a') }}
            </span>
            <span class="inline-flex items-center gap-1 rounded bg-background/50 px-1.5 py-0.5">
                <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" /></svg>
                {{ data_get($variant, 'prompt_snapshot_summary.primary_keyword', 'no keyword') }}
            </span>
            <span class="inline-flex items-center gap-1 rounded bg-background/50 px-1.5 py-0.5">
                <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                {{ \Illuminate\Support\Str::limit((string) data_get($variant, 'prompt_snapshot_summary.shared_inputs_hash', 'n/a'), 8, '') }}
            </span>
        </div>
    @endif
</div>
