<div class="space-y-6">
    <x-llm-tracking.analysis-card
        title="Raw response"
        description="Keep raw payloads available for debugging, but move them behind the final tab so analysis remains the primary experience."
        icon="braces"
    >
        @if (data_get($detail, 'raw.available'))
            <div class="space-y-3">
                @foreach ([
                    'answer_text' => 'Answer text',
                    'normalized_response' => 'Normalized response',
                    'parsed_payload' => 'Parsed payload',
                    'answer_json' => 'Answer JSON',
                    'raw_response' => 'Raw provider response',
                ] as $key => $label)
                    @php($value = (string) data_get($detail, 'raw.' . $key, ''))
                    <details class="group rounded-lg border border-border bg-background">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3">
                            <span class="text-sm font-medium text-textPrimary">{{ $label }}</span>
                            <i data-lucide="chevron-down" class="h-4 w-4 shrink-0 text-textMuted transition group-open:rotate-180"></i>
                        </summary>
                        <div class="border-t border-border px-4 py-4">
                            @if ($value !== '')
                                <pre class="overflow-x-auto whitespace-pre-wrap break-words text-xs leading-6 text-textSecondary">{{ $value }}</pre>
                            @else
                                <p class="text-sm text-textMuted">No data stored for this payload.</p>
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>
        @else
            <div class="rounded-lg border border-dashed border-border bg-background px-4 py-10 text-sm text-textMuted">
                No successful run yet, so no raw response is available.
            </div>
        @endif
    </x-llm-tracking.analysis-card>
</div>
