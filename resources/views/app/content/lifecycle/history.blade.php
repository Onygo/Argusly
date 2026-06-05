@extends('layouts.app', ['title' => 'Lifecycle History - ' . Str::limit($content->title, 30)])

@section('content')
    <div class="mb-6">
        <div class="flex items-center gap-2 text-sm text-textSecondary">
            <a href="{{ route('app.content.lifecycle.index') }}" class="hover:text-textPrimary hover:underline">Lifecycle</a>
            <i data-lucide="chevron-right" class="h-4 w-4"></i>
            <a href="{{ route('app.content.show', $content) }}" class="hover:text-textPrimary hover:underline">{{ Str::limit($content->title, 40) }}</a>
            <i data-lucide="chevron-right" class="h-4 w-4"></i>
            <span class="text-textPrimary">History</span>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Left: Content Info --}}
        <div class="lg:col-span-1">
            <div class="rounded-lg border border-border bg-surface p-4 shadow-sm">
                <h2 class="text-lg font-semibold text-textPrimary">{{ $content->title }}</h2>

                <div class="mt-4 space-y-3 text-sm">
                    {{-- Current Stage --}}
                    <div class="flex items-center justify-between">
                        <span class="text-textSecondary">Current Stage</span>
                        @php $currentStage = $content->lifecycleStageEnum(); @endphp
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-{{ $currentStage->color() }}-100 px-2.5 py-1 text-xs font-medium text-{{ $currentStage->color() }}-700">
                            <i data-lucide="{{ $currentStage->icon() }}" class="h-3.5 w-3.5"></i>
                            {{ $currentStage->label() }}
                        </span>
                    </div>

                    {{-- Assigned To --}}
                    @if ($content->assignedUser)
                        <div class="flex items-center justify-between">
                            <span class="text-textSecondary">Assigned To</span>
                            <span class="text-textPrimary">{{ $content->assignedUser->name }}</span>
                        </div>
                    @endif

                    {{-- Reviewer --}}
                    @if ($content->reviewerUser)
                        <div class="flex items-center justify-between">
                            <span class="text-textSecondary">Reviewer</span>
                            <span class="text-textPrimary">{{ $content->reviewerUser->name }}</span>
                        </div>
                    @endif

                    {{-- Due Date --}}
                    @if ($content->due_at)
                        <div class="flex items-center justify-between">
                            <span class="text-textSecondary">Due Date</span>
                            <span class="{{ $content->isOverdue() ? 'text-rose-600' : 'text-textPrimary' }}">
                                {{ $content->due_at->format('M j, Y') }}
                                @if ($content->isOverdue())
                                    <span class="text-xs">(Overdue)</span>
                                @endif
                            </span>
                        </div>
                    @endif

                    {{-- Approved --}}
                    @if ($content->approved_at)
                        <div class="flex items-center justify-between">
                            <span class="text-textSecondary">Approved</span>
                            <span class="text-textPrimary">
                                {{ $content->approved_at->format('M j, Y') }}
                                @if ($content->approvedByUser)
                                    by {{ $content->approvedByUser->name }}
                                @endif
                            </span>
                        </div>
                    @endif

                    {{-- Rejection --}}
                    @if ($content->rejected_at)
                        <div class="mt-3 rounded-md border border-rose-200 bg-rose-50 p-3">
                            <div class="text-xs font-medium text-rose-700">
                                Rejected {{ $content->rejected_at->format('M j, Y') }}
                                @if ($content->rejectedByUser)
                                    by {{ $content->rejectedByUser->name }}
                                @endif
                            </div>
                            @if ($content->rejection_reason)
                                <p class="mt-1 text-xs text-rose-600">{{ $content->rejection_reason }}</p>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="mt-4 flex gap-2">
                    <a href="{{ route('app.content.show', $content) }}" class="pl-btn-secondary flex-1 text-center">
                        Open Content
                    </a>
                    <a href="{{ route('app.content.lifecycle.index') }}" class="pl-btn-ghost">
                        Back
                    </a>
                </div>
            </div>
        </div>

        {{-- Right: Event Timeline --}}
        <div class="lg:col-span-2">
            <div class="rounded-lg border border-border bg-surface shadow-sm">
                <div class="border-b border-border px-4 py-3">
                    <h3 class="font-medium text-textPrimary">Lifecycle Timeline</h3>
                </div>

                <div class="divide-y divide-border">
                    @forelse ($events as $event)
                        <div class="p-4">
                            <div class="flex items-start gap-3">
                                {{-- Event Icon --}}
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full
                                    @switch($event->event_type)
                                        @case(\App\Models\ContentLifecycleEvent::TYPE_APPROVAL)
                                            bg-emerald-100 text-emerald-700
                                            @break
                                        @case(\App\Models\ContentLifecycleEvent::TYPE_REJECTION)
                                            bg-rose-100 text-rose-700
                                            @break
                                        @case(\App\Models\ContentLifecycleEvent::TYPE_ASSIGNMENT)
                                        @case(\App\Models\ContentLifecycleEvent::TYPE_REVIEWER_ASSIGNMENT)
                                            bg-sky-100 text-sky-700
                                            @break
                                        @case(\App\Models\ContentLifecycleEvent::TYPE_TRANSITION)
                                            bg-purple-100 text-purple-700
                                            @break
                                        @default
                                            bg-surfaceSubtle text-textSecondary
                                    @endswitch
                                ">
                                    @switch($event->event_type)
                                        @case(\App\Models\ContentLifecycleEvent::TYPE_APPROVAL)
                                            <i data-lucide="check" class="h-4 w-4"></i>
                                            @break
                                        @case(\App\Models\ContentLifecycleEvent::TYPE_REJECTION)
                                            <i data-lucide="x" class="h-4 w-4"></i>
                                            @break
                                        @case(\App\Models\ContentLifecycleEvent::TYPE_ASSIGNMENT)
                                            <i data-lucide="user-plus" class="h-4 w-4"></i>
                                            @break
                                        @case(\App\Models\ContentLifecycleEvent::TYPE_REVIEWER_ASSIGNMENT)
                                            <i data-lucide="user-check" class="h-4 w-4"></i>
                                            @break
                                        @case(\App\Models\ContentLifecycleEvent::TYPE_TRANSITION)
                                            <i data-lucide="arrow-right" class="h-4 w-4"></i>
                                            @break
                                        @case(\App\Models\ContentLifecycleEvent::TYPE_COMMENT)
                                            <i data-lucide="message-square" class="h-4 w-4"></i>
                                            @break
                                        @case(\App\Models\ContentLifecycleEvent::TYPE_DUE_DATE_CHANGE)
                                            <i data-lucide="calendar" class="h-4 w-4"></i>
                                            @break
                                        @default
                                            <i data-lucide="activity" class="h-4 w-4"></i>
                                    @endswitch
                                </div>

                                <div class="min-w-0 flex-1">
                                    {{-- Event Header --}}
                                    <div class="flex flex-wrap items-center gap-2 text-sm">
                                        @switch($event->event_type)
                                            @case(\App\Models\ContentLifecycleEvent::TYPE_TRANSITION)
                                                <span class="font-medium text-textPrimary">
                                                    @if ($event->from_stage)
                                                        Moved from
                                                        <span class="rounded bg-surfaceSubtle px-1.5 py-0.5 text-xs">{{ \App\Enums\ContentLifecycleStatus::tryFrom($event->from_stage)?->label() ?? $event->from_stage }}</span>
                                                        to
                                                    @else
                                                        Started at
                                                    @endif
                                                    <span class="rounded bg-surfaceSubtle px-1.5 py-0.5 text-xs">{{ \App\Enums\ContentLifecycleStatus::tryFrom($event->to_stage)?->label() ?? $event->to_stage }}</span>
                                                </span>
                                                @break
                                            @case(\App\Models\ContentLifecycleEvent::TYPE_APPROVAL)
                                                <span class="font-medium text-emerald-700">Content Approved</span>
                                                @break
                                            @case(\App\Models\ContentLifecycleEvent::TYPE_REJECTION)
                                                <span class="font-medium text-rose-700">Content Rejected</span>
                                                @break
                                            @case(\App\Models\ContentLifecycleEvent::TYPE_ASSIGNMENT)
                                                <span class="font-medium text-textPrimary">
                                                    Assigned to {{ data_get($event->metadata, 'assignee_name', 'someone') }}
                                                </span>
                                                @break
                                            @case(\App\Models\ContentLifecycleEvent::TYPE_REVIEWER_ASSIGNMENT)
                                                <span class="font-medium text-textPrimary">
                                                    {{ data_get($event->metadata, 'reviewer_name', 'Someone') }} set as reviewer
                                                </span>
                                                @break
                                            @case(\App\Models\ContentLifecycleEvent::TYPE_COMMENT)
                                                <span class="font-medium text-textPrimary">Comment added</span>
                                                @break
                                            @case(\App\Models\ContentLifecycleEvent::TYPE_DUE_DATE_CHANGE)
                                                <span class="font-medium text-textPrimary">Due date changed</span>
                                                @break
                                            @default
                                                <span class="font-medium text-textPrimary">{{ ucfirst(str_replace('_', ' ', $event->event_type)) }}</span>
                                        @endswitch
                                    </div>

                                    {{-- Event Meta --}}
                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-textSecondary">
                                        @if ($event->user)
                                            <span>by {{ $event->user->name }}</span>
                                            <span>&middot;</span>
                                        @elseif ($event->actor_type === \App\Models\ContentLifecycleEvent::ACTOR_SYSTEM)
                                            <span>by System</span>
                                            <span>&middot;</span>
                                        @elseif ($event->actor_type === \App\Models\ContentLifecycleEvent::ACTOR_AUTOMATION)
                                            <span>by Automation</span>
                                            <span>&middot;</span>
                                        @endif
                                        <span title="{{ $event->created_at->format('Y-m-d H:i:s') }}">
                                            {{ $event->created_at->diffForHumans() }}
                                        </span>
                                    </div>

                                    {{-- Notes or Metadata --}}
                                    @if ($event->notes)
                                        <div class="mt-2 rounded-md border border-border bg-background p-2 text-xs text-textSecondary">
                                            {{ $event->notes }}
                                        </div>
                                    @endif

                                    @if ($event->event_type === \App\Models\ContentLifecycleEvent::TYPE_REJECTION && data_get($event->metadata, 'rejection_reason'))
                                        <div class="mt-2 rounded-md border border-rose-200 bg-rose-50 p-2 text-xs text-rose-700">
                                            <strong>Reason:</strong> {{ data_get($event->metadata, 'rejection_reason') }}
                                        </div>
                                    @endif

                                    @if ($event->event_type === \App\Models\ContentLifecycleEvent::TYPE_DUE_DATE_CHANGE)
                                        <div class="mt-2 text-xs text-textSecondary">
                                            @if (data_get($event->metadata, 'previous_due_date'))
                                                From {{ \Carbon\Carbon::parse(data_get($event->metadata, 'previous_due_date'))->format('M j, Y') }}
                                            @else
                                                From no due date
                                            @endif
                                            &rarr;
                                            @if (data_get($event->metadata, 'new_due_date'))
                                                {{ \Carbon\Carbon::parse(data_get($event->metadata, 'new_due_date'))->format('M j, Y') }}
                                            @else
                                                No due date
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center">
                            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-surfaceSubtle">
                                <i data-lucide="history" class="h-6 w-6 text-textFaint"></i>
                            </div>
                            <p class="text-sm text-textSecondary">No lifecycle events recorded yet.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
