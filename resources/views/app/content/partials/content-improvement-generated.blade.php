@php
    $recentRuns = collect($contentImprovementDashboard['recent'] ?? []);
@endphp

<div id="content-improvement-generated" class="mt-5">
    <div class="flex items-start justify-between gap-3">
        <div>
            <div class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">Generated improvements</div>
            <h4 class="mt-1 text-base font-semibold text-textPrimary">Generated improvements</h4>
            <p class="mt-1 text-sm text-textSecondary">Completed and failed AI improvements with diff previews and review actions.</p>
        </div>
    </div>

    <div class="mt-4 space-y-4">
        @forelse ($recentRuns as $run)
            @php
                $payload = (array) ($run->result_payload ?? []);
                $diagnostics = (array) ($run->diagnostics ?? []);
                $targetDraft = $run->targetDraft ?? null;
                $sourceDraft = $run->sourceDraft ?? null;
                $showApplyAction = $run->status === \App\Models\ContentImprovementRun::STATUS_COMPLETED
                    && $run->applied_at === null
                    && $targetDraft
                    && $sourceDraft
                    && (string) $sourceDraft->id !== (string) $targetDraft->id;
            @endphp
            <div id="content-improvement-run-{{ $run->id }}" class="rounded-2xl border border-border/70 bg-white p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-medium text-textPrimary">{{ $run->recommendation_label ?: \Illuminate\Support\Str::headline((string) $run->type) }}</div>
                        <div class="mt-1 text-xs text-textSecondary">
                            {{ ucfirst((string) $run->status) }}
                            @if ($run->completed_at)
                                · {{ $run->completed_at->diffForHumans() }}
                            @elseif ($run->failed_at)
                                · {{ $run->failed_at->diffForHumans() }}
                            @endif
                        </div>
                    </div>
                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-medium
                        {{ $run->status === 'completed' ? 'bg-emerald-50 text-emerald-700' : '' }}
                        {{ $run->status === 'no_changes' ? 'bg-amber-50 text-amber-800' : '' }}
                        {{ $run->status === 'failed' ? 'bg-rose-50 text-rose-700' : '' }}
                        {{ $run->status === 'cancelled' ? 'bg-slate-100 text-slate-700' : '' }}">
                        {{ $run->status === 'no_changes' ? 'No changes generated' : ucfirst((string) $run->status) }}
                    </span>
                </div>

                @if ($run->status === 'failed')
                    <div class="mt-3 rounded-2xl bg-rose-50 px-4 py-3 text-sm text-rose-700">
                        {{ $run->error_message ?: 'Improvement generation failed.' }}
                    </div>
                @endif

                @if ($run->status === 'no_changes')
                    <div class="mt-3 rounded-2xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        <div class="font-medium">No useful changes generated</div>
                        <div class="mt-1">{{ $run->error_message ?: ($diagnostics['no_changes_reason'] ?? 'The generated output did not produce a meaningful content diff.') }}</div>
                    </div>
                @endif

                @if (filled($run->generated_summary ?? null) || filled($payload['change_summary'] ?? null))
                    <p class="mt-3 text-sm text-textPrimary">{{ $run->generated_summary ?: $payload['change_summary'] }}</p>
                @endif

                @if (filled($run->diff_summary ?? null))
                    <p class="mt-2 text-xs text-textSecondary">{{ $run->diff_summary }}</p>
                @endif

                @if ($targetDraft || $sourceDraft)
                    <div class="mt-4 grid gap-3 md:grid-cols-3">
                        <div class="rounded-2xl bg-slate-50 p-4">
                            <div class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">Updated draft</div>
                            <div class="mt-2 text-sm font-medium text-textPrimary">
                                @if ($targetDraft)
                                    Draft {{ \Illuminate\Support\Str::limit((string) $targetDraft->title, 42) }}
                                @else
                                    Draft unavailable
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-textSecondary">
                                {{ $run->status === 'completed' ? 'This generated result was saved into a reviewable draft immediately.' : 'No editable review draft was updated.' }}
                            </p>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-4">
                            <div class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">Source revision</div>
                            <div class="mt-2 text-sm font-medium text-textPrimary">{{ $run->source_revision_hash ? substr((string) $run->source_revision_hash, 0, 10) : 'n/a' }}</div>
                            <p class="mt-1 text-xs text-textSecondary">Logged for duplicate prevention and debugging.</p>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-4">
                            <div class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">Output revision</div>
                            <div class="mt-2 text-sm font-medium text-textPrimary">{{ $run->output_revision_hash ? substr((string) $run->output_revision_hash, 0, 10) : 'n/a' }}</div>
                            <p class="mt-1 text-xs text-textSecondary">
                                @if ($run->status === 'completed')
                                    Review the generated draft to inspect the exact edited content.
                                @else
                                    No output revision was persisted.
                                @endif
                            </p>
                        </div>
                    </div>
                @endif

                @if (filled($payload['inserted_text'] ?? null) || filled($payload['removed_text'] ?? null))
                    <div class="mt-4 grid gap-3 lg:grid-cols-2">
                        <div class="rounded-2xl bg-emerald-50 p-4">
                            <div class="text-xs font-medium uppercase tracking-[0.18em] text-emerald-700">Inserted text</div>
                            <p class="mt-2 text-sm text-emerald-900">{{ $payload['inserted_text'] ?: 'No inserted text captured.' }}</p>
                        </div>
                        <div class="rounded-2xl bg-rose-50 p-4">
                            <div class="text-xs font-medium uppercase tracking-[0.18em] text-rose-700">Removed text</div>
                            <p class="mt-2 text-sm text-rose-900">{{ $payload['removed_text'] ?: 'No removed text captured.' }}</p>
                        </div>
                    </div>
                @endif

                @if (filled($payload['diff_preview_html'] ?? null))
                    <div class="mt-4 rounded-2xl bg-slate-50 p-4">
                        <div class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">Diff preview</div>
                        <div class="mt-3 text-sm leading-7 text-textPrimary">{!! $payload['diff_preview_html'] !!}</div>
                    </div>
                @endif

                @if (! empty($payload['change_notes'] ?? []))
                    <div class="mt-4">
                        <div class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">Change notes</div>
                        <ul class="mt-2 space-y-2 text-sm text-textSecondary">
                            @foreach (($payload['change_notes'] ?? []) as $note)
                                <li class="flex gap-2">
                                    <span class="mt-1 h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                    <span>{{ $note }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <dl class="mt-4 grid gap-2 text-xs text-textSecondary sm:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <dt class="text-textFaint">Score impact</dt>
                        <dd class="mt-1 text-textPrimary">
                            @if ($run->before_score !== null || $run->after_score !== null)
                                {{ $run->before_score ?? 'n/a' }} → {{ $run->after_score ?? 'n/a' }}
                            @else
                                n/a
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-textFaint">Queue name</dt>
                        <dd class="mt-1 text-textPrimary">{{ $diagnostics['queue_name'] ?? 'generation' }}</dd>
                    </div>
                    <div>
                        <dt class="text-textFaint">Retry count</dt>
                        <dd class="mt-1 text-textPrimary">{{ (int) ($diagnostics['retry_count'] ?? 0) }}</dd>
                    </div>
                    <div>
                        <dt class="text-textFaint">Elapsed time</dt>
                        <dd class="mt-1 text-textPrimary">{{ isset($diagnostics['elapsed_seconds']) ? ((int) $diagnostics['elapsed_seconds']) . 's' : 'n/a' }}</dd>
                    </div>
                    <div>
                        <dt class="text-textFaint">Failure reason</dt>
                        <dd class="mt-1 text-textPrimary">{{ $diagnostics['failure_reason'] ?? ($run->error_message ?: 'None') }}</dd>
                    </div>
                </dl>

                @if ($run->status === \App\Models\ContentImprovementRun::STATUS_COMPLETED || $run->status === \App\Models\ContentImprovementRun::STATUS_NO_CHANGES)
                    <div class="mt-4 flex flex-wrap gap-2">
                        @if ($targetDraft)
                            <a href="{{ route('app.drafts.show', ['draft' => $targetDraft]) }}" class="inline-flex items-center justify-center gap-2 rounded-full bg-textPrimary px-4 py-2 text-xs font-medium text-white hover:opacity-90">
                                Review draft
                            </a>
                        @endif
                        @if (filled($payload['diff_preview_html'] ?? null))
                            <a href="#content-improvement-run-{{ $run->id }}" class="inline-flex items-center justify-center gap-2 rounded-full border border-border px-4 py-2 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">
                                View diff
                            </a>
                        @endif
                        @if ($showApplyAction)
                            <form method="POST" action="{{ route('app.content.improvements.accept', [$content, $run]) }}" data-content-improvement-accept-form>
                                @csrf
                                <button class="inline-flex items-center justify-center gap-2 rounded-full border border-border px-4 py-2 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">
                                    Apply to current draft
                                </button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('app.content.improvements.queue', $content) }}" data-content-improvement-form>
                            @csrf
                            <input type="hidden" name="type" value="{{ $run->type }}">
                            <input type="hidden" name="recommendation" value="{{ $run->recommendation_label }}">
                            <button class="inline-flex items-center justify-center gap-2 rounded-full border border-border px-4 py-2 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">
                                Regenerate
                            </button>
                        </form>
                    </div>
                @elseif ($run->applied_at)
                    <div class="mt-4 rounded-full bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700 inline-flex">
                        Applied to draft
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-2xl bg-slate-50 px-4 py-5 text-sm text-textSecondary">
                No generated improvements yet.
            </div>
        @endforelse
    </div>
</div>
