@extends('layouts.app', ['title' => $automation->name])

@section('content')
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">{{ $automation->name }}</h1>
                <span class="rounded border border-border px-2 py-0.5 text-xs text-textSecondary">{{ ucfirst($automation->lifecycleStatus()) }}</span>
                <span class="rounded border border-border px-2 py-0.5 text-xs text-textSecondary">{{ $automation->publication_mode?->label() ?? $automation->publication_mode }}</span>
            </div>
            <p class="mt-1 text-sm text-textSecondary">{{ $automation->topic_scope }}</p>
            @if ($automation->latestRun)
                <p class="mt-2 text-xs text-textSecondary">Last run: <span class="font-mono">{{ $automation->latestRun->id }}</span> · {{ $automation->latestRun->status?->label() ?? $automation->latestRun->status }}</p>
            @endif
            @if ($automation->last_failure_message)
                <p class="mt-2 text-sm text-rose-800">Last failure: {{ $automation->last_failure_message }}</p>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($automation->isActive())
                <form method="POST" action="{{ route('app.content.automations.run', $automation) }}">
                    @csrf
                    <button class="rounded border border-border px-3 py-2 text-sm">Run now</button>
                </form>
            @endif
            @if ($automation->lifecycleStatus() === 'paused')
                <form method="POST" action="{{ route('app.content.automations.resume', $automation) }}">
                    @csrf
                    <button class="rounded border border-border px-3 py-2 text-sm">Resume</button>
                </form>
            @elseif ($automation->lifecycleStatus() === 'active')
                <form method="POST" action="{{ route('app.content.automations.pause', $automation) }}">
                    @csrf
                    <button class="rounded border border-border px-3 py-2 text-sm">Pause</button>
                </form>
            @endif
            <a href="{{ route('app.content.automations.edit', $automation) }}" class="rounded border border-border px-3 py-2 text-sm">Edit</a>
        </div>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif
    @if ($errors->has('automation'))
        <div class="mb-4 rounded border border-rose-300/60 bg-rose-500/5 px-3 py-2 text-sm text-rose-800">{{ $errors->first('automation') }}</div>
    @endif
    @if (($automationCreditEvaluation['skip_reason'] ?? null) === 'insufficient_credits')
        <x-alert class="mb-4" :variant="($automationCreditEvaluation['workspace_evaluation']['is_blocking'] ?? false) ? 'error' : 'brand'" iconName="coins">
            <x-slot:title>{{ __('app.credits.low_warning.title') }}</x-slot:title>
            {{ $automationCreditEvaluation['message'] }}
        </x-alert>
    @endif

    @if ($latestErrorPresenter?->hasError())
        <div class="mb-4">
            @include('app.content.automations.partials.error-alert', [
                'error' => $latestErrorPresenter,
                'canViewTechnicalDetails' => $canViewTechnicalDetails,
            ])
        </div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-lg border border-border bg-surface p-4 lg:col-span-2">
            <h2 class="text-sm font-semibold text-textPrimary">Configuration</h2>
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Scope</p>
                    <p class="mt-1 text-sm font-medium text-textPrimary">{{ $automation->clientSite?->name ?? $automation->workspace?->name ?? 'Unknown' }}</p>
                </div>
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Cadence</p>
                    <p class="mt-1 text-sm font-medium text-textPrimary">Every {{ $automation->generation_frequency_value }} {{ strtolower($automation->generation_frequency_unit?->label() ?? (string) $automation->generation_frequency_unit) }}</p>
                </div>
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Mode</p>
                    <p class="mt-1 text-sm font-medium text-textPrimary">{{ $automation->mode?->label() ?? $automation->mode }} · {{ $automation->chain_size }} item(s)</p>
                </div>
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Languages</p>
                    <p class="mt-1 text-sm font-medium text-textPrimary">Source: {{ strtoupper($automation->sourceLocale()) }} · Targets: {{ implode(', ', array_map('strtoupper', $automation->targetLocales())) ?: 'None' }}</p>
                    <p class="mt-1 text-xs text-textSecondary">Auto-translate: {{ $automation->autoTranslateGeneratedContent() ? 'On' : 'Off' }} · Auto-publish translations: {{ $automation->autoPublishTranslationsWithSource() ? 'On' : 'Off' }} · Family mode: {{ ucfirst($automation->familyPublishMode()) }}</p>
                </div>
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Brand voice</p>
                    <p class="mt-1 text-sm font-medium text-textPrimary">{{ $automation->brandVoice?->name ?? 'Automatic' }}</p>
                </div>
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Team persona</p>
                    <p class="mt-1 text-sm font-medium text-textPrimary">{{ $automation->teamPersona?->name ?? 'None' }}</p>
                </div>
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Buyer persona</p>
                    <p class="mt-1 text-sm font-medium text-textPrimary">{{ $automation->buyerPersona?->name ?? 'None' }}</p>
                </div>
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">Next run</p>
                    <p class="mt-1 text-sm font-medium text-textPrimary">{{ optional($automation->next_run_at)->toDayDateTimeString() ?? 'Not scheduled' }}</p>
                </div>
                    <div class="rounded border border-border bg-background p-3">
                        <p class="text-xs text-textSecondary">Run count</p>
                        <p class="mt-1 text-sm font-medium text-textPrimary">{{ (int) $automation->run_count }} / {{ $automation->max_runs ?? 'unlimited' }}</p>
                    </div>
                    <div class="rounded border border-border bg-background p-3">
                        <p class="text-xs text-textSecondary">End date</p>
                        <p class="mt-1 text-sm font-medium text-textPrimary">{{ optional($automation->end_at)->toDayDateTimeString() ?? 'None' }}</p>
                    </div>
                    <div class="rounded border border-border bg-background p-3 md:col-span-2">
                        <p class="text-xs text-textSecondary">Failure tracking</p>
                        <p class="mt-1 text-sm font-medium text-textPrimary">{{ $automation->last_failure_code ?: 'None' }}</p>
                        <p class="mt-1 text-xs text-textSecondary">{{ optional($automation->last_failure_at)->toDayDateTimeString() ?? 'No recent failure recorded' }}</p>
                    </div>
                </div>

            @if ($automation->content_goal || $automation->company_context_override || data_get($automation->settings, 'content_pillars'))
                <div class="mt-4 space-y-3">
                    @if ($automation->content_goal)
                        <div class="rounded border border-border bg-background p-3">
                            <p class="text-xs text-textSecondary">Content goal</p>
                            <p class="mt-1 text-sm text-textPrimary">{{ $automation->content_goal }}</p>
                        </div>
                    @endif
                    @if ($automation->company_context_override)
                        <div class="rounded border border-border bg-background p-3">
                            <p class="text-xs text-textSecondary">Company context override</p>
                            <p class="mt-1 text-sm text-textPrimary">{{ $automation->company_context_override }}</p>
                        </div>
                    @endif
                    @if (data_get($automation->settings, 'content_pillars'))
                        <div class="rounded border border-border bg-background p-3">
                            <p class="text-xs text-textSecondary">Content pillars</p>
                            <p class="mt-1 text-sm text-textPrimary whitespace-pre-line">{{ data_get($automation->settings, 'content_pillars') }}</p>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        <div class="rounded-lg border border-border bg-surface p-4">
            <h2 class="text-sm font-semibold text-textPrimary">Actions</h2>
            <div class="mt-4 space-y-2">
                <form method="POST" action="{{ route('app.content.automations.duplicate', $automation) }}">
                    @csrf
                    <button class="w-full rounded border border-border px-3 py-2 text-sm">Duplicate as paused copy</button>
                </form>
                <form method="POST" action="{{ route('app.content.automations.destroy', $automation) }}" onsubmit="return confirm('Delete this automation and all run history?');">
                    @csrf
                    @method('DELETE')
                    <button class="w-full rounded border border-rose-300/60 px-3 py-2 text-sm text-rose-800">Delete automation</button>
                </form>
            </div>
        </div>
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-3">
        <div class="rounded-lg border border-border bg-surface p-4 lg:col-span-2">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-textPrimary">Run history</h2>
                    <p class="mt-1 text-xs text-textSecondary">Each run keeps its own status, summary, generated ids, and any error message for auditability.</p>
                </div>
            </div>

            <div class="mt-4 space-y-3">
                @forelse ($runs as $run)
                    @php
                        $runItems = collect($run->relationLoaded('items') ? $run->items : []);
                        $metadataItems = collect((array) data_get($run->metadata, 'items', []))
                            ->filter(fn ($metadataItem) => is_array($metadataItem) || is_object($metadataItem));
                        $fallbackItems = $runItems->isNotEmpty() ? $runItems : $metadataItems;
                    @endphp
                    <div id="run-{{ $run->id }}" class="rounded border border-border bg-background p-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium text-textPrimary">{{ $run->status?->label() ?? $run->status }}</span>
                                    <span class="rounded border border-border px-2 py-0.5 text-xs text-textSecondary">{{ ucfirst($run->triggered_by?->value ?? (string) $run->triggered_by) }}</span>
                                </div>
                                <p class="mt-1 text-xs text-textSecondary">
                                    Started {{ optional($run->started_at)->toDayDateTimeString() ?? 'n/a' }}
                                    @if ($run->finished_at)
                                        · Finished {{ $run->finished_at->toDayDateTimeString() }}
                                    @endif
                                </p>
                            </div>
                            <div class="text-right text-xs text-textSecondary">
                                <div>{{ count($run->generated_content_ids ?? []) }} generated</div>
                                <div>{{ count($run->published_content_ids ?? []) }} published</div>
                                <div>{{ (int) ($run->attempt_count ?? 0) }} attempt(s)</div>
                            </div>
                        </div>
                        @if ($run->result_summary)
                            <p class="mt-2 text-sm text-textPrimary">{{ $run->result_summary }}</p>
                        @endif
                        @if ($run->error_message)
                            @php
                                $runErrorPresenter = \App\Support\Errors\AutomationErrorPresenter::fromRun($run);
                            @endphp
                            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                                <span class="inline-flex items-center rounded border border-rose-200 bg-rose-50 px-1.5 py-0.5 font-mono text-xs text-rose-700">
                                    {{ $runErrorPresenter->publicErrorCode() }}
                                </span>
                                <span class="text-rose-700">{{ $runErrorPresenter->publicErrorTitle() }}</span>
                            </div>
                            <p class="mt-2 text-sm text-rose-800">{{ $run->error_message }}</p>
                            @if ($canViewTechnicalDetails && data_get($run->metadata, 'real_error'))
                                <details class="mt-2 rounded border border-border bg-surface p-2 text-xs text-textSecondary">
                                    <summary class="cursor-pointer font-medium text-textPrimary">View run details</summary>
                                    <pre class="mt-2 overflow-auto text-[11px] text-textPrimary">{{ json_encode(data_get($run->metadata, 'real_error'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                            @endif
                        @endif
                        @if ($fallbackItems->isNotEmpty())
                            <div class="mt-3 overflow-x-auto">
                                <table class="min-w-full text-left text-xs">
                                    <thead class="text-textSecondary">
                                        <tr>
                                            <th class="py-1 pr-3">Item</th>
                                            <th class="py-1 pr-3">Status</th>
                                            <th class="py-1 pr-3">Stage</th>
                                            <th class="py-1 pr-3">Locale</th>
                                            <th class="py-1 pr-3">Type</th>
                                            <th class="py-1 pr-3">Generation</th>
                                            <th class="py-1 pr-3">Translation</th>
                                            <th class="py-1 pr-3">Delivery</th>
                                            <th class="py-1 pr-3">Publication</th>
                                            <th class="py-1 pr-3">Timeline</th>
                                            <th class="py-1 pr-3">Error</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($fallbackItems as $runItem)
                                            @php
                                                $itemContent = $runItem instanceof \App\Models\ContentAutomationRunItem ? $runItem->content : null;
                                                $itemTitle = data_get($runItem, 'chain_index', data_get($runItem, 'sequence', data_get($runItem, 'title', 'n/a')));
                                                $itemStatus = (string) data_get($runItem, 'status', 'unknown');
                                                $itemStage = (string) data_get($runItem, 'failure_stage', data_get($runItem, 'stage', 'n/a'));
                                                $itemLocale = $itemContent?->localeCode() ?: (string) data_get($runItem, 'locale', data_get($runItem, 'target_locale', ''));
                                                $itemType = (string) data_get($runItem, 'item_type', data_get($runItem, 'is_source_locale', false) ? 'source' : 'translation');
                                                $generationStatus = (string) data_get($runItem, 'generation_status', data_get($runItem, 'metadata.result.status', 'n/a'));
                                                $translationStatus = (string) data_get($runItem, 'translation_status', $itemType === 'translation' ? 'pending' : 'not_required');
                                                $deliveryStatus = (string) data_get($runItem, 'delivery_status', $itemContent?->delivery_status ?? 'n/a');
                                                $publicationStatus = (string) data_get($runItem, 'publication_status', $itemContent?->publish_status ?? 'n/a');
                                                $timeline = collect((array) data_get($runItem, 'metadata.history', []))
                                                    ->map(fn ($entry) => is_array($entry) ? (string) ($entry['message'] ?? '') : '')
                                                    ->filter()
                                                    ->values();
                                                $duplicatePrevented = (bool) data_get($runItem, 'metadata.duplicate_prevented', false);
                                                $itemErrorCode = (string) data_get($runItem, 'last_error_code', data_get($runItem, 'error_code', ''));
                                                $itemErrorMessage = (string) data_get($runItem, 'last_error_message', data_get($runItem, 'error', ''));

                                                // Map to user-friendly error presentation
                                                $itemErrorPresenter = null;
                                                if ($itemErrorMessage !== '') {
                                                    $itemErrorPresenter = $runItem instanceof \App\Models\ContentAutomationRunItem
                                                        ? \App\Support\Errors\AutomationErrorPresenter::fromRunItem($runItem)
                                                        : \App\Support\Errors\AutomationErrorPresenter::fromArray(
                                                            is_array($runItem) ? $runItem : (array) $runItem,
                                                            (string) $run->id,
                                                        );
                                                }
                                            @endphp
                                            <tr class="border-t border-border">
                                                <td class="py-1 pr-3">{{ $itemTitle }}</td>
                                                <td class="py-1 pr-3">{{ $itemStatus }}</td>
                                                <td class="py-1 pr-3">{{ $itemStage !== '' ? $itemStage : 'n/a' }}</td>
                                                <td class="py-1 pr-3">{{ strtoupper($itemLocale) }}</td>
                                                <td class="py-1 pr-3">{{ ucfirst($itemType) }}</td>
                                                <td class="py-1 pr-3">
                                                    <span>{{ $generationStatus !== '' ? $generationStatus : 'n/a' }}</span>
                                                    @if ($duplicatePrevented)
                                                        <span class="ml-1 inline-flex items-center rounded border border-amber-200 bg-amber-50 px-1 py-0.5 text-[10px] font-medium text-amber-700">
                                                            Existing content reused
                                                        </span>
                                                    @endif
                                                </td>
                                                <td class="py-1 pr-3">{{ $translationStatus !== '' ? $translationStatus : 'n/a' }}</td>
                                                <td class="py-1 pr-3">{{ $deliveryStatus !== '' ? $deliveryStatus : 'n/a' }}</td>
                                                <td class="py-1 pr-3">{{ $publicationStatus !== '' ? $publicationStatus : 'n/a' }}</td>
                                                <td class="py-1 pr-3">
                                                    @if ($timeline->isNotEmpty())
                                                        <div class="space-y-1 text-[11px] text-textSecondary">
                                                            @foreach ($timeline as $entry)
                                                                <div>{{ $entry }}</div>
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        <span class="text-textSecondary">-</span>
                                                    @endif
                                                </td>
                                                <td class="py-1 pr-3">
                                                    @if ($itemErrorPresenter)
                                                        <span class="inline-flex items-center gap-1.5">
                                                            <span class="rounded border border-rose-200 bg-rose-50 px-1 py-0.5 font-mono text-rose-700">
                                                                {{ $itemErrorPresenter->publicErrorCode() }}
                                                            </span>
                                                            <span class="text-rose-700" title="{{ $itemErrorPresenter->publicErrorMessage() }}">
                                                                {{ \Illuminate\Support\Str::limit($itemErrorPresenter->publicErrorTitle(), 40) }}
                                                            </span>
                                                        </span>
                                                        @if ($canViewTechnicalDetails)
                                                            <button
                                                                type="button"
                                                                class="ml-1 text-rose-500 hover:text-rose-700"
                                                                title="{{ $itemErrorPresenter->technicalDetails() }}"
                                                            >
                                                                <i data-lucide="info" class="inline h-3 w-3" aria-hidden="true"></i>
                                                            </button>
                                                        @endif
                                                    @else
                                                        <span class="text-textSecondary">-</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if (in_array((string) ($run->status?->value ?? $run->status), ['failed', 'partial'], true) && $automation->isActive())
                                <p class="mt-2 text-xs text-textSecondary">Retry failed items by running this automation again. Existing generated content is not duplicated by repair commands.</p>
                            @endif
                        @endif
                    </div>
                @empty
                    <div class="rounded border border-dashed border-border bg-background px-4 py-6 text-sm text-textSecondary">
                        No runs yet.
                    </div>
                @endforelse
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-4">
            <h2 class="text-sm font-semibold text-textPrimary">Recent content</h2>
            <div class="mt-4 space-y-3">
                @forelse ($recentContents as $content)
                    <a href="{{ route('app.content.show', $content) }}" class="block rounded border border-border bg-background px-3 py-3 hover:bg-surfaceSubtle">
                        <p class="text-sm font-medium text-textPrimary">{{ $content->title }}</p>
                        <p class="mt-1 text-xs text-textSecondary">
                            {{ strtoupper($content->localeCode()) }}
                            · {{ ucfirst((string) ($content->publish_status ?? $content->status)) }}
                            @if ($content->is_source_locale)
                                · Source
                            @else
                                · Translation
                            @endif
                        </p>
                    </a>
                @empty
                    <div class="rounded border border-dashed border-border bg-background px-4 py-6 text-sm text-textSecondary">
                        Generated content will appear here after the first run.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
@endsection
