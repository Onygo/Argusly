@php
    $answerBlockInjector = app(\App\Services\Content\AnswerBlockInjectorService::class);
    $answerBlockSchema = app(\App\Services\Content\AnswerBlockSchemaService::class);
    $visibleQuestions = $answerBlockInjector->visibleBlocks($content)
        ->map(fn (\App\Models\StructuredAnswerBlock $block): string => mb_strtolower(trim((string) $block->question)))
        ->values()
        ->all();
    $schemaQuestions = collect((array) data_get($answerBlockSchema->forContent($content), 'mainEntity', []))
        ->map(fn (array $item): string => mb_strtolower(trim((string) ($item['name'] ?? ''))))
        ->filter()
        ->values()
        ->all();
@endphp

<div id="answer-block-list" class="space-y-4">
    @forelse ($content->answerBlocks as $block)
        <div class="rounded-lg border border-border bg-background p-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-textSecondary">Block {{ $loop->iteration }} · Order {{ $block->order }}</p>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('app.content.answer-blocks.move', ['content' => $content, 'block' => $block]) }}">
                        @csrf
                        <input type="hidden" name="direction" value="up">
                        <button class="rounded border border-border px-2 py-1 text-xs">Up</button>
                    </form>
                    <form method="POST" action="{{ route('app.content.answer-blocks.move', ['content' => $content, 'block' => $block]) }}">
                        @csrf
                        <input type="hidden" name="direction" value="down">
                        <button class="rounded border border-border px-2 py-1 text-xs">Down</button>
                    </form>
                    <form method="POST" action="{{ route('app.content.answer-blocks.destroy', ['content' => $content, 'block' => $block]) }}">
                        @csrf
                        @method('DELETE')
                        <button class="rounded border border-rose-200 px-2 py-1 text-xs text-rose-700">Delete</button>
                    </form>
                </div>
            </div>
            @php
                $normalizedQuestion = mb_strtolower(trim((string) $block->question));
                $visibleInArticle = in_array($normalizedQuestion, $visibleQuestions, true);
                $includedInSchema = in_array($normalizedQuestion, $schemaQuestions, true);
            @endphp
            <div class="mb-3 flex flex-wrap gap-2 text-xs">
                <span class="rounded-full border px-2.5 py-1 {{ $visibleInArticle ? 'border-emerald-200 text-emerald-700' : 'border-slate-200 text-slate-600' }}">
                    {{ $visibleInArticle ? 'Visible in article' : 'Hidden from article' }}
                </span>
                <span class="rounded-full border px-2.5 py-1 {{ $includedInSchema ? 'border-sky-200 text-sky-700' : 'border-slate-200 text-slate-600' }}">
                    {{ $includedInSchema ? 'Included in FAQ schema' : 'Excluded from FAQ schema' }}
                </span>
            </div>
            <form method="POST" action="{{ route('app.content.answer-blocks.update', ['content' => $content, 'block' => $block]) }}" class="grid gap-3">
                @csrf
                @method('PUT')
                <input type="text" name="question" value="{{ old('question_'.$block->id, $block->question) }}" class="w-full rounded border border-border bg-surface px-3 py-2 text-sm" required>
                <textarea name="answer" rows="4" class="w-full rounded border border-border bg-surface px-3 py-2 text-sm" required>{{ old('answer_'.$block->id, $block->answer) }}</textarea>
                <input type="text" name="entities" value="{{ old('entities_'.$block->id, implode(', ', (array) $block->entities)) }}" class="w-full rounded border border-border bg-surface px-3 py-2 text-sm">
                <input type="text" name="platforms" value="{{ old('platforms_'.$block->id, implode(', ', (array) $block->platforms)) }}" class="w-full rounded border border-border bg-surface px-3 py-2 text-sm">
                <div>
                    <button class="rounded border border-border px-3 py-2 text-sm">Save block</button>
                </div>
            </form>
        </div>
    @empty
        @php
            $status = (string) ($content->answer_block_generation_status ?? '');
            $lastWarning = trim((string) ($content->answer_block_generation_last_warning ?? ''));
            $lastError = trim((string) ($content->answer_block_generation_last_error ?? ''));
            $emptyMessage = match ($status) {
                \App\Models\Content::ANSWER_BLOCK_STATUS_QUEUED,
                \App\Models\Content::ANSWER_BLOCK_STATUS_RUNNING => 'Generating answer blocks…',
                \App\Models\Content::ANSWER_BLOCK_STATUS_COMPLETED_WITH_WARNING => $lastWarning !== '' ? $lastWarning : 'The job completed without creating answer blocks.',
                \App\Models\Content::ANSWER_BLOCK_STATUS_FAILED => $lastError !== '' ? $lastError : 'Answer block generation failed.',
                default => 'No answer blocks yet. Generate them or add them manually.',
            };
        @endphp
        <div class="rounded-lg border border-dashed border-border bg-background p-6 text-sm text-textSecondary">
            {{ $emptyMessage }}
        </div>
    @endforelse
</div>
