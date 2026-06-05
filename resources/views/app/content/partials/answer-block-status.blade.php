@php
    $status = (string) ($content->answer_block_generation_status ?? '');
    $persistedCount = (int) ($content->answer_block_generation_persisted_count ?? $content->answerBlocks->count());
    $lastError = trim((string) ($content->answer_block_generation_last_error ?? ''));
    $lastWarning = trim((string) ($content->answer_block_generation_last_warning ?? ''));
    $startedAt = $content->answer_block_generation_started_at;
    $completedAt = $content->answer_block_generation_completed_at;
    $failedAt = $content->answer_block_generation_failed_at;
    $isActive = $content->answerBlockGenerationIsActive();
    $generationMeta = is_array($content->answer_block_generation_meta) ? $content->answer_block_generation_meta : [];
    $isAdminViewer = auth()->user()?->isAdminAreaUser() === true;
    $renderMode = app(\App\Services\Content\AnswerBlockInjectorService::class)->resolveRenderMode($content);
    $maxVisible = app(\App\Services\Content\AnswerBlockInjectorService::class)->resolveMaxVisible($content);
    $tone = match ($status) {
        \App\Models\Content::ANSWER_BLOCK_STATUS_RUNNING => 'border-sky-200 bg-sky-50 text-sky-800',
        \App\Models\Content::ANSWER_BLOCK_STATUS_QUEUED => 'border-amber-200 bg-amber-50 text-amber-900',
        \App\Models\Content::ANSWER_BLOCK_STATUS_COMPLETED => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        \App\Models\Content::ANSWER_BLOCK_STATUS_COMPLETED_WITH_WARNING => 'border-amber-200 bg-amber-50 text-amber-900',
        \App\Models\Content::ANSWER_BLOCK_STATUS_FAILED => 'border-rose-200 bg-rose-50 text-rose-800',
        default => 'border-slate-200 bg-slate-50 text-slate-700',
    };
    $headline = match ($status) {
        \App\Models\Content::ANSWER_BLOCK_STATUS_QUEUED => 'Generating answer blocks…',
        \App\Models\Content::ANSWER_BLOCK_STATUS_RUNNING => 'Generating answer blocks…',
        \App\Models\Content::ANSWER_BLOCK_STATUS_COMPLETED => 'Generated ' . $persistedCount . ' answer block' . ($persistedCount === 1 ? '' : 's') . '.',
        \App\Models\Content::ANSWER_BLOCK_STATUS_COMPLETED_WITH_WARNING => $lastWarning !== '' ? $lastWarning : 'The job finished without creating visible answer blocks.',
        \App\Models\Content::ANSWER_BLOCK_STATUS_FAILED => $lastError !== '' ? $lastError : 'Answer block generation failed.',
        default => $content->answerBlocks->isEmpty() ? 'No answer blocks yet.' : 'Generated ' . $content->answerBlocks->count() . ' answer block' . ($content->answerBlocks->count() === 1 ? '' : 's') . '.',
    };
    $metaLine = match ($status) {
        \App\Models\Content::ANSWER_BLOCK_STATUS_QUEUED => 'Queued on the generation workers.',
        \App\Models\Content::ANSWER_BLOCK_STATUS_RUNNING => $startedAt ? 'Started ' . $startedAt->diffForHumans() . '.' : 'The AI worker is generating blocks now.',
        \App\Models\Content::ANSWER_BLOCK_STATUS_COMPLETED => $completedAt ? 'Completed ' . $completedAt->diffForHumans() . '.' : 'Saved to this content item.',
        \App\Models\Content::ANSWER_BLOCK_STATUS_COMPLETED_WITH_WARNING => 'Retry generation or add blocks manually.',
        \App\Models\Content::ANSWER_BLOCK_STATUS_FAILED => $failedAt ? 'Failed ' . $failedAt->diffForHumans() . '. Retry generation to try again.' : 'Retry generation to try again.',
        default => 'Generate them with AI or add them manually.',
    };
@endphp

<div id="answer-block-status" class="rounded-lg border {{ $tone }} p-4">
    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <h3 class="text-sm font-semibold">Answer block generation</h3>
                @if ($isActive)
                    <span class="inline-flex h-2 w-2 animate-pulse rounded-full bg-current"></span>
                @endif
            </div>
            <p class="mt-2 text-sm">{{ $headline }}</p>
            <p class="mt-1 text-xs opacity-80">{{ $metaLine }}</p>
            <p class="mt-2 text-xs opacity-80">
                Render mode: {{ $renderMode }} · Max visible: {{ $maxVisible }}
            </p>
            @if ($isAdminViewer && $generationMeta !== [])
                <div class="mt-3 rounded border border-current/20 bg-white/50 px-3 py-2 text-xs opacity-90">
                    <div>Provider: {{ data_get($generationMeta, 'provider', 'n/a') }} / {{ data_get($generationMeta, 'model', 'n/a') }}</div>
                    <div>Draft: {{ data_get($generationMeta, 'draft_revision_id', 'n/a') }}</div>
                    <div>Prompt chars: {{ (int) data_get($generationMeta, 'prompt_length', 0) }} | Raw chars: {{ (int) data_get($generationMeta, 'raw_response_length', 0) }}</div>
                    <div>Raw: {{ (int) data_get($generationMeta, 'raw_block_count', 0) }} | Parsed: {{ (int) data_get($generationMeta, 'parsed_block_count', 0) }} | Accepted: {{ (int) data_get($generationMeta, 'accepted_block_count', 0) }} | Rejected: {{ (int) data_get($generationMeta, 'rejected_block_count', 0) }}</div>
                    <div>Saved IDs: {{ implode(', ', (array) data_get($generationMeta, 'saved_block_ids', [])) ?: 'none' }}</div>
                    <div>Failure reason: {{ data_get($generationMeta, 'failure_reason_message', 'none') }}</div>
                    @if ((array) data_get($generationMeta, 'rejection_reasons', []) !== [])
                        <div>Rejections: {{ json_encode(data_get($generationMeta, 'rejection_reasons', []), JSON_UNESCAPED_SLASHES) }}</div>
                    @endif
                </div>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            @can('update', $content)
                @if (in_array($status, [\App\Models\Content::ANSWER_BLOCK_STATUS_FAILED, \App\Models\Content::ANSWER_BLOCK_STATUS_COMPLETED_WITH_WARNING], true))
                    <form method="POST" action="{{ route('app.content.answer-blocks.generate', $content) }}" data-answer-block-generate-form>
                        @csrf
                        <button type="submit" class="rounded border border-current px-3 py-2 text-xs font-medium" {{ $isActive ? 'disabled' : '' }}>
                            Retry generation
                        </button>
                    </form>
                @endif
            @endcan
        </div>
    </div>
</div>
