@php
    $selectedTokens = (int) old('requested_max_output_tokens', $outputTokenOptions['standard'] ?? 8000);
    $formAction = $formAction ?? route('app.content.workspace.drafts.generate', $brief);
    $buttonLabel = $buttonLabel ?? 'Generate draft';
    $inputIdPrefix = $inputIdPrefix ?? 'requested_max_output_tokens';
@endphp

<form method="POST" action="{{ $formAction }}" class="space-y-2">
    @csrf
    <label class="block text-[11px] font-semibold uppercase tracking-[0.14em] text-textSecondary" for="{{ $inputIdPrefix }}_{{ $brief->id }}">Output size</label>
    <select id="{{ $inputIdPrefix }}_{{ $brief->id }}" name="requested_max_output_tokens" class="w-full rounded-lg border border-border bg-background px-3 py-3 text-sm text-textPrimary" data-credit-preview-select>
        <option value="{{ $outputTokenOptions['standard'] }}" @selected($selectedTokens === (int) $outputTokenOptions['standard'])>
            Standard ({{ number_format((int) $outputTokenOptions['standard']) }} tokens)
        </option>
        <option value="{{ $outputTokenOptions['long'] }}" @selected($selectedTokens === (int) $outputTokenOptions['long'])>
            Long ({{ number_format((int) $outputTokenOptions['long']) }} tokens)
        </option>
        @if ((int) $outputTokenOptions['max'] !== (int) $outputTokenOptions['long'])
            <option value="{{ $outputTokenOptions['max'] }}" @selected($selectedTokens === (int) $outputTokenOptions['max'])>
                Extended ({{ number_format((int) $outputTokenOptions['max']) }} tokens)
            </option>
        @endif
    </select>
    <div class="flex items-center justify-between gap-3 rounded-lg border border-border bg-background/80 px-3 py-2.5 text-xs">
        <div>
            <div class="font-semibold text-textPrimary">Estimated credits</div>
            <div class="text-textSecondary">Max {{ (int) $maxCredits }} credits per draft</div>
        </div>
        <div class="rounded-full border border-border bg-surface px-3 py-1 font-semibold text-textPrimary">
            <span data-credit-preview-label></span>
        </div>
    </div>
    <p class="text-xs leading-5 text-textSecondary">Generate the next draft revision using the current brief strategy, output settings, and content direction.</p>
    <button class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-transparent bg-textPrimary px-4 py-3 text-sm font-medium text-white transition hover:opacity-95">
        <i data-lucide="wand-sparkles" class="h-4 w-4" aria-hidden="true"></i>
        <span>{{ $buttonLabel }}</span>
    </button>
</form>
