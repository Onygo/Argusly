@extends('layouts.app', ['title' => 'Content detail'])

@section('content')
    @php
        $destinationType = \App\Enums\ContentDestinationType::normalize($destination?->type?->value ?? $destination?->type);
        $destinationLabel = \App\Enums\ContentDestinationType::label($destinationType);
        $statusPresenter = \App\View\Presenters\ContentStatusPresenter::for($content);
        $isWordPressDestination = $destinationType === \App\Enums\ContentDestinationType::WORDPRESS->value;
        $isLaravelDestination = $destinationType === \App\Enums\ContentDestinationType::LARAVEL->value;
        $hasLaravelConnectorDestination = isset($laravelDestination) && $laravelDestination;
        $supportsImmediatePublish = $isWordPressDestination || $isLaravelDestination;
        $contentLocaleLabel = strtoupper($content->localeCode());
        $contentSourceLocaleLabel = \App\Enums\SupportedLanguage::tryFromString((string) ($content->translation_source_locale ?? ''))?->value;
        $contentSourceLocaleLabel = $contentSourceLocaleLabel && $contentSourceLocaleLabel !== $content->localeCode()
            ? strtoupper($contentSourceLocaleLabel)
            : null;
        $detailLastPublishError = $statusPresenter->lastErrorMessage();
        $shouldShowDetailPublishError = filled($detailLastPublishError) && $statusPresenter->deliveryStatus()->needsAttention();
        $livePublicationLabel = $statusPresenter->deliveryLabel();
        $liveRemoteLabel = $statusPresenter->remotePublishLabel();
        $aeoBreakdown = is_array(data_get($content->aeo_breakdown, 'breakdown'))
            ? data_get($content->aeo_breakdown, 'breakdown')
            : [];
        $aeoImprovements = collect(data_get($content->aeo_breakdown, 'improvements', []))
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->values();
        $isSyncedTranslation = $content->isTranslationVariant()
            && (bool) ($content->sync_with_source ?? true)
            && (bool) ($localizedContentSource?->auto_publish ?? true);
        $linkedSourceSchedule = $localizedContentSource?->scheduled_publish_at;
        $latestDraft = $generationDraft ?? $legacyDraft;
        $latestDraftTimestamp = $latestDraft?->updated_at;
        $currentVersionTimestamp = $content->currentVersion?->updated_at ?? $content->currentVersion?->created_at;
        $latestDraftHash = $latestDraft
            ? hash('sha256', json_encode([
                'content_html' => trim((string) ($latestDraft->content_html ?? '')),
                'title' => trim((string) ($latestDraft->title ?? '')),
                'seo_title' => trim((string) ($latestDraft->seo_title ?? '')),
                'seo_meta_description' => trim((string) ($latestDraft->seo_meta_description ?? '')),
                'seo_h1' => trim((string) ($latestDraft->seo_h1 ?? '')),
                'seo_canonical' => trim((string) ($latestDraft->seo_canonical ?? '')),
                'language' => (string) ($latestDraft->language->value ?? $latestDraft->language ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
            : null;
        $currentVersionDraftId = trim((string) data_get($content->currentVersion?->meta, 'draft_id', ''));
        $currentVersionDraftHash = trim((string) data_get($content->currentVersion?->meta, 'draft_hash', ''));
        $currentRevisionDraftId = trim((string) ($content->currentRevision?->draft_id ?? ''));
        $hasUnsavedChanges = $latestDraft
            ? (
                ($currentVersionDraftId !== (string) $latestDraft->id)
                && ($currentRevisionDraftId !== (string) $latestDraft->id)
                && ($latestDraftHash === null || $currentVersionDraftHash === '' || ! hash_equals($currentVersionDraftHash, $latestDraftHash))
            )
            : false;
        $contentHealthTone = match (true) {
            $contentHealthScore >= 80 => 'text-emerald-700 bg-emerald-50 border-emerald-200',
            $contentHealthScore >= 60 => 'text-amber-800 bg-amber-50 border-amber-200',
            default => 'text-rose-700 bg-rose-50 border-rose-200',
        };
        $answerBlockGenerationStatus = (string) ($content->answer_block_generation_status ?? '');
        $answerBlockGenerationActive = in_array($answerBlockGenerationStatus, [
            \App\Models\Content::ANSWER_BLOCK_STATUS_QUEUED,
            \App\Models\Content::ANSWER_BLOCK_STATUS_RUNNING,
        ], true);
        $lifecycleBadgeClasses = match ($statusPresenter->lifecycleColor()) {
            'green', 'emerald', 'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
            'blue', 'sky', 'primary' => 'border-sky-200 bg-sky-50 text-sky-800',
            'amber', 'yellow', 'orange' => 'border-amber-200 bg-amber-50 text-amber-900',
            'red', 'rose', 'danger' => 'border-rose-200 bg-rose-50 text-rose-800',
            default => 'border-slate-200 bg-slate-100 text-slate-700',
        };
        $deliveryBadgeClasses = match ($statusPresenter->deliveryColor()) {
            'green', 'emerald', 'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
            'blue', 'sky', 'primary' => 'border-sky-200 bg-sky-50 text-sky-800',
            'amber', 'yellow', 'orange' => 'border-amber-200 bg-amber-50 text-amber-900',
            'red', 'rose', 'danger' => 'border-rose-200 bg-rose-50 text-rose-800',
            default => 'border-slate-200 bg-slate-100 text-slate-700',
        };
        $seoMetadataWarnings = app(\App\Services\Seo\SeoMetadataService::class)->warningsForContent($content);
        $workflowTimeline = collect([
            [
                'label' => 'Created',
                'value' => $content->created_at?->format('Y-m-d H:i'),
                'hint' => 'Content record created',
                'tone' => 'bg-slate-200',
            ],
            $legacyBrief
                ? [
                    'label' => 'Brief ready',
                    'value' => $legacyBrief->created_at?->format('Y-m-d H:i'),
                    'hint' => 'Editorial brief captured',
                    'tone' => 'bg-sky-400',
                ]
                : null,
            $latestDraftTimestamp
                ? [
                    'label' => 'Draft updated',
                    'value' => $latestDraftTimestamp->format('Y-m-d H:i'),
                    'hint' => $hasUnsavedChanges ? 'Draft changes are ahead of the current version' : 'Draft is aligned with the current version',
                    'tone' => 'bg-indigo-400',
                ]
                : null,
            collect($translationDebugger['events'] ?? [])->last()
                ? [
                    'label' => 'Localization activity',
                    'value' => collect($translationDebugger['events'] ?? [])->last()?->created_at?->format('Y-m-d H:i'),
                    'hint' => collect($translationDebugger['events'] ?? [])->last()?->message,
                    'tone' => 'bg-amber-400',
                ]
                : null,
            $content->scheduled_publish_at
                ? [
                    'label' => 'Scheduled',
                    'value' => $content->scheduled_publish_at->format('Y-m-d H:i'),
                    'hint' => 'Queued for publication',
                    'tone' => 'bg-sky-500',
                ]
                : null,
            ($content->published_at ?? null)
                ? [
                    'label' => 'Published',
                    'value' => $content->published_at?->format('Y-m-d H:i'),
                    'hint' => 'Live publication recorded',
                    'tone' => 'bg-emerald-500',
                ]
                : null,
            $content->published_url
                ? [
                    'label' => 'Live route',
                    'value' => $content->published_url,
                    'hint' => 'Remote URL available',
                    'tone' => 'bg-emerald-500',
                ]
                : null,
        ])->filter()->values();
    @endphp
    <section class="mb-6 overflow-hidden rounded-2xl border border-border bg-gradient-to-br from-white via-surface to-surfaceSubtle">
        <div class="border-b border-border/70 px-4 py-4 sm:px-6">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2 text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">
                        <span>Editorial Header</span>
                        <span class="h-1 w-1 rounded-full bg-border"></span>
                        <span>{{ $content->clientSite?->name ?? 'No site' }}</span>
                    </div>
                    <h1 class="mt-3 break-words text-2xl font-semibold tracking-tight text-textPrimary sm:text-3xl">{{ $content->title }}</h1>
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                        <span class="pl-badge {{ $lifecycleBadgeClasses }}"><span class="pl-badge__label">{{ $statusPresenter->lifecycleLabel() }}</span></span>
                        <span class="pl-badge {{ $deliveryBadgeClasses }}"><span class="pl-badge__label">{{ $livePublicationLabel }}</span></span>
                        <span class="pl-badge border-slate-200 bg-slate-100 text-slate-700"><span class="pl-badge__label">{{ $contentLocaleLabel }}@if($contentSourceLocaleLabel) · SRC {{ $contentSourceLocaleLabel }}@endif</span></span>
                        @if ($hasUnsavedChanges)
                            <span class="pl-badge border-amber-200 bg-amber-50 text-amber-900"><span class="pl-badge__label">Unsaved draft changes</span></span>
                        @else
                            <span class="pl-badge border-emerald-200 bg-emerald-50 text-emerald-800"><span class="pl-badge__label">All changes published</span></span>
                        @endif
                    </div>
                    <div class="mt-4 grid gap-3 text-sm text-textSecondary sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-textFaint">Publish State</div>
                            <div class="mt-1 font-medium text-textPrimary">{{ $livePublicationLabel }} / {{ $liveRemoteLabel }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-textFaint">Updated</div>
                            <div class="mt-1 font-medium text-textPrimary">{{ $content->updated_at?->diffForHumans() ?? 'n/a' }}</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-textFaint">Language</div>
                            <div class="mt-1 font-medium text-textPrimary">Language: {{ $contentLocaleLabel }}@if($contentSourceLocaleLabel) (Source: {{ $contentSourceLocaleLabel }})@endif</div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-wide text-textFaint">Destination</div>
                            <div class="mt-1 font-medium text-textPrimary">{{ $destinationLabel }} · {{ $content->status }}</div>
                        </div>
                    </div>
                    @if (!empty($seoMetadataWarnings))
                        <div class="mt-4 rounded border border-amber-200 bg-amber-50 px-4 py-3">
                            <p class="text-sm font-medium text-amber-900">SEO metadata warnings</p>
                            <ul class="mt-2 space-y-1 text-xs text-amber-900">
                                @foreach ($seoMetadataWarnings as $warning)
                                    <li>{{ $warning['message'] }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                <div class="w-full xl:max-w-sm">
                    <div class="rounded-2xl border border-border bg-white/90 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="text-xs uppercase tracking-[0.18em] text-textSecondary">Content Health Trend</div>
                                <div class="mt-2 text-3xl font-semibold text-textPrimary">{{ $contentHealthScore }}</div>
                            </div>
                            <span class="inline-flex rounded-full border px-3 py-1 text-xs font-medium {{ $contentHealthTone }}">
                                @if ($contentHealthScore >= 80)
                                    Strong
                                @elseif ($contentHealthScore >= 60)
                                    Improving
                                @else
                                    Needs attention
                                @endif
                            </span>
                        </div>
                        <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full {{ $contentHealthScore >= 80 ? 'bg-emerald-500' : ($contentHealthScore >= 60 ? 'bg-amber-500' : 'bg-rose-500') }}" style="width: {{ max(0, min(100, (int) $contentHealthScore)) }}%;"></div>
                        </div>
                        <p class="mt-3 text-xs text-textSecondary">
                            {{ $hasAnyInsightResults ? 'AI recommendations are available below to improve this content.' : 'Run AI checks to build a health baseline for this content item.' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="sticky top-0 z-20 border-b border-border/70 bg-white/90 px-4 py-3 backdrop-blur sm:px-6">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="pl-tab-scroll">
                    <div class="pl-tab-scroll__inner">
                    <a href="{{ route('app.content.show', ['content' => $content, 'tab' => 'draft']) }}" class="shrink-0 rounded-full border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">Open Draft</a>
                    <a href="{{ route('app.content.markdown-preview', $content) }}" class="shrink-0 rounded-full border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">Preview</a>
                    <a href="#translation-operations" class="shrink-0 rounded-full border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">Translate</a>
                    @if ($content->published_url)
                        <a href="{{ $content->published_url }}" target="_blank" rel="noopener" class="shrink-0 rounded-full border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">Open Live</a>
                    @endif
                    <a href="{{ route('app.content.markdown', $content) }}" class="shrink-0 rounded-full border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">Open `.md`</a>
                    </div>
                </div>

                @can('update', $content)
                    <div class="flex flex-wrap gap-2">
                        @if ($supportsImmediatePublish)
                            <form method="POST" action="{{ route('app.content.publish-now', $content) }}">
                                @csrf
                                <button class="rounded-full bg-textPrimary px-4 py-2 text-sm font-medium text-white hover:opacity-90">Publish</button>
                            </form>
                        @endif
                        @if ($isWordPressDestination || $isLaravelDestination)
                            <form method="POST" action="{{ route('app.content.republish', $content) }}">
                                @csrf
                                <button class="rounded-full border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                    Republish
                                </button>
                            </form>
                        @endif
                    </div>
                @endcan
            </div>
        </div>
    </section>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif
    @if ($errors->has('regenerate'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('regenerate') }}</div>
    @endif
    @if ($errors->has('brief'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('brief') }}</div>
    @endif
    @if ($errors->has('image_generate'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('image_generate') }}</div>
    @endif
    @if ($errors->has('image_push'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('image_push') }}</div>
    @endif
    @if ($errors->has('image_restore'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('image_restore') }}</div>
    @endif
    @if ($errors->has('image_delete'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('image_delete') }}</div>
    @endif
    @if ($errors->has('publish'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('publish') }}</div>
    @endif
    @if ($errors->has('translation'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('translation') }}</div>
    @endif
    @if ($errors->has('refresh_recommendations'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('refresh_recommendations') }}</div>
    @endif
    @if ($errors->has('internal_linking'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('internal_linking') }}</div>
    @endif
    @if ($errors->has('localization'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('localization') }}</div>
    @endif
    @if ($errors->has('question') || $errors->has('answer'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">
            {{ $errors->first('question') ?: $errors->first('answer') }}
        </div>
    @endif

    <div class="pb-32 lg:grid lg:grid-cols-12 lg:gap-6">
        <div class="space-y-5 lg:col-span-8 xl:col-span-9">
            <div class="rounded-2xl bg-surface/70 p-4">
        @php
            $tabLinks = [
                'overview' => route('app.content.show', ['content' => $content, 'tab' => 'overview']),
                'brief' => $legacyBrief
                    ? route('app.content.workspace.brief', $legacyBrief)
                    : route('app.content.show', ['content' => $content, 'tab' => 'brief']),
                'answers' => route('app.content.show', ['content' => $content, 'tab' => 'answers']),
                'draft' => route('app.content.show', ['content' => $content, 'tab' => 'draft']),
                'images' => route('app.content.show', ['content' => $content, 'tab' => 'images']),
                'revisions' => route('app.content.show', ['content' => $content, 'tab' => 'revisions']),
                'activity' => route('app.content.show', ['content' => $content, 'tab' => 'activity']),
            ];
            $tabMeta = [
                'overview' => ['label' => 'Overview', 'icon' => 'O'],
                'brief' => ['label' => 'Brief', 'icon' => 'B'],
                'answers' => ['label' => 'Answer Blocks', 'icon' => 'Q'],
                'draft' => ['label' => 'Draft', 'icon' => 'D'],
                'images' => ['label' => 'Images', 'icon' => 'I'],
                'revisions' => ['label' => 'Revisions', 'icon' => 'R'],
                'activity' => ['label' => 'Activity', 'icon' => 'A'],
            ];
        @endphp
        <div class="sticky top-[88px] z-10 mb-5 rounded-2xl border border-border bg-white/90 p-2 backdrop-blur">
            <div class="pl-tab-scroll">
            <div class="pl-tab-scroll__inner text-sm">
            @foreach ($tabMeta as $key => $meta)
                <a href="{{ $tabLinks[$key] }}" class="inline-flex items-center gap-2 rounded-xl px-3 py-2 font-medium transition {{ $activeTab === $key ? 'bg-textPrimary text-white' : 'text-textSecondary hover:bg-surfaceSubtle hover:text-textPrimary' }}">
                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full {{ $activeTab === $key ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-600' }}">{{ $meta['icon'] }}</span>
                    <span>{{ $meta['label'] }}</span>
                </a>
            @endforeach
            </div>
            </div>
        </div>

        @if ($activeTab === 'overview')
            @php
                $insight = $contentInsight ?? [];
                $statusCode = (string) data_get($insight, 'status_code', 'waiting_for_data');
                $statusMessage = (string) data_get($insight, 'status_message', 'Waiting for tracking data from this page.');
                $roiScore = data_get($insight, 'roi_score');
                $aiVisibilityScore = data_get($insight, 'ai_visibility_score');
                $aiSeoScore = data_get($insight, 'ai_seo_score');
                $roiUpdated = data_get($insight, 'roi_updated_at');
                $aiVisibilityUpdated = data_get($insight, 'ai_visibility_updated_at');
                $aiSeoUpdated = data_get($insight, 'ai_seo_updated_at');
            @endphp
            <section class="mb-5 rounded-2xl border border-border/80 bg-white p-5">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">Overview</div>
                        <h3 class="mt-1 text-lg font-semibold text-textPrimary">Performance Snapshot</h3>
                        <p class="mt-1 text-sm text-textSecondary">The three signals that best explain whether this content is healthy, findable, and ready to improve.</p>
                    </div>
                    <div class="rounded-full border border-border bg-surfaceSubtle px-3 py-1 text-xs text-textSecondary">
                        {{ $statusCode === 'ready' ? 'Tracking active' : 'Waiting for signal data' }}
                    </div>
                </div>
                <div class="grid gap-4 md:grid-cols-3 text-sm">
                    <div class="rounded-2xl bg-slate-50 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-textSecondary">Content ROI</p>
                                <p class="mt-1 text-xs text-textSecondary">Business return from this content asset.</p>
                            </div>
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-border/70 bg-white text-base">R</span>
                        </div>
                        @if (is_numeric($roiScore))
                            @php
                                $roiValue = (float) $roiScore;
                            @endphp
                            <p class="mt-4 text-3xl font-semibold @if($roiValue < 40) text-rose-600 @elseif($roiValue < 70) text-amber-600 @else text-success @endif">{{ number_format($roiValue, 1) }}</p>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-white">
                                <div class="h-full rounded-full @if($roiValue < 40) bg-rose-500 @elseif($roiValue < 70) bg-amber-500 @else bg-emerald-500 @endif" style="width: {{ max(0, min(100, $roiValue)) }}%;"></div>
                            </div>
                        @else
                            <p class="mt-4 text-base font-medium text-textPrimary">Waiting for first attribution cycle</p>
                            <p class="mt-2 text-xs text-textSecondary">Publish and distribute this content to start measuring ROI impact.</p>
                        @endif
                        <p class="mt-3 text-xs text-textSecondary">{{ $roiUpdated ? 'Updated '.$roiUpdated->diffForHumans() : 'No score refresh yet' }}</p>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-textSecondary">AI Visibility</p>
                                <p class="mt-1 text-xs text-textSecondary">How discoverable this page is in AI retrieval flows.</p>
                            </div>
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-border/70 bg-white text-base">A</span>
                        </div>
                        @if (is_numeric($aiVisibilityScore))
                            @php
                                $visibilityValue = (float) $aiVisibilityScore;
                            @endphp
                            <p class="mt-4 text-3xl font-semibold text-textPrimary">{{ number_format($visibilityValue, 1) }}</p>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-white">
                                <div class="h-full rounded-full {{ $visibilityValue >= 70 ? 'bg-emerald-500' : ($visibilityValue >= 40 ? 'bg-amber-500' : 'bg-rose-500') }}" style="width: {{ max(0, min(100, $visibilityValue)) }}%;"></div>
                            </div>
                        @else
                            <p class="mt-4 text-base font-medium text-textPrimary">Waiting for first crawl</p>
                            <p class="mt-2 text-xs text-textSecondary">Publish content to start tracking AI visibility and retrieval coverage.</p>
                        @endif
                        <p class="mt-3 text-xs text-textSecondary">{{ $aiVisibilityUpdated ? 'Updated '.$aiVisibilityUpdated->diffForHumans() : 'No score refresh yet' }}</p>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs uppercase tracking-wide text-textSecondary">AI SEO Score</p>
                                <p class="mt-1 text-xs text-textSecondary">Readiness for answer engines and semantic discovery.</p>
                            </div>
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-full border border-border/70 bg-white text-base">S</span>
                        </div>
                        @if (is_numeric($aiSeoScore))
                            @php
                                $aiSeoValue = (float) $aiSeoScore;
                            @endphp
                            <p class="mt-4 text-3xl font-semibold @if($aiSeoValue < 40) text-rose-600 @elseif($aiSeoValue < 70) text-amber-600 @else text-success @endif">{{ number_format($aiSeoValue, 1) }}</p>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-white">
                                <div class="h-full rounded-full @if($aiSeoValue < 40) bg-rose-500 @elseif($aiSeoValue < 70) bg-amber-500 @else bg-emerald-500 @endif" style="width: {{ max(0, min(100, $aiSeoValue)) }}%;"></div>
                            </div>
                        @elseif (data_get($insight, 'ai_seo_score_stale') === true)
                            <p class="mt-4 text-base font-medium text-amber-700">Score pending recalculation</p>
                            <p class="mt-2 text-xs text-textSecondary">The content changed and the AI SEO evaluation is refreshing.</p>
                        @else
                            <p class="mt-4 text-base font-medium text-textPrimary">Readiness baseline not built yet</p>
                            <p class="mt-2 text-xs text-textSecondary">Run AI scoring to understand how well this page answers and structures knowledge.</p>
                        @endif
                        <p class="mt-3 text-xs text-textSecondary">{{ $aiSeoUpdated ? 'Updated '.$aiSeoUpdated->diffForHumans() : 'No score refresh yet' }}</p>
                    </div>
                </div>
                @if ($statusCode !== 'ready')
                    <p class="mt-4 text-xs text-textSecondary">{{ $statusMessage }}</p>
                @endif
            </section>
            <section class="mb-5 rounded-2xl border border-border/80 bg-white p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">Optimization</div>
                        <h3 class="mt-1 text-lg font-semibold text-textPrimary">AEO Score</h3>
                        <p class="mt-1 text-sm text-textSecondary">Answer Engine Optimization score and the next useful actions.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('app.content.aeo.recalculate', $content) }}">
                            @csrf
                            <button class="rounded-full border border-border px-3 py-2 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">Improve AEO</button>
                        </form>
                        <form method="POST" action="{{ route('app.content.answer-blocks.generate', $content) }}">
                            @csrf
                            <button class="rounded-full border border-border px-3 py-2 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">Generate Answer Blocks</button>
                        </form>
                    </div>
                </div>
                <div class="mt-5 grid gap-5 xl:grid-cols-[240px_minmax(0,1fr)]">
                    <div class="rounded-2xl bg-slate-50 p-5">
                        <div class="mx-auto flex h-36 w-36 items-center justify-center rounded-full border-[10px] {{ ($content->aeo_score ?? 0) >= 70 ? 'border-emerald-400' : (($content->aeo_score ?? 0) >= 40 ? 'border-amber-400' : 'border-rose-400') }} bg-white">
                            <div class="text-center">
                                <p class="text-3xl font-semibold text-textPrimary">{{ $content->aeo_score ?? 0 }}</p>
                                <p class="mt-1 text-sm font-medium {{ ($content->aeo_score ?? 0) >= 70 ? 'text-emerald-700' : (($content->aeo_score ?? 0) >= 40 ? 'text-amber-700' : 'text-rose-700') }}">
                                    @if (($content->aeo_score ?? 0) >= 70)
                                        Good
                                    @elseif (($content->aeo_score ?? 0) >= 40)
                                        Fair
                                    @else
                                        Weak
                                    @endif
                                </p>
                            </div>
                        </div>
                        <p class="mt-4 text-sm text-textSecondary">Answer readiness with answer block coverage and structure quality.</p>
                        <div class="mt-4 rounded-2xl border border-border/70 bg-white px-4 py-3 text-sm">
                            <div class="font-medium text-textPrimary">{{ $content->answerBlocks->count() }} answer block{{ $content->answerBlocks->count() === 1 ? '' : 's' }}</div>
                            <div class="mt-1 text-xs text-textSecondary">Use answer blocks to increase extractable responses for AI systems.</div>
                        </div>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-5">
                        <div class="mb-4 flex items-center justify-between gap-3">
                            <div>
                                <div class="text-sm font-medium text-textPrimary">Score breakdown</div>
                                <div class="mt-1 text-xs text-textSecondary">Scan for the weakest answer-engine factors.</div>
                            </div>
                        </div>
                        <div class="space-y-3">
                        @foreach ([
                            'answer_clarity' => 'Answer clarity',
                            'structure' => 'Structure',
                            'semantic_coverage' => 'Semantic coverage',
                            'entity_usage' => 'Entity usage',
                            'readability' => 'Readability',
                            'llm_formatting' => 'LLM formatting',
                        ] as $key => $label)
                            @php
                                $metricValue = (int) data_get($aeoBreakdown, $key, 0);
                            @endphp
                            <div class="grid gap-2 sm:grid-cols-[150px_minmax(0,1fr)_42px] sm:items-center">
                                <div class="text-sm font-medium text-textPrimary">{{ $label }}</div>
                                <div class="h-2 overflow-hidden rounded-full bg-white">
                                    <div class="h-full rounded-full {{ $metricValue >= 70 ? 'bg-emerald-500' : ($metricValue >= 40 ? 'bg-amber-500' : 'bg-rose-500') }}" style="width: {{ max(0, min(100, $metricValue)) }}%;"></div>
                                </div>
                                <div class="text-right text-sm font-medium {{ $metricValue >= 70 ? 'text-emerald-700' : ($metricValue >= 40 ? 'text-amber-800' : 'text-rose-700') }}">{{ $metricValue }}</div>
                            </div>
                        @endforeach
                        </div>
                    </div>
                </div>
                @if ($aeoImprovements->isNotEmpty())
                    <div class="mt-5">
                        <p class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">Actionable improvements</p>
                        <div
                            class="mt-3"
                            data-content-improvement-root
                            data-status-url="{{ route('app.content.improvements.status', $content) }}"
                            data-latest-event-id="{{ (int) ($contentImprovementDashboard['latest_event_id'] ?? 0) }}"
                        >
                            @include('app.content.partials.content-improvement-actions', [
                                'content' => $content,
                                'contentImprovementOptions' => $contentImprovementOptions,
                                'contentImprovementDashboard' => $contentImprovementDashboard,
                            ])

                            <details class="mt-4 rounded-2xl border border-border/70 bg-slate-50 p-4">
                                <summary class="cursor-pointer list-none text-sm font-medium text-textPrimary">Generated improvement details</summary>
                                <div class="mt-4 space-y-4">
                                    @include('app.content.partials.content-improvement-monitor', [
                                        'content' => $content,
                                        'contentImprovementDashboard' => $contentImprovementDashboard,
                                    ])

                                    @include('app.content.partials.content-improvement-generated', [
                                        'content' => $content,
                                        'contentImprovementDashboard' => $contentImprovementDashboard,
                                    ])
                                </div>
                            </details>
                        </div>
                    </div>
                @endif
            </section>
            <section class="mb-5 rounded-2xl border border-border/80 bg-white p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">Workflow</div>
                        <h3 class="mt-1 text-lg font-semibold text-textPrimary">Content Workflow Timeline</h3>
                        <p class="mt-1 text-sm text-textSecondary">Recent editorial, localization, and publishing milestones.</p>
                    </div>
                </div>
                <div class="mt-5 grid gap-4 xl:grid-cols-2">
                    <div class="space-y-4">
                        @forelse ($workflowTimeline as $timelineItem)
                            <div class="flex gap-4">
                                <div class="flex flex-col items-center">
                                    <span class="h-3 w-3 rounded-full {{ $timelineItem['tone'] }}"></span>
                                    @if (! $loop->last)
                                        <span class="mt-2 h-full w-px bg-border"></span>
                                    @endif
                                </div>
                                <div class="min-w-0 pb-4">
                                    <div class="text-sm font-medium text-textPrimary">{{ $timelineItem['label'] }}</div>
                                    <div class="mt-1 text-sm text-textSecondary break-all">{{ $timelineItem['value'] ?? 'n/a' }}</div>
                                    <div class="mt-1 text-xs text-textSecondary">{{ $timelineItem['hint'] }}</div>
                                </div>
                            </div>
                        @empty
                            <div class="rounded-2xl bg-slate-50 px-4 py-5 text-sm text-textSecondary">No workflow events captured yet.</div>
                        @endforelse
                    </div>
                    <div class="space-y-4">
                        <div class="rounded-2xl bg-slate-50 p-4">
                            <div class="text-sm font-medium text-textPrimary">Latest AI improvements</div>
                            @if ($aeoImprovements->isNotEmpty())
                                <ul class="mt-3 space-y-2 text-sm text-textSecondary">
                                    @foreach ($aeoImprovements->take(4) as $improvement)
                                        <li class="flex gap-2">
                                            <span class="mt-1 h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                            <span>{{ $improvement }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="mt-3 text-sm text-textSecondary">AI improvements will appear here after AEO or insight actions are run.</p>
                            @endif
                        </div>
                        <details class="rounded-2xl bg-slate-50 p-4">
                            <summary class="cursor-pointer list-none text-sm font-medium text-textPrimary">Developer diagnostics</summary>
                            <dl class="mt-4 grid gap-3 text-sm text-textSecondary sm:grid-cols-2">
                                <div>
                                    <dt class="text-[11px] uppercase tracking-wide text-textFaint">Content ID</dt>
                                    <dd class="mt-1 break-all text-textPrimary">{{ $content->id }}</dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] uppercase tracking-wide text-textFaint">External key</dt>
                                    <dd class="mt-1 break-all text-textPrimary">{{ $content->external_key ?? 'None' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] uppercase tracking-wide text-textFaint">Current version</dt>
                                    <dd class="mt-1 text-textPrimary">{{ $content->currentVersion?->type ?? 'None' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] uppercase tracking-wide text-textFaint">Destination</dt>
                                    <dd class="mt-1 text-textPrimary">{{ $destinationLabel }}</dd>
                                </div>
                            </dl>
                        </details>
                    </div>
                </div>
            </section>
            <details class="mb-5 rounded-2xl border border-border/80 bg-white p-5">
                <summary class="cursor-pointer list-none text-sm font-semibold text-textPrimary">Search visibility diagnostics</summary>
                <div class="mt-5">
                    @include('app.content.partials.seo-diagnostics-panel')
                </div>
            </details>
            <details class="mb-5 rounded-2xl border border-border/80 bg-white p-5">
                <summary class="cursor-pointer list-none text-sm font-semibold text-textPrimary">Publishing and localization details</summary>
                <div class="mt-5 grid gap-5 xl:grid-cols-2">
                {{-- Publication Status Panel --}}
                <x-content-status-panel :content="$content" />

                {{-- Content Details --}}
                <div class="rounded-2xl bg-slate-50 p-5">
                    <h3 class="mb-3 text-sm font-medium text-textPrimary">Content Details</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="text-textSecondary">Language</span>
                            <span class="text-textPrimary">
                                {{ $contentLocaleLabel }}
                                @if ($contentSourceLocaleLabel)
                                    <span class="text-textSecondary">(Source: {{ $contentSourceLocaleLabel }})</span>
                                @endif
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-textSecondary">Scheduled publish</span>
                            <span class="text-textPrimary">{{ optional($content->scheduled_publish_at)->format('Y-m-d H:i') ?? 'Not scheduled' }}</span>
                        </div>
                        @if (! $content->isTranslationVariant())
                            <div class="pt-2">
                                <form method="POST" action="{{ route('app.content.publishing-sync.update', $content) }}" class="rounded-2xl border border-border/70 bg-white p-3">
                                    @csrf
                                    <div class="flex items-center justify-between gap-3">
                                        <label for="auto_publish_translations" class="text-sm font-medium text-textPrimary">Auto-publish translations</label>
                                        <input id="auto_publish_translations" type="checkbox" name="auto_publish" value="1" @checked((bool) ($content->auto_publish ?? true)) class="h-4 w-4 rounded border-border">
                                    </div>
                                    <p class="mt-2 text-xs text-textSecondary">When this source locale is scheduled or published, synced translations inherit the same publishing lifecycle.</p>
                                    <button class="mt-3 rounded border border-border px-3 py-2 text-xs text-textPrimary">Save linked locale settings</button>
                                </form>
                            </div>
                        @else
                            <div class="pt-2">
                                <form method="POST" action="{{ route('app.content.publishing-sync.update', $content) }}" class="rounded-2xl border border-border/70 bg-white p-3">
                                    @csrf
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <label for="sync_with_source" class="text-sm font-medium text-textPrimary">Sync publish timing with source</label>
                                        <input id="sync_with_source" type="checkbox" name="sync_with_source" value="1" @checked((bool) ($content->sync_with_source ?? true)) class="h-4 w-4 rounded border-border">
                                    </div>
                                    <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <label for="auto_publish_locale" class="text-sm font-medium text-textPrimary">Auto-publish this locale</label>
                                        <input id="auto_publish_locale" type="checkbox" name="auto_publish" value="1" @checked((bool) ($content->auto_publish ?? true)) class="h-4 w-4 rounded border-border">
                                    </div>
                                    <p class="mt-2 text-xs text-textSecondary">
                                        @if ($linkedSourceSchedule)
                                            Translations will publish at: {{ $linkedSourceSchedule->format('Y-m-d H:i') }}
                                        @elseif (($localizedContentSource?->publish_status ?? '') === 'published' || ($localizedContentSource?->status ?? '') === 'published')
                                            Source is already live. This locale will auto-publish once it has a ready draft.
                                        @else
                                            Source-controlled publish timing is active for this locale.
                                        @endif
                                    </p>
                                    <button class="mt-3 rounded border border-border px-3 py-2 text-xs text-textPrimary">Save locale sync settings</button>
                                </form>
                            </div>
                        @endif
                        <x-metadata-row label="Current version" :value="$content->currentVersion?->type ?? 'None'" stacked />
                        <x-metadata-row label="Primary keyword" :value="$content->primary_keyword ?? 'Not set'" stacked />
                        <x-metadata-row label="External key" stacked>
                            <span class="font-mono text-xs">{{ $content->external_key ?? 'None' }}</span>
                        </x-metadata-row>
                        @if ($content->series)
                            <x-metadata-row label="Chain" stacked>
                                <a href="{{ route('app.content.series.show', $content->series) }}" class="text-link underline">{{ $content->series->name }}</a>
                            </x-metadata-row>
                            <x-metadata-row label="Chain role" stacked>
                                <span class="inline-flex items-center rounded border px-2 py-0.5 text-xs {{ $content->seriesRole() === 'pillar' ? 'border-sky-300 text-sky-700' : 'border-border text-textSecondary' }}">
                                    {{ $content->seriesRole() === 'pillar' ? 'Pillar' : ($content->seriesRole() === 'supporting' ? 'Supporting' : 'Unassigned') }}
                                </span>
                            </x-metadata-row>
                        @endif
                        @if ($isLaravelDestination && $hasLaravelConnectorDestination)
                            @php
                                $laravelDeliveryStatus = \App\Enums\PublicationDeliveryStatus::fromLegacyStatus((string) ($laravelPublication?->delivery_status ?? 'pending'));
                                $laravelDeliveryLabel = $laravelPublication
                                    ? $laravelDeliveryStatus->label()
                                    : (string) data_get($laravelPublishTarget, 'sync_status', 'not_queued');
                                $laravelDeliveryColor = $laravelPublication
                                    ? $laravelDeliveryStatus->color()
                                    : 'slate';
                            @endphp
                            <div class="border-t border-border pt-2">
                                <x-metadata-row label="Laravel destination" :value="$laravelDestination->name" stacked />
                            </div>
                            <x-metadata-row label="Connector publish" stacked>
                                <x-status-badge :label="$laravelDeliveryLabel" :color="$laravelDeliveryColor" />
                            </x-metadata-row>
                        @endif
                    </div>
                </div>

                <div id="translation-operations" class="rounded-2xl bg-slate-50 p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">Localization</div>
                            <h3 class="mt-1 text-lg font-semibold text-textPrimary">Localization Operations</h3>
                            <p class="mt-1 text-xs text-textSecondary">
                                Available: @foreach(($localizedContentStatuses ?? collect()) as $localizedStatus){{ strtoupper((string) $localizedStatus['locale']) }}@if((string) $localizedStatus['content']->id === (string) $content->id) (current)@elseif(($localizedStatus['is_source'] ?? false)) (source)@else (translation)@endif{{ ! $loop->last ? ' · ' : '' }}@endforeach
                            </p>
                        </div>
                        @if ((string) $content->id !== (string) ($localizedContentSource?->id ?? $content->id))
                            <a href="{{ route('app.content.show', $localizedContentSource ?? $content) }}" class="rounded border border-border px-2 py-1 text-xs text-textPrimary">Open source</a>
                        @endif
                    </div>

                    <div class="mt-4 space-y-3">
                        @foreach(($localizedContentStatuses ?? collect()) as $localizedStatus)
                            @php($variantContent = $localizedStatus['content'])
                            <div class="rounded-2xl border border-border/70 bg-white px-4 py-4">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a href="{{ route('app.content.show', $variantContent) }}" class="font-medium text-textPrimary hover:underline">
                                                {{ strtoupper((string) $localizedStatus['locale']) }}
                                            </a>
                                            <span class="rounded border border-border px-1.5 py-0.5 text-[10px] font-normal text-textSecondary">
                                                {{ (string) ($localizedStatus['source_badge_label'] ?? ($localizedStatus['is_source'] ? 'Source' : '')) }}
                                            </span>
                                            @if($localizedStatus['has_duplicate_locale_rows'] ?? false)
                                                <x-status-badge label="Duplicate locale" color="amber" />
                                            @elseif(($localizedStatus['action_state'] ?? '') === 'publishing')
                                                <x-status-badge label="Publishing..." color="sky" />
                                            @elseif(($localizedStatus['action_state'] ?? '') === 'scheduled')
                                                <x-status-badge label="Scheduled" color="sky" />
                                            @elseif($localizedStatus['is_published'])
                                                <x-status-badge label="Published" color="green" />
                                            @else
                                                <x-status-badge label="Draft" color="slate" />
                                            @endif
                                            @if($localizedStatus['is_outdated'])
                                                <x-status-badge label="Outdated" color="amber" />
                                            @endif
                                        </div>
                                        <div class="mt-1 text-xs text-textSecondary">
                                            {{ ucfirst((string) $localizedStatus['status']) }}
                                            @if(($localizedStatus['publish_status'] ?? '') !== '')
                                                · {{ $localizedStatus['publish_status'] }}
                                            @endif
                                            @if(($localizedStatus['delivery_label'] ?? '') !== '')
                                                · {{ $localizedStatus['delivery_label'] }}
                                            @endif
                                            @if((($localizedStatus['has_newer_draft'] ?? false) || ($localizedStatus['is_outdated'] ?? false)) && $localizedStatus['is_published'])
                                                · Draft newer than live version
                                            @endif
                                            @if(! ($localizedStatus['latest_draft_exists'] ?? false))
                                                · no draft
                                            @endif
                                        </div>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <a href="{{ route('app.content.show', $variantContent) }}" class="rounded border border-border px-3 py-2 text-xs text-textPrimary hover:bg-surfaceSubtle">
                                            Open
                                        </a>

                                        @can('update', $variantContent)
                                            @if($localizedStatus['is_laravel_variant'] ?? false)
                                                @if(($localizedStatus['has_duplicate_locale_rows'] ?? false) || ! ($localizedStatus['has_laravel_destination'] ?? false))
                                                    <button type="button" class="rounded border border-border px-3 py-2 text-xs text-textSecondary opacity-60" disabled>
                                                        Publish blocked
                                                    </button>
                                                @elseif(($localizedStatus['action_state'] ?? '') === 'publishing' || ($localizedStatus['action_state'] ?? '') === 'scheduled')
                                                    <button type="button" class="rounded border border-border px-3 py-2 text-xs text-textSecondary opacity-60" disabled>
                                                        {{ ($localizedStatus['action_state'] ?? '') === 'scheduled' ? 'Scheduled' : 'Publishing...' }}
                                                    </button>
                                                @elseif(($localizedStatus['can_publish_now'] ?? false) || ($localizedStatus['can_update_live'] ?? false))
                                                    <form method="POST" action="{{ route('app.content.publish-now', $localizedContentSource ?? $content) }}">
                                                        @csrf
                                                        <input type="hidden" name="locale" value="{{ (string) $localizedStatus['locale'] }}">
                                                        <button class="rounded border border-transparent bg-textPrimary px-3 py-2 text-xs font-medium text-white hover:opacity-90"
                                                            @disabled(! ($localizedStatus['latest_draft_exists'] ?? false))
                                                            onclick="this.disabled=true; this.form.submit();">
                                                            {{ ($localizedStatus['can_update_live'] ?? false) ? 'Update publish' : 'Publish now' }}
                                                        </button>
                                                    </form>
                                                @elseif(! ($localizedStatus['latest_draft_exists'] ?? false) && ! $localizedStatus['is_published'])
                                                    <button type="button" class="rounded border border-border px-3 py-2 text-xs text-textSecondary opacity-60" disabled>
                                                        Publish now
                                                    </button>
                                                @endif

                                                @if(($localizedStatus['sync_with_source_active'] ?? false))
                                                    <button type="button" class="rounded border border-border px-3 py-2 text-xs text-textSecondary opacity-60" disabled>
                                                        Source-controlled schedule
                                                    </button>
                                                @elseif($localizedStatus['can_schedule'] ?? false)
                                                    <details class="group">
                                                        <summary class="cursor-pointer list-none rounded border border-border px-3 py-2 text-xs text-textPrimary hover:bg-surfaceSubtle">
                                                            Schedule
                                                        </summary>
                                                        <form method="POST" action="{{ route('app.content.schedule', $variantContent) }}" class="mt-2 flex flex-wrap items-end gap-2 rounded border border-border bg-surfaceSubtle p-2">
                                                            @csrf
                                                            <div>
                                                                <label class="mb-1 block text-[11px] text-textSecondary">Publish at</label>
                                                                <input
                                                                    type="datetime-local"
                                                                    name="scheduled_publish_at"
                                                                    value="{{ optional($localizedStatus['scheduled_publish_at'] ?? null)->format('Y-m-d\\TH:i') }}"
                                                                    class="rounded border border-border bg-background px-2 py-2 text-xs text-textPrimary"
                                                                >
                                                            </div>
                                                            <button class="rounded border border-border px-3 py-2 text-xs text-textPrimary hover:bg-background">
                                                                Save
                                                            </button>
                                                        </form>
                                                    </details>
                                                @endif
                                            @endif
                                        @endcan
                                    </div>
                                </div>
                            </div>
                        @endforeach

                        @if(($showLegacyLocaleRedirects ?? false) && ($legacyLocaleRedirects ?? collect())->isNotEmpty())
                            <div class="rounded-2xl border border-border/70 bg-white px-4 py-3 text-[11px] text-textSecondary">
                                <div class="font-medium text-textPrimary">Legacy redirect</div>
                                <div class="mt-1">Historical locale route redirects to the canonical {{ strtoupper((string) ($localizedContentSource?->localeCode() ?? $content->localeCode())) }} URL.</div>
                                @foreach(($legacyLocaleRedirects ?? collect()) as $legacyLocaleRedirect)
                                    <div class="mt-1 font-mono text-[11px]">
                                        {{ $legacyLocaleRedirect->source_path }} → {{ $legacyLocaleRedirect->target_path }}
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @can('update', $localizedContentSource ?? $content)
                        @if(($localizedTranslationTargets ?? collect())->isNotEmpty())
                            <div class="mt-5 border-t border-border/70 pt-5">
                                <div class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">Translate or refresh</div>
                                <div class="mt-3 grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                                    @foreach(($localizedTranslationTargets ?? collect()) as $target)
                                        @php($targetLocale = \App\Enums\SupportedLanguage::fromStringOrDefault((string) $target['value'])->value)
                                        <div class="rounded-2xl border border-border/70 bg-white px-4 py-4 text-xs text-textPrimary">
                                            <div class="flex items-center gap-2">
                                                <span>{{ strtoupper($targetLocale) }}</span>
                                                @if (($target['state'] ?? 'none') === 'queued')
                                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800">Queued</span>
                                                @elseif (($target['state'] ?? 'none') === 'processing')
                                                    <span class="rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-medium text-sky-800">Translating</span>
                                                @elseif (($target['state'] ?? 'none') === 'completed')
                                                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-800">Translated</span>
                                                @elseif (($target['state'] ?? 'none') === 'failed')
                                                    <span class="rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-medium text-rose-800">Failed</span>
                                                @elseif (($target['state'] ?? 'none') === 'insufficient_credits')
                                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-900">Not enough credits</span>
                                                @elseif (($target['state'] ?? 'none') === 'stale_recovered')
                                                    <span class="rounded-full bg-orange-100 px-2 py-0.5 text-[11px] font-medium text-orange-800">Stale recovered</span>
                                                @elseif (($target['state'] ?? 'none') === 'ready')
                                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700">Ready</span>
                                                @endif
                                            </div>
                                            @if (($target['state'] ?? 'none') === 'insufficient_credits')
                                                <div class="mt-1 text-[11px] text-amber-800">
                                                    Required: {{ (int) ($target['required_credits'] ?? 0) }} credits
                                                    · Available: {{ (int) ($target['available_credits'] ?? 0) }} credits
                                                </div>
                                            @endif
                                            @if (in_array((string) ($target['state'] ?? 'none'), ['failed', 'stale', 'stale_recovered'], true) && filled($target['error_message'] ?? null))
                                                <div class="mt-1 text-[11px] text-rose-700">{{ $target['error_message'] }}</div>
                                            @elseif (($target['state'] ?? 'none') === 'insufficient_credits' && filled($target['error_message'] ?? null))
                                                <div class="mt-1 text-[11px] text-amber-800">{{ $target['error_message'] }}</div>
                                            @endif
                                            @if (! empty($target['last_failed_at'] ?? null))
                                                <div class="mt-1 text-[11px] text-textSecondary">Last failed at {{ $target['last_failed_at']->format('Y-m-d H:i') }}</div>
                                            @endif
                                            @if (($target['state'] ?? 'none') === 'completed' && ($target['existing_variant'] ?? null))
                                                <div class="mt-2 flex flex-wrap gap-2">
                                                    <a href="{{ route('app.content.show', $target['existing_variant']) }}" class="rounded border border-border px-3 py-2 text-xs text-textPrimary hover:bg-surfaceSubtle">
                                                        Open {{ strtoupper($targetLocale) }}
                                                    </a>
                                                </div>
                                            @endif
                                            @if (($target['state'] ?? 'none') === 'insufficient_credits')
                                                <div class="mt-2 flex flex-wrap gap-2">
                                                    <a href="{{ $target['buy_credits_url'] ?? route('app.billing.index') }}" class="rounded border border-border px-3 py-2 text-xs text-textPrimary hover:bg-surfaceSubtle">
                                                        Buy credits
                                                    </a>
                                                    <a href="{{ $target['upgrade_plan_url'] ?? route('app.billing.index', ['tab' => 'subscriptions']) }}" class="rounded border border-border px-3 py-2 text-xs text-textPrimary hover:bg-surfaceSubtle">
                                                        Upgrade plan
                                                    </a>
                                                </div>
                                            @endif
                                            @if (! in_array((string) ($target['state'] ?? 'none'), ['queued', 'processing'], true))
                                                <form method="POST" action="{{ route('app.content.translate', $localizedContentSource ?? $content) }}" class="mt-2">
                                                    @csrf
                                                    <input type="hidden" name="target_locale" value="{{ $targetLocale }}">
                                                    <button class="rounded border border-border px-3 py-2 text-xs text-textPrimary hover:bg-surfaceSubtle">
                                                        {{ $target['verb'] ?? 'Translate' }}
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endcan

                    @if(!empty($translationDebugger['translations']) || !empty($translationDebugger['events']))
                        @include('app.content.partials.translation-monitor')
                    @endif
                </div>
                </div>
            </details>

            <details class="mb-5 rounded-2xl border border-border/80 bg-white p-5">
                <summary class="cursor-pointer list-none text-sm font-semibold text-textPrimary">Content chain intelligence</summary>
                <div class="mt-5">
                    @include('app.content.partials.chain-intelligence')
                </div>
            </details>

            @can('update', $content)
                <details class="mt-4 rounded-2xl border border-border/80 bg-white p-5">
                    <summary class="cursor-pointer list-none text-sm font-semibold text-textPrimary">Advanced publishing controls</summary>
                    <div class="mt-4 rounded border border-border p-3">
                    @if ($isSyncedTranslation)
                        <div class="rounded border border-border bg-background px-3 py-2 text-xs text-textSecondary">
                            @if ($linkedSourceSchedule)
                                Translations will publish at: {{ $linkedSourceSchedule->format('Y-m-d H:i') }}
                            @else
                                Manual scheduling is disabled while this locale is synced with its source.
                            @endif
                        </div>
                    @else
                        <form method="POST" action="{{ route('app.content.schedule', $content) }}" class="flex flex-wrap items-end gap-2">
                            @csrf
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary">Schedule publish</label>
                                <input type="datetime-local" name="scheduled_publish_at" value="{{ optional($content->scheduled_publish_at)->format('Y-m-d\\TH:i') }}" class="rounded border border-border px-2 py-2 text-sm">
                            </div>
                            <button class="rounded border border-border px-3 py-2 text-sm">Save schedule</button>
                        </form>
                    @endif
                    @if ($supportsImmediatePublish)
                        <form method="POST" action="{{ route('app.content.publish-now', $content) }}" class="mt-2">
                            @csrf
                            <button class="rounded border border-border px-3 py-2 text-sm">Publish now</button>
                        </form>
                    @endif
                    @if ($isWordPressDestination)
                    <div class="mt-3 rounded border border-border p-3">
                        <div class="text-xs text-textSecondary">Push this draft to a connected WordPress site</div>
                        @if (($wordpressSites ?? collect())->isEmpty())
                            <p class="mt-2 text-xs text-amber-700">
                                No WordPress site connected yet.
                                <a href="{{ route('app.sites') }}" class="underline">Connect a site first</a>.
                            </p>
                        @else
                            <form method="POST" action="{{ route('app.content.push-to-site', $content) }}" class="mt-2 flex flex-wrap items-end gap-2">
                                @csrf
                                <div>
                                    <label class="mb-1 block text-xs text-textSecondary">Destination site</label>
                                    <select name="site_id" class="rounded border border-border px-2 py-2 text-sm" required>
                                        @foreach (($wordpressSites ?? collect()) as $siteOption)
                                            <option value="{{ $siteOption->id }}" @selected((string) $content->client_site_id === (string) $siteOption->id)>
                                                {{ $siteOption->name }} ({{ $siteOption->base_url ?: $siteOption->site_url }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <button class="rounded border border-border px-3 py-2 text-sm">Push to selected site</button>
                            </form>
                        @endif
                    </div>
                    @elseif ($isLaravelDestination)
                    <div class="mt-3 rounded border border-border bg-background p-3 text-xs text-textSecondary">
                        <div class="mb-2 font-medium text-textPrimary">Laravel publishing</div>
                        <div>Republish to Laravel to refresh the live route and remote payload.</div>
                        @if ($content->published_url)
                            <div class="mt-1">Live URL: <a href="{{ $content->published_url }}" target="_blank" rel="noopener" class="underline">{{ $content->published_url }}</a></div>
                        @endif
                    </div>
                    @elseif ($destination)
                    <div class="mt-3 rounded border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                        Unknown destination: {{ $destination->type?->value ?? $destination->type }}
                    </div>
                    @endif
                    @if ($shouldShowDetailPublishError)
                        <p class="mt-2 text-xs text-rose-700">Last publish error: {{ $detailLastPublishError }}</p>
                    @endif
                    @if ($isLaravelDestination && $hasLaravelConnectorDestination)
                        <div class="mt-3 rounded border border-border bg-background p-3 text-xs text-textSecondary">
                            <div>Sync endpoint: {{ $laravelDestination->laravelConnectorSyncUrl() ?? 'n/a' }}</div>
                            <div>Health endpoint: {{ $laravelDestination->laravelConnectorHealthUrl() ?? 'n/a' }}</div>
                            <div>Canonical publication: {{ $laravelPublication?->delivery_status ?? 'pending' }}@if($laravelPublication?->remote_status) · {{ $laravelPublication->remote_status }}@endif</div>
                            <div>Last sync attempt: {{ optional($latestLaravelSyncAttempt?->created_at)->format('Y-m-d H:i') ?? 'never' }}</div>
                            <div>Last sync status: {{ $latestLaravelSyncAttempt?->status ?? 'n/a' }}@if($latestLaravelSyncAttempt?->response_status) · HTTP {{ $latestLaravelSyncAttempt->response_status }}@endif</div>
                            @if (!empty($latestLaravelSyncAttempt?->error_message))
                                <div class="text-rose-700">Last sync error: {{ $latestLaravelSyncAttempt->error_message }}</div>
                            @endif
                        </div>
                        @if (($recentLaravelSyncAttempts ?? collect())->isNotEmpty())
                            <div class="mt-3 rounded border border-border bg-background p-3 text-xs text-textSecondary">
                                <div class="mb-2 font-medium text-textPrimary">Recent connector deliveries</div>
                                <div class="space-y-1">
                                    @foreach (($recentLaravelSyncAttempts ?? collect()) as $syncAttempt)
                                        <div>
                                            {{ $syncAttempt->created_at?->format('Y-m-d H:i:s') ?? 'n/a' }}
                                            · {{ $syncAttempt->status }}
                                            @if($syncAttempt->response_status)
                                                · HTTP {{ $syncAttempt->response_status }}
                                            @endif
                                            · {{ $syncAttempt->trigger_source }}
                                            @if(!empty($syncAttempt->error_message))
                                                · {{ $syncAttempt->error_message }}
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endif
                    </div>
                </details>
            @endcan
        @elseif ($activeTab === 'brief')
            @php($brief = $content->briefVersion)
            @if ($brief)
                <div class="space-y-3">
                    <div class="text-xs text-textSecondary">Received at {{ $brief->created_at?->format('Y-m-d H:i') }} via {{ $brief->source }}</div>
                    <pre class="whitespace-pre-wrap rounded border border-border bg-background p-3 text-xs">{{ $brief->body }}</pre>
                </div>
            @elseif (!empty($legacyBrief))
                @php($latestBriefDraft = $legacyBriefLatestDraft ?? null)
                <div class="space-y-3">
                    <div class="text-xs text-textSecondary">Received at {{ $legacyBrief->created_at?->format('Y-m-d H:i') }} via legacy brief</div>
                    <div class="flex flex-wrap items-center gap-2">
                        @can('update', $legacyBrief)
                            <a href="{{ route('app.content.workspace.brief.edit', $legacyBrief) }}" class="rounded border border-border px-3 py-1.5 text-sm">Edit brief</a>
                        @endcan
                        @can('generateDraft', $legacyBrief)
                            @if ($legacyBrief->status !== 'archived')
                                <form method="POST" action="{{ route('app.content.workspace.drafts.generate', $legacyBrief) }}">
                                    @csrf
                                    <button class="rounded border border-border px-3 py-1.5 text-sm">Generate draft</button>
                                </form>
                            @endif
                        @endcan
                        @if ($latestBriefDraft)
                            <span class="rounded border border-border px-2 py-1 text-xs text-textSecondary">
                                Draft status: {{ $latestBriefDraft->status }}
                            </span>
                            <span class="text-xs text-textSecondary">
                                Updated {{ optional($latestBriefDraft->updated_at)->format('Y-m-d H:i:s') }}
                            </span>
                            <a href="{{ route('app.drafts.show', $latestBriefDraft) }}" class="rounded border border-border px-3 py-1.5 text-sm">Open draft</a>
                        @endif
                    </div>
                    @if ($latestBriefDraft && !empty($latestBriefDraft->last_error))
                        <div class="rounded border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-800">
                            Last generation error: {{ $latestBriefDraft->last_error }}
                        </div>
                    @endif
                    <div class="rounded border border-border bg-background p-3 text-sm space-y-1">
                        <div><strong>Title:</strong> {{ $legacyBrief->title }}</div>
                        <div><strong>Language:</strong> {{ $legacyBrief->language }}</div>
                        <div><strong>Intent:</strong> {{ $legacyBrief->intent }}</div>
                        <div><strong>Primary keyword:</strong> {{ $legacyBrief->primary_keyword }}</div>
                        <div><strong>Audience:</strong> {{ $legacyBrief->audience }}</div>
                        @if (!empty($legacyBrief->notes))
                            <div><strong>Notes:</strong> {{ $legacyBrief->notes }}</div>
                        @endif
                    </div>
                </div>
            @else
                <div class="text-sm text-textSecondary">No brief version found.</div>
            @endif
        @elseif ($activeTab === 'answers')
            <div class="space-y-4" data-answer-block-root data-status-url="{{ route('app.content.answers', $content) }}">
                @include('app.content.partials.answer-block-status', ['content' => $content])
                <div class="rounded-lg border border-border bg-background p-4">
                    <h3 class="text-sm font-semibold text-textPrimary">Visibility & placement</h3>
                    @if (Route::has('app.content.answer-blocks.settings'))
                        <form method="POST" action="{{ route('app.content.answer-blocks.settings', ['content' => $content, 'tab' => 'answers']) }}" class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,14rem)_minmax(0,12rem)_minmax(0,12rem)_minmax(0,10rem)_auto] xl:items-end">
                            @csrf
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary">Render mode</label>
                                <select name="answer_block_render_mode" class="w-full rounded border border-border bg-surface px-3 py-2 text-sm">
                                    @foreach (\App\Models\Content::answerBlockRenderModes() as $mode)
                                        <option value="{{ $mode }}" @selected(old('answer_block_render_mode', $content->answer_block_render_mode ?: ($content->answerBlocks->isNotEmpty() ? \App\Models\Content::ANSWER_BLOCK_RENDER_MODE_AI_OPTIMIZED : \App\Models\Content::ANSWER_BLOCK_RENDER_MODE_DISABLED)) === $mode)>
                                            {{ str_replace('_', ' ', $mode) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary">Visibility</label>
                                <select name="answer_block_visibility" class="w-full rounded border border-border bg-surface px-3 py-2 text-sm">
                                    @foreach (\App\Models\Content::answerBlockVisibilities() as $visibility)
                                        <option value="{{ $visibility }}" @selected(old('answer_block_visibility', $content->answer_block_visibility ?: (($content->answer_block_render_mode ?? null) === \App\Models\Content::ANSWER_BLOCK_RENDER_MODE_DISABLED ? \App\Models\Content::ANSWER_BLOCK_VISIBILITY_HIDDEN : \App\Models\Content::ANSWER_BLOCK_VISIBILITY_VISIBLE)) === $visibility)>
                                            {{ ucfirst($visibility) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary">Position</label>
                                <select name="answer_block_position" class="w-full rounded border border-border bg-surface px-3 py-2 text-sm">
                                    @foreach (\App\Models\Content::answerBlockPositions() as $position)
                                        <option value="{{ $position }}" @selected(old('answer_block_position', $content->answer_block_position ?: (in_array($content->answer_block_render_mode, \App\Models\Content::answerBlockPositions(), true) ? $content->answer_block_render_mode : \App\Models\Content::ANSWER_BLOCK_POSITION_AI_OPTIMIZED)) === $position)>
                                            {{ str_replace('_', ' ', $position) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary">Max visible</label>
                                <input type="number" min="1" max="10" name="answer_block_max_visible" value="{{ old('answer_block_max_visible', $content->answer_block_max_visible ?? 3) }}" class="w-full rounded border border-border bg-surface px-3 py-2 text-sm">
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button class="rounded border border-border px-3 py-2 text-sm">Save settings</button>
                                <a href="{{ route('app.content.markdown-preview', $content) }}#article-preview" class="rounded border border-border px-3 py-2 text-sm">Open preview</a>
                            </div>
                        </form>
                    @else
                        <div class="mt-4 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                            Answer block settings route is not available yet.
                        </div>
                    @endif
                </div>

                <div class="rounded-lg border border-border bg-background p-4">
                    <h3 class="text-sm font-semibold text-textPrimary">New Answer Block</h3>
                    <form method="POST" action="{{ route('app.content.answer-blocks.store', $content) }}" class="mt-4 grid gap-3">
                        @csrf
                        <input type="text" name="question" value="{{ old('question') }}" placeholder="What is AEO?" class="w-full rounded border border-border bg-surface px-3 py-2 text-sm" required>
                        <textarea name="answer" rows="4" placeholder="AEO is the practice of structuring content so AI systems can extract direct answers." class="w-full rounded border border-border bg-surface px-3 py-2 text-sm" required>{{ old('answer') }}</textarea>
                        <input type="text" name="entities" value="{{ old('entities') }}" placeholder="Google, ChatGPT, PublishLayer" class="w-full rounded border border-border bg-surface px-3 py-2 text-sm">
                        <input type="text" name="platforms" value="{{ old('platforms') }}" placeholder="Google, ChatGPT, Perplexity" class="w-full rounded border border-border bg-surface px-3 py-2 text-sm">
                        <div>
                            <button class="rounded border border-border bg-textPrimary px-3 py-2 text-sm text-white">Add block</button>
                        </div>
                    </form>
                    <form method="POST" action="{{ route('app.content.answer-blocks.generate', $content) }}" class="mt-3" data-answer-block-generate-form>
                        @csrf
                        <button type="submit" class="rounded border border-border px-3 py-2 text-sm" {{ $answerBlockGenerationActive ? 'disabled' : '' }}>
                            {{ $answerBlockGenerationActive ? 'Generating answer blocks…' : 'Regenerate with AI' }}
                        </button>
                    </form>
                </div>

                @include('app.content.partials.answer-block-list', ['content' => $content])
            </div>
        @elseif ($activeTab === 'draft')
            @php($draft = $content->currentVersion)
            @php($draftBody = trim((string) ($draft?->body ?? '')))
            @php($fallbackDraftBody = trim((string) ($legacyDraft->content_html ?? '')))
            @php($regenDraft = $generationDraft ?? $legacyDraft)
            @php($estimatedCredits = (int) ($regenDraft->credit_cost ?? 0))
            @php($availableCredits = (int) data_get($creditSummary, 'available', 0))
            <div class="mb-4 rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary">
                Language: {{ $contentLocaleLabel }}@if($contentSourceLocaleLabel) <span class="text-textSecondary">(Source: {{ $contentSourceLocaleLabel }})</span>@endif
            </div>
            <div class="mb-4 rounded-md border border-border bg-background p-3">
                <form method="POST" action="{{ route('app.content.generation-preferences.update', $content) }}" class="grid gap-3 md:grid-cols-5">
                    @csrf
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Brand Voice</label>
                        <select name="brand_voice_id" class="w-full rounded border border-border px-2 py-2 text-sm">
                            <option value="">Auto default</option>
                            @foreach (($brandVoices ?? collect()) as $voice)
                                <option value="{{ $voice->id }}" @selected((string) $content->brand_voice_id === (string) $voice->id)>
                                    {{ $voice->name }}{{ $voice->is_default ? ' (default)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Buyer Persona</label>
                        <select name="buyer_persona_id" class="w-full rounded border border-border px-2 py-2 text-sm">
                            <option value="">General audience</option>
                            @foreach (($buyerPersonas ?? collect()) as $persona)
                                <option value="{{ $persona->id }}" @selected((string) $content->buyer_persona_id === (string) $persona->id)>
                                    {{ $persona->name }}{{ data_get($persona->profile_data, 'role') ? ' - ' . data_get($persona->profile_data, 'role') : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Team Member Persona</label>
                        <select name="team_member_id" class="w-full rounded border border-border px-2 py-2 text-sm">
                            <option value="">Company perspective</option>
                            @foreach (($teamMembers ?? collect()) as $member)
                                <option value="{{ $member->id }}" @selected((string) $content->team_member_id === (string) $member->id)>
                                    {{ $member->name }}{{ $member->role ? ' - '.$member->role : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Writer Profile</label>
                        <select name="writer_profile_id" class="w-full rounded border border-border px-2 py-2 text-sm">
                            <option value="">No writer profile</option>
                            @foreach (($writerProfiles ?? collect()) as $profile)
                                <option value="{{ $profile->id }}" @selected((string) $content->writer_profile_id === (string) $profile->id)>
                                    {{ $profile->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Article Length</label>
                        <select name="preferred_length" class="w-full rounded border border-border px-2 py-2 text-sm">
                            @foreach (['short' => 'Short (600-800)', 'medium' => 'Medium (900-1200)', 'long' => 'Long (1400-1800)', 'pillar' => 'Pillar (2200-3000)'] as $key => $label)
                                <option value="{{ $key }}" @selected(($content->preferred_length ?: 'medium') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="md:col-span-4">
                        <button class="rounded border border-border px-3 py-2 text-sm">Save generation preferences</button>
                    </div>
                </form>
                @if (($brandVoices ?? collect())->isEmpty())
                    <div class="mt-2 text-xs text-amber-700">
                        No brand voices found. Generation will use default system voice.
                    </div>
                @endif
                <div class="mt-3 rounded border border-border p-3 text-sm">
                    <div class="mb-2 font-medium">AI Regeneration</div>
                    <div class="grid gap-2 md:grid-cols-3 text-xs text-textSecondary">
                        <div>Estimated credits: <strong class="text-textPrimary">{{ $estimatedCredits }}</strong></div>
                        <div>Available credits: <strong class="text-textPrimary">{{ $availableCredits }}</strong></div>
                        <div>Current draft credit status: <strong class="text-textPrimary">{{ $regenDraft->credit_status ?? 'n/a' }}</strong></div>
                    </div>
                    @if ($regenDraft)
                        <div class="mt-2 grid gap-2 md:grid-cols-2 text-xs text-textSecondary">
                            <div>Generation status: <strong class="text-textPrimary">{{ $regenDraft->status ?? 'n/a' }}</strong></div>
                            <div>Last update: <strong class="text-textPrimary">{{ optional($regenDraft->updated_at)->format('Y-m-d H:i:s') ?? 'n/a' }}</strong></div>
                        </div>
                        @if (!empty($regenDraft->last_error))
                            <div class="mt-2 rounded border border-rose-500/30 bg-rose-500/10 px-2 py-2 text-xs text-rose-800">
                                Last generation error: {{ $regenDraft->last_error }}
                            </div>
                        @endif
                    @endif
                    @can('generateDraft', $content)
                        @if ($regenDraft)
                            <form method="POST" action="{{ route('app.content.regenerate', $content) }}" class="mt-3">
                                @csrf
                                @if (($isWordPressSite ?? false))
                                    <label class="mb-2 flex items-center gap-2 text-xs text-textSecondary">
                                        <input type="checkbox" name="auto_repush_to_wp" value="1" class="rounded border-border">
                                        Auto repush to WordPress after regenerate
                                    </label>
                                @endif
                                <button class="rounded border border-border px-3 py-2 text-sm">
                                    Regenerate Draft (uses credits)
                                </button>
                            </form>
                        @else
                            <div class="mt-3 text-xs text-amber-700">No draft available yet to regenerate.</div>
                        @endif
                    @endcan
                </div>
            </div>
            <div class="mb-3 flex flex-wrap items-center gap-2">
                @if (!empty($legacyDraft))
                    <a href="{{ route('app.drafts.show', $legacyDraft) }}"
                       class="inline-flex items-center rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                        Open Draft
                    </a>
                @endif
                @if (config('features.network_linking'))
                    {{-- TODO(FEATURE): Re-enable network linking when ready. --}}
                    <a href="{{ route('app.network-linking.index') }}"
                       class="inline-flex items-center rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                        Open Network Linking Settings
                    </a>
                @endif
            </div>
            @if ($draft && ($draftBody !== '' || $fallbackDraftBody !== ''))
                <form method="POST" action="{{ route('app.content.revisions.store', $content) }}" class="space-y-2">
                    @csrf
                    <textarea name="note" class="w-full rounded border border-border px-3 py-2 text-sm" rows="2" placeholder="Optional note for this revision">{{ old('note') }}</textarea>
                    <textarea name="body" class="w-full rounded border border-border px-3 py-2 text-sm" rows="16">{{ old('body', $draftBody !== '' ? $draftBody : $fallbackDraftBody) }}</textarea>
                    <button class="rounded border border-border px-3 py-2 text-sm">Save as revision</button>
                </form>
            @elseif (!empty($legacyDraft))
                <form method="POST" action="{{ route('app.content.revisions.store', $content) }}" class="space-y-2">
                    @csrf
                    <textarea name="note" class="w-full rounded border border-border px-3 py-2 text-sm" rows="2" placeholder="Optional note for this revision">{{ old('note') }}</textarea>
                    <textarea name="body" class="w-full rounded border border-border px-3 py-2 text-sm" rows="16">{{ old('body', $legacyDraft->content_html) }}</textarea>
                    <button class="rounded border border-border px-3 py-2 text-sm">Save as revision</button>
                </form>
            @else
                <div class="text-sm text-textSecondary">No draft version found.</div>
            @endif
        @elseif ($activeTab === 'images')
            @php($image = $featuredImage ?? null)
            @php($og = $ogImage ?? null)
            @php($isGenerating = in_array((string) ($image->status ?? ''), ['queued', 'generating'], true))
            @php($isGeneratingOg = in_array((string) ($og->status ?? ''), ['queued', 'generating'], true))
            @php($imagePreviewBase = $image?->medium_ui_url ?: $image?->original_ui_url)
            @php($ogPreviewBase = $og?->medium_ui_url ?: $og?->original_ui_url)
            @php($imageVersion = $image?->updated_at?->getTimestamp())
            @php($ogVersion = $og?->updated_at?->getTimestamp())
            @php($imagePreviewUrl = $imagePreviewBase ? $imagePreviewBase.(str_contains((string) $imagePreviewBase, '?') ? '&' : '?').'v='.(string) ($imageVersion ?? time()) : null)
            @php($ogPreviewUrl = $ogPreviewBase ? $ogPreviewBase.(str_contains((string) $ogPreviewBase, '?') ? '&' : '?').'v='.(string) ($ogVersion ?? time()) : null)
            @php($canPush = $image && $image->status === 'ready' && !empty($image->getWordPressUploadUrl($content->clientSite)))
            @php($canPushOg = $og && $og->status === 'ready' && !empty($og->getWordPressUploadUrl($content->clientSite)))
            @php($featuredImageHistoryCollection = collect($featuredImageHistory ?? []))
            @php($ogImageHistoryCollection = collect($ogImageHistory ?? []))
            @php($featuredAttribution = is_array($image?->metadata ?? null) ? data_get($image->metadata, 'attribution') : null)
            <div class="space-y-4">
                @can('update', $content)
                    <div class="rounded border border-border p-4">
                        <div class="mb-2 text-sm font-medium text-textPrimary">Image generation instructions</div>
                        <p class="mb-3 text-xs text-textSecondary">
                            Add style direction or constraints for image generation, for example: "minimalist photo style, no text, cool blue tones".
                        </p>
                        <form method="POST" action="{{ route('app.content.images.preferences.update', $content) }}" class="space-y-2">
                            @csrf
                            <div class="grid gap-2 md:grid-cols-[1fr_auto]">
                                @if (!empty($imagePresets))
                                    <select id="image-preset-select" class="rounded border border-border px-3 py-2 text-sm">
                                        <option value="">Select a preset</option>
                                        @foreach ($imagePresets as $preset)
                                            <option value="{{ $preset['instructions'] }}">
                                                {{ $preset['name'] }}{{ $preset['is_default'] ? ' (Default)' : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button
                                        type="button"
                                        class="rounded border border-border px-3 py-2 text-sm"
                                        onclick="(function(){var s=document.getElementById('image-preset-select'); var t=document.getElementById('image-prompt-instructions'); if(!s||!t||!s.value){return;} t.value = s.value; t.focus();})();"
                                    >
                                        Apply preset
                                    </button>
                                @else
                                    <div class="text-xs text-textSecondary col-span-full">
                                        No presets available.
                                        <a href="{{ route('app.settings.image-presets.index') }}" class="text-primary hover:underline">Create image presets</a>
                                        to quickly apply consistent visual styles.
                                    </div>
                                @endif
                            </div>
                            <textarea
                                id="image-prompt-instructions"
                                name="image_prompt_instructions"
                                rows="4"
                                maxlength="4000"
                                class="w-full rounded border border-border px-3 py-2 text-sm"
                                placeholder="Example: no text, high contrast, cinematic lighting, realistic photo, avoid people."
                            >{{ old('image_prompt_instructions', (string) ($content->image_prompt_instructions ?? '')) }}</textarea>
                            <button class="rounded border border-border px-3 py-2 text-sm">Save image instructions</button>
                        </form>
                    </div>
                @endcan

                @can('update', $content)
                    <div class="rounded border border-border bg-white p-4">
                        <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-base font-semibold text-textPrimary">Stock images</h3>
                                <p class="mt-1 text-xs text-textSecondary">Search Unsplash and keep the required photo attribution with the selected image.</p>
                            </div>
                            <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">Unsplash</span>
                        </div>

                        @if (!($stockImagesConfigured ?? false))
                            <div class="rounded border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-900">
                                Configure UNSPLASH_ACCESS_KEY to enable stock image search.
                            </div>
                        @else
                            <form method="GET" action="{{ route('app.content.show', ['content' => $content]) }}" class="grid gap-2 md:grid-cols-[1fr_auto]">
                                <input type="hidden" name="tab" value="images">
                                <input
                                    name="stock_image_query"
                                    value="{{ old('stock_image_query', $stockImageQuery ?? ($content->primary_keyword ?: $content->title)) }}"
                                    class="rounded border border-border px-3 py-2 text-sm"
                                    placeholder="Search stock images"
                                >
                                <button class="rounded border border-border px-3 py-2 text-sm">Search Unsplash</button>
                            </form>

                            @if (!empty($stockImageSearchError))
                                <div class="mt-3 rounded border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-800">
                                    {{ $stockImageSearchError }}
                                </div>
                            @endif

                            @if (!empty($stockImageResults))
                                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                                    @foreach ($stockImageResults as $stockImage)
                                        <div class="rounded border border-border p-3">
                                            <a href="{{ $stockImage['photo_url'] }}" target="_blank" rel="noopener" class="mb-3 block overflow-hidden rounded border border-border">
                                                <img src="{{ $stockImage['thumb_url'] ?: $stockImage['image_url'] }}" alt="{{ $stockImage['alt_text'] ?: 'Unsplash image preview' }}" class="h-32 w-full object-cover">
                                            </a>
                                            <div class="mb-3 text-xs text-textSecondary">
                                                Photo by
                                                <a href="{{ $stockImage['photographer_url'] }}" target="_blank" rel="noopener" class="font-medium text-primary hover:underline">{{ $stockImage['photographer_name'] }}</a>
                                                on
                                                <a href="https://unsplash.com" target="_blank" rel="noopener" class="font-medium text-primary hover:underline">Unsplash</a>
                                            </div>
                                            <form method="POST" action="{{ route('app.content.images.featured.unsplash', $content) }}">
                                                @csrf
                                                <input type="hidden" name="photo[id]" value="{{ $stockImage['id'] }}">
                                                <input type="hidden" name="photo[query]" value="{{ $stockImageQuery ?? '' }}">
                                                <input type="hidden" name="photo[urls][regular]" value="{{ $stockImage['image_url'] }}">
                                                <input type="hidden" name="photo[urls][small]" value="{{ $stockImage['thumb_url'] }}">
                                                <input type="hidden" name="photo[links][html]" value="{{ $stockImage['photo_url'] }}">
                                                <input type="hidden" name="photo[links][download_location]" value="{{ $stockImage['download_location'] }}">
                                                <input type="hidden" name="photo[user][name]" value="{{ $stockImage['photographer_name'] }}">
                                                <input type="hidden" name="photo[user][links][html]" value="{{ $stockImage['photographer_url'] }}">
                                                <input type="hidden" name="photo[alt_description]" value="{{ $stockImage['alt_text'] }}">
                                                <input type="hidden" name="photo[width]" value="{{ $stockImage['width'] }}">
                                                <input type="hidden" name="photo[height]" value="{{ $stockImage['height'] }}">
                                                <button class="w-full rounded border border-border px-3 py-2 text-sm">Use photo</button>
                                            </form>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </div>
                @endcan

                <div class="grid grid-cols-1 gap-8 lg:grid-cols-2">
                    <div class="rounded-lg border border-border bg-white p-6">
                        <div class="mb-4 flex items-center justify-between gap-3">
                            <h3 class="text-lg font-semibold text-textPrimary">Current Featured Image</h3>
                            @if ($image?->is_active)
                                <span class="rounded-full bg-green-100 px-2.5 py-1 text-xs font-medium text-green-700">Active</span>
                            @endif
                        </div>

                        @if ($image && !empty($imagePreviewUrl))
                            <div class="mb-4 overflow-hidden rounded-lg border border-border bg-background">
                                <img src="{{ $imagePreviewUrl }}" alt="Current featured image for {{ $content->title }}" class="h-64 w-full object-cover">
                            </div>
                        @else
                            <div class="mb-4 rounded-lg border border-dashed border-border p-6 text-sm text-textSecondary">
                                No featured image available.
                            </div>
                        @endif

                        @php($featuredFilename = basename((string) ($image?->image_path ?: $image?->original_path ?: $image?->medium_path ?: '')))
                        @php($featuredDimensions = ($image?->width && $image?->height) ? ($image->width.'x'.$image->height) : 'n/a')
                        <div class="grid gap-2 text-sm text-textSecondary">
                            <div>Filename: <span class="font-medium text-textPrimary">{{ $featuredFilename !== '' ? $featuredFilename : 'n/a' }}</span></div>
                            <div>Dimensions: <span class="font-medium text-textPrimary">{{ $featuredDimensions }}</span></div>
                            <div>Last updated: <span class="font-medium text-textPrimary">{{ optional($image?->updated_at)->format('Y-m-d H:i') ?? 'n/a' }}</span></div>
                            <div>Status: <span class="font-medium text-textPrimary">{{ $image->status ?? 'none' }}</span></div>
                        </div>

                        @if (is_array($featuredAttribution))
                            <div class="mt-3 rounded border border-border bg-background px-3 py-2 text-xs text-textSecondary">
                                Photo by
                                <a href="{{ data_get($featuredAttribution, 'photographer_url') }}" target="_blank" rel="noopener" class="font-medium text-primary hover:underline">{{ data_get($featuredAttribution, 'photographer_name') }}</a>
                                on
                                <a href="{{ data_get($featuredAttribution, 'provider_url', 'https://unsplash.com') }}" target="_blank" rel="noopener" class="font-medium text-primary hover:underline">{{ data_get($featuredAttribution, 'provider_name', 'Unsplash') }}</a>
                            </div>
                        @endif

                        @if ($image && !empty($image->error_message))
                            <div class="mt-3 rounded border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-800">
                                {{ $image->error_message }}
                            </div>
                        @endif

                        <div class="mt-4 flex flex-wrap gap-2">
                            @can('generateImage', $content)
                                <form method="POST" action="{{ route('app.content.images.featured.generate', $content) }}" class="inline-flex">
                                    @csrf
                                    <button class="rounded border border-border px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60" @disabled($isGenerating)>
                                        {{ $image ? 'Replace' : 'Generate featured image' }}
                                    </button>
                                </form>
                            @endcan

                            @if (($isWordPressSite ?? false))
                                @can('pushFeaturedImage', $content)
                                    <form method="POST" action="{{ route('app.content.images.featured.push', $content) }}" class="inline-flex">
                                        @csrf
                                        <button class="rounded border border-border px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60" @disabled(! $canPush)>
                                            Push featured image
                                        </button>
                                    </form>
                                @endcan
                            @endif
                        </div>
                    </div>

                    <div class="rounded-lg border border-border bg-white p-6">
                        <div class="mb-4 flex items-center justify-between gap-3">
                            <h3 class="text-lg font-semibold text-textPrimary">Current OG Image</h3>
                            @if ($og)
                                <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">
                                    {{ (($og->provider ?? '') === 'pl-renderer') ? 'Generated' : 'Custom' }}
                                </span>
                            @else
                                <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">Not set</span>
                            @endif
                        </div>

                        <p class="mb-3 text-xs text-textSecondary">
                            Generated at 1200x630 with PublishLayer overlay template.
                        </p>

                        @if ($og && !empty($ogPreviewUrl))
                            <div class="mb-4 overflow-hidden rounded-lg border border-border bg-background">
                                <img src="{{ $ogPreviewUrl }}" alt="Current OG image for {{ $content->title }}" class="aspect-[1200/630] w-full object-cover">
                            </div>
                        @else
                            <div class="mb-4 rounded-lg border border-dashed border-border p-6 text-sm text-textSecondary">
                                No OG image available.
                            </div>
                        @endif

                        @php($ogDimensions = ($og?->width && $og?->height) ? ($og->width.'x'.$og->height) : 'n/a')
                        <div class="grid gap-2 text-sm text-textSecondary">
                            <div>Type: <span class="font-medium text-textPrimary">{{ (($og?->provider ?? '') === 'pl-renderer') ? 'Generated' : 'Custom' }}</span></div>
                            <div>Dimensions: <span class="font-medium text-textPrimary">{{ $ogDimensions }}</span></div>
                            <div>Last generated: <span class="font-medium text-textPrimary">{{ optional($og?->updated_at)->format('Y-m-d H:i') ?? 'n/a' }}</span></div>
                            <div>Status: <span class="font-medium text-textPrimary">{{ $og->status ?? 'none' }}</span></div>
                        </div>

                        @if ($og && !empty($og->error_message))
                            <div class="mt-3 rounded border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-800">
                                {{ $og->error_message }}
                            </div>
                        @endif

                        <div class="mt-4 flex flex-wrap gap-2">
                            @can('generateImage', $content)
                                <form method="POST" action="{{ route('app.content.images.og.generate', $content) }}" class="inline-flex">
                                    @csrf
                                    <button class="rounded border border-border px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60" @disabled($isGeneratingOg)>
                                        {{ $og ? 'Regenerate OG' : 'Generate OG image' }}
                                    </button>
                                </form>
                            @endcan

                            @if ($og && !empty($og->original_ui_url))
                                <a href="{{ $og->original_ui_url }}" download class="rounded border border-border px-3 py-2 text-sm">Download OG image</a>
                                <button
                                    type="button"
                                    class="rounded border border-border px-3 py-2 text-sm"
                                    onclick="navigator.clipboard && navigator.clipboard.writeText('{{ $og->original_ui_url }}')"
                                >
                                    Copy OG image URL
                                </button>
                            @endif

                            @if (($isWordPressSite ?? false))
                                @can('pushFeaturedImage', $content)
                                    <form method="POST" action="{{ route('app.content.images.og.push', $content) }}" class="inline-flex">
                                        @csrf
                                        <button class="rounded border border-border px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60" @disabled(! $canPushOg)>
                                            Push OG image
                                        </button>
                                    </form>
                                @endcan
                            @endif
                        </div>
                    </div>
                </div>

                <div class="mt-12 grid grid-cols-1 gap-8 lg:grid-cols-2">
                    <div class="rounded-lg border border-border bg-white p-6">
                        <h3 class="mb-4 text-lg font-semibold text-textPrimary">Featured Image History</h3>

                        @if ($featuredImageHistoryCollection->isEmpty())
                            <div class="rounded-lg border border-dashed border-border p-6 text-sm text-textSecondary">No history yet</div>
                        @else
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach ($featuredImageHistoryCollection as $historyItem)
                                    @php($historyImageUrl = $historyItem->medium_ui_url ?: $historyItem->original_ui_url)
                                    <div class="rounded-lg border p-3 {{ $historyItem->is_active ? 'border-green-300 bg-green-50/60' : 'border-border bg-white' }}">
                                        @if(!empty($historyImageUrl))
                                            <a href="{{ $historyImageUrl }}" target="_blank" class="mb-3 block overflow-hidden rounded border border-border">
                                                <img src="{{ $historyItem->thumbnail_ui_url ?: $historyImageUrl }}" alt="Featured image history preview" class="h-24 w-full object-cover">
                                            </a>
                                        @endif

                                        <div class="space-y-1 text-xs text-textSecondary">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <span class="rounded bg-gray-100 px-2 py-0.5 text-gray-600">Version {{ $loop->iteration }}</span>
                                                @if ($historyItem->is_active)
                                                    <span class="rounded bg-green-100 px-2 py-0.5 text-green-700">Active</span>
                                                @endif
                                            </div>
                                            <div>{{ optional($historyItem->created_at)->format('Y-m-d H:i:s') }}</div>
                                            <div>Status: <span class="font-medium text-textPrimary">{{ $historyItem->status }}</span></div>
                                        </div>

                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @if ($historyItem->status === 'ready' && ! $historyItem->is_active)
                                                <form method="POST" action="{{ route('app.content.images.versions.restore', ['content' => $content, 'imageVersion' => $historyItem]) }}" class="inline-flex">
                                                    @csrf
                                                    <input type="hidden" name="image_type" value="featured">
                                                    <button class="rounded border border-border px-2 py-1 text-[11px]">Restore</button>
                                                </form>
                                            @endif
                                            @if (! $historyItem->is_active)
                                                <form method="POST" action="{{ route('app.content.images.versions.delete', ['content' => $content, 'imageVersion' => $historyItem]) }}" class="inline-flex" onsubmit="return confirm('Delete this image version?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="rounded border border-border px-2 py-1 text-[11px]">Delete</button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="rounded-lg border border-border bg-white p-6">
                        <h3 class="mb-4 text-lg font-semibold text-textPrimary">OG Image History</h3>

                        @if ($ogImageHistoryCollection->isEmpty())
                            <div class="rounded-lg border border-dashed border-border p-6 text-sm text-textSecondary">No history yet</div>
                        @else
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @foreach ($ogImageHistoryCollection as $historyItem)
                                    @php($historyImageUrl = $historyItem->medium_ui_url ?: $historyItem->original_ui_url)
                                    <div class="rounded-lg border p-3 {{ $historyItem->is_active ? 'border-green-300 bg-green-50/60' : 'border-border bg-white' }}">
                                        @if(!empty($historyImageUrl))
                                            <a href="{{ $historyImageUrl }}" target="_blank" class="mb-3 block overflow-hidden rounded border border-border">
                                                <img src="{{ $historyItem->thumbnail_ui_url ?: $historyImageUrl }}" alt="OG image history preview" class="aspect-[1200/630] w-full object-cover">
                                            </a>
                                        @endif

                                        <div class="space-y-1 text-xs text-textSecondary">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <span class="rounded bg-gray-100 px-2 py-0.5 text-gray-600">{{ (($historyItem->provider ?? '') === 'pl-renderer') ? 'Generated' : 'Custom' }}</span>
                                                @if ($historyItem->is_active)
                                                    <span class="rounded bg-green-100 px-2 py-0.5 text-green-700">Active</span>
                                                @endif
                                            </div>
                                            <div>{{ optional($historyItem->created_at)->format('Y-m-d H:i:s') }}</div>
                                            <div>Status: <span class="font-medium text-textPrimary">{{ $historyItem->status }}</span></div>
                                        </div>

                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @if ($historyItem->status === 'ready' && ! $historyItem->is_active)
                                                <form method="POST" action="{{ route('app.content.images.versions.restore', ['content' => $content, 'imageVersion' => $historyItem]) }}" class="inline-flex">
                                                    @csrf
                                                    <input type="hidden" name="image_type" value="og">
                                                    <button class="rounded border border-border px-2 py-1 text-[11px]">Restore</button>
                                                </form>
                                            @endif
                                            @if (! $historyItem->is_active)
                                                <form method="POST" action="{{ route('app.content.images.versions.delete', ['content' => $content, 'imageVersion' => $historyItem]) }}" class="inline-flex" onsubmit="return confirm('Delete this image version?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="rounded border border-border px-2 py-1 text-[11px]">Delete</button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @elseif ($activeTab === 'revisions')
            <div class="space-y-2">
                @forelse ($content->versions as $version)
                    @php($versionLabel = data_get($version->meta, 'label'))
                    @php($versionNote = data_get($version->meta, 'note'))
                    <div class="rounded border border-border p-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                        <div>
                            <strong>{{ $version->type }}</strong> · {{ $version->created_at?->format('Y-m-d H:i') }}
                            @if ($versionLabel)
                                <span class="ml-2 text-textSecondary">{{ $versionLabel }}</span>
                            @endif
                            @if ($version->id === $content->current_version_id)
                                <span class="ml-2 rounded bg-emerald-500/10 px-2 py-1 text-xs text-emerald-700">Current</span>
                            @endif
                            @if ($versionNote)
                                <div class="mt-1 text-xs text-textSecondary">Note: {{ $versionNote }}</div>
                            @endif
                        </div>
                        @can('restoreRevision', $content)
                            @if ($version->id !== $content->current_version_id && in_array($version->type, ['draft', 'revision'], true))
                                <form method="POST" action="{{ route('app.content.versions.restore', [$content, $version]) }}">
                                    @csrf
                                    <button class="rounded border border-border px-2 py-1 text-xs">Restore</button>
                                </form>
                            @endif
                        @endcan
                    </div>
                @empty
                    <div class="text-sm text-textSecondary">No versions yet.</div>
                @endforelse
            </div>
        @else
            <div class="space-y-2">
                @forelse ($activity as $event)
                    <div class="rounded border border-border p-3 text-sm">
                        <div class="text-xs text-textSecondary">{{ $event->occurred_at?->format('Y-m-d H:i') }} · {{ $event->type }}</div>
                        <pre class="mt-1 whitespace-pre-wrap text-xs">{{ json_encode($event->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                @empty
                    <div class="text-sm text-textSecondary">No activity yet.</div>
                @endforelse
            </div>
        @endif
            </div>
        </div>

        <div class="mt-6 lg:col-span-4 lg:mt-0 xl:col-span-3">
            <x-content-insights-sidebar
                :content="$content"
                :active-tab="$activeTab"
                :selected-insight="$selectedInsight"
                :content-health-score="$contentHealthScore"
                :localization-status-label="$localizationStatusLabel"
                :localization-status-class="$localizationStatusClass"
                :refresh-status-label="$refreshStatusLabel"
                :refresh-status-class="$refreshStatusClass"
                :links-status-label="$linksStatusLabel"
                :links-status-class="$linksStatusClass"
                :has-any-insight-results="$hasAnyInsightResults"
                :has-localization-results="$hasLocalizationResults"
                :has-refresh-results="$hasRefreshResults"
                :has-links-results="$hasLinksResults"
                :localization-summary="$localizationSummary"
                :refresh-summary="$refreshSummary"
                :links-summary="$linksSummary"
                :localization-run="$localizationRun"
                :refresh-run="$refreshRecommendationsRun"
                :internal-linking-run="$internalLinkingRun"
                :localized-content-source="$localizedContentSource"
                :locale-mismatch-analysis="$localeMismatchAnalysis ?? null"
            />
        </div>
    </div>

    @can('update', $content)
        <div class="pointer-events-none fixed inset-x-0 bottom-0 z-30 px-4 pb-4">
            <div class="pointer-events-auto mx-auto max-w-6xl rounded-2xl border border-border bg-white/95 px-4 py-3 backdrop-blur">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="text-sm font-medium text-textPrimary">
                            {{ $hasUnsavedChanges ? 'Draft updated and awaiting publish decision' : 'Workflow ready' }}
                        </div>
                        <div class="mt-1 text-xs text-textSecondary">
                            @if ($content->scheduled_publish_at)
                                Scheduled for {{ $content->scheduled_publish_at->format('M j, H:i') }}
                            @elseif ($content->published_url)
                                Live route available at {{ $content->published_url }}
                            @else
                                No schedule set yet. Use quick actions to translate, schedule, or publish.
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('app.content.show', ['content' => $content, 'tab' => 'draft']) }}" class="rounded-full border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                            Open Draft
                        </a>
                        @if (! $isSyncedTranslation)
                            <details class="group">
                                <summary class="list-none rounded-full border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                    Schedule
                                </summary>
                                <form method="POST" action="{{ route('app.content.schedule', $content) }}" class="pl-elevation-overlay absolute bottom-16 right-4 mt-2 rounded-2xl border border-border bg-white p-3">
                                    @csrf
                                    <label class="mb-2 block text-[11px] uppercase tracking-wide text-textSecondary">Publish at</label>
                                    <input type="datetime-local" name="scheduled_publish_at" value="{{ optional($content->scheduled_publish_at)->format('Y-m-d\\TH:i') }}" class="rounded-xl border border-border px-3 py-2 text-sm">
                                    <button class="mt-3 w-full rounded-full border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">Save schedule</button>
                                </form>
                            </details>
                        @endif
                        @if ($supportsImmediatePublish)
                            <form method="POST" action="{{ route('app.content.publish-now', $content) }}">
                                @csrf
                                <button class="rounded-full bg-textPrimary px-4 py-2 text-sm font-medium text-white hover:opacity-90">Publish</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endcan

    @if ($activeTab === 'overview')
        <script>
            (function () {
                var root = document.querySelector('[data-content-improvement-root]');
                if (!root) {
                    return;
                }

                var statusUrl = root.getAttribute('data-status-url');
                var latestEventId = Number(root.getAttribute('data-latest-event-id') || '0');
                var pollHandle = null;
                var submitting = false;

                function ensureToastHost() {
                    var host = document.getElementById('content-improvement-toast-host');
                    if (host) {
                        return host;
                    }

                    host = document.createElement('div');
                    host.id = 'content-improvement-toast-host';
                    host.className = 'fixed right-4 top-20 z-50 space-y-2';
                    document.body.appendChild(host);

                    return host;
                }

                function showToast(message, tone) {
                    if (!message) {
                        return;
                    }

                    var host = ensureToastHost();
                    var item = document.createElement('div');
                    item.className = 'pl-elevation-overlay rounded-xl border px-4 py-3 text-sm ' + (
                        tone === 'error'
                            ? 'border-rose-200 bg-rose-50 text-rose-700'
                            : tone === 'success'
                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                : 'border-sky-200 bg-sky-50 text-sky-800'
                    );
                    item.textContent = message;
                    host.appendChild(item);

                    window.setTimeout(function () {
                        item.remove();
                    }, 3600);
                }

                function spinnerMarkup(tone) {
                    var colorClass = tone === 'dark' ? 'bg-white/80' : (tone === 'error' ? 'bg-rose-500' : 'bg-sky-500');

                    return '<span class="inline-block h-2 w-2 animate-pulse rounded-full ' + colorClass + '"></span>';
                }

                function csrfTokenFor(form) {
                    var tokenInput = form.querySelector('input[name="_token"]');

                    return tokenInput ? tokenInput.value : '';
                }

                function replaceSection(id, html) {
                    var current = root.querySelector('#' + id);
                    if (!current || !html) {
                        return;
                    }

                    var wrapper = document.createElement('div');
                    wrapper.innerHTML = html.trim();
                    var replacement = wrapper.firstElementChild;
                    if (!replacement) {
                        return;
                    }

                    current.replaceWith(replacement);
                }

                function hasActiveRuns() {
                    return root.querySelector('#content-improvement-monitor [data-active-run]') !== null;
                }

                function schedulePolling() {
                    if (pollHandle) {
                        window.clearTimeout(pollHandle);
                    }

                    if (!hasActiveRuns()) {
                        return;
                    }

                    pollHandle = window.setTimeout(function () {
                        refreshStatus();
                    }, 4000);
                }

                function toastToneForEvent(type) {
                    if (type === 'FAILED') {
                        return 'error';
                    }

                    if (type === 'COMPLETED' || type === 'APPLIED') {
                        return 'success';
                    }

                    return 'info';
                }

                function handleEvents(events) {
                    var handledCount = 0;

                    (events || []).forEach(function (event) {
                        if (Number(event.id) <= latestEventId) {
                            return;
                        }

                        latestEventId = Number(event.id);
                        handledCount += 1;
                        showToast(event.message, toastToneForEvent(event.event_type));
                    });
                    root.setAttribute('data-latest-event-id', String(latestEventId));

                    return handledCount;
                }

                function setPendingState(button, label, tone) {
                    if (!button) {
                        return;
                    }

                    button.disabled = true;
                    button.dataset.originalHtml = button.innerHTML;
                    button.innerHTML = spinnerMarkup(tone || 'info') + '<span>' + label + '</span>';
                }

                function refreshStatus() {
                    fetch(statusUrl, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        credentials: 'same-origin'
                    }).then(function (response) {
                        return response.json();
                    }).then(function (payload) {
                        root.setAttribute('data-latest-event-id', String(payload.latest_event_id || latestEventId));
                        replaceSection('content-improvement-actions', payload.actions_html);
                        replaceSection('content-improvement-monitor', payload.monitor_html);
                        replaceSection('content-improvement-generated', payload.generated_html);
                        handleEvents(payload.events || []);
                        schedulePolling();
                    }).catch(function () {
                        schedulePolling();
                    });
                }

                function submitForm(form, pendingMessage) {
                    if (submitting) {
                        return;
                    }

                    submitting = true;
                    var button = form.querySelector('button[type="submit"]');
                    setPendingState(
                        button,
                        pendingMessage,
                        form.matches('[data-content-improvement-accept-form]') ? 'dark' : 'info'
                    );

                    var body = new FormData(form);
                    fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfTokenFor(form)
                        },
                        body: body,
                        credentials: 'same-origin'
                    }).then(function (response) {
                        if (!response.ok) {
                            throw new Error('Request failed');
                        }

                        return response.json();
                    }).then(function (payload) {
                        root.setAttribute('data-latest-event-id', String(payload.latest_event_id || latestEventId));
                        replaceSection('content-improvement-actions', payload.actions_html);
                        replaceSection('content-improvement-monitor', payload.monitor_html);
                        replaceSection('content-improvement-generated', payload.generated_html);
                        var handledCount = handleEvents(payload.events || []);
                        if (payload.toast && handledCount === 0) {
                            showToast(payload.toast, payload.applied ? 'success' : 'info');
                        }
                        submitting = false;
                        schedulePolling();
                    }).catch(function () {
                        submitting = false;
                        if (button) {
                            button.disabled = false;
                            button.innerHTML = button.dataset.originalHtml || 'Generate';
                        }
                        showToast('The AI improvement action could not be completed.', 'error');
                    });
                }

                root.addEventListener('submit', function (event) {
                    var form = event.target;
                    if (!(form instanceof HTMLFormElement)) {
                        return;
                    }

                    if (
                        !form.matches('[data-content-improvement-form]')
                        && !form.matches('[data-content-improvement-accept-form]')
                        && !form.matches('[data-content-improvement-reject-form]')
                    ) {
                        return;
                    }

                    event.preventDefault();

                    if (form.matches('[data-content-improvement-form]')) {
                        submitForm(form, 'Queued...');
                        return;
                    }

                    if (form.matches('[data-content-improvement-accept-form]')) {
                        submitForm(form, 'Applying...');
                        return;
                    }

                    submitForm(form, 'Rejecting...');
                });

                schedulePolling();
            })();
        </script>
    @endif
    @if ($activeTab === 'answers')
        <script>
            (function () {
                var root = document.querySelector('[data-answer-block-root]');
                if (!root) {
                    return;
                }

                var statusUrl = root.getAttribute('data-status-url');
                var pollHandle = null;

                function replaceSection(id, html) {
                    var current = document.getElementById(id);
                    if (!current || !html) {
                        return;
                    }

                    var wrapper = document.createElement('div');
                    wrapper.innerHTML = html.trim();
                    var replacement = wrapper.firstElementChild;
                    if (!replacement) {
                        return;
                    }

                    current.replaceWith(replacement);
                }

                function hasActiveGeneration(payload) {
                    return !!(payload && payload.generation && payload.generation.is_active);
                }

                function schedulePolling(active) {
                    if (pollHandle) {
                        window.clearTimeout(pollHandle);
                    }

                    if (!active) {
                        return;
                    }

                    pollHandle = window.setTimeout(refreshStatus, 4000);
                }

                function refreshStatus() {
                    fetch(statusUrl, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        credentials: 'same-origin'
                    }).then(function (response) {
                        return response.json();
                    }).then(function (payload) {
                        replaceSection('answer-block-status', payload.status_html);
                        replaceSection('answer-block-list', payload.list_html);
                        schedulePolling(hasActiveGeneration(payload));
                    }).catch(function () {
                        schedulePolling(true);
                    });
                }

                root.addEventListener('submit', function (event) {
                    var form = event.target;
                    if (!(form instanceof HTMLFormElement) || !form.matches('[data-answer-block-generate-form]')) {
                        return;
                    }

                    var button = form.querySelector('button[type="submit"]');
                    if (!button) {
                        return;
                    }

                    button.disabled = true;
                    button.dataset.originalText = button.textContent;
                    button.textContent = 'Generating answer blocks…';
                });

                refreshStatus();
            })();
        </script>
    @endif
@endsection
