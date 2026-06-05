<div class="flex flex-wrap items-center gap-1.5">
    {{-- Primary actions based on stage --}}
    @switch($stage)
        @case(\App\Enums\ContentLifecycleStatus::IDEA)
        @case(\App\Enums\ContentLifecycleStatus::BRIEF)
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded border border-border bg-surface px-2 py-1 text-[11px] font-medium text-textPrimary hover:bg-surfaceSubtle"
                data-lifecycle-transition="draft"
                data-content-id="{{ $content->id }}"
                data-content-title="{{ $content->title }}"
            >
                <i data-lucide="pencil" class="h-3 w-3"></i>
                Start Draft
            </button>
            @break

        @case(\App\Enums\ContentLifecycleStatus::DRAFT)
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded border border-purple-200 bg-purple-50 px-2 py-1 text-[11px] font-medium text-purple-700 hover:bg-purple-100"
                data-lifecycle-transition="review"
                data-content-id="{{ $content->id }}"
                data-content-title="{{ $content->title }}"
            >
                <i data-lucide="send" class="h-3 w-3"></i>
                Send for Review
            </button>
            @break

        @case(\App\Enums\ContentLifecycleStatus::REVIEW)
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-[11px] font-medium text-emerald-700 hover:bg-emerald-100"
                data-lifecycle-transition="approve"
                data-content-id="{{ $content->id }}"
                data-content-title="{{ $content->title }}"
            >
                <i data-lucide="check" class="h-3 w-3"></i>
                Approve
            </button>
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded border border-rose-200 bg-rose-50 px-2 py-1 text-[11px] font-medium text-rose-700 hover:bg-rose-100"
                data-lifecycle-transition="reject"
                data-content-id="{{ $content->id }}"
                data-content-title="{{ $content->title }}"
            >
                <i data-lucide="x" class="h-3 w-3"></i>
                Reject
            </button>
            @break

        @case(\App\Enums\ContentLifecycleStatus::APPROVED)
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded border border-sky-200 bg-sky-50 px-2 py-1 text-[11px] font-medium text-sky-700 hover:bg-sky-100"
                data-lifecycle-transition="scheduled"
                data-content-id="{{ $content->id }}"
                data-content-title="{{ $content->title }}"
            >
                <i data-lucide="clock" class="h-3 w-3"></i>
                Schedule
            </button>
            <a
                href="{{ route('app.content.show', $content) }}"
                class="inline-flex items-center gap-1 rounded border border-green-200 bg-green-50 px-2 py-1 text-[11px] font-medium text-green-700 hover:bg-green-100"
            >
                <i data-lucide="globe" class="h-3 w-3"></i>
                Publish
            </a>
            @break

        @case(\App\Enums\ContentLifecycleStatus::PUBLISHED)
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded border border-orange-200 bg-orange-50 px-2 py-1 text-[11px] font-medium text-orange-700 hover:bg-orange-100"
                data-lifecycle-transition="refresh_needed"
                data-content-id="{{ $content->id }}"
                data-content-title="{{ $content->title }}"
            >
                <i data-lucide="refresh-cw" class="h-3 w-3"></i>
                Mark for Refresh
            </button>
            @break

        @case(\App\Enums\ContentLifecycleStatus::REFRESH_NEEDED)
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] font-medium text-amber-700 hover:bg-amber-100"
                data-lifecycle-transition="draft"
                data-content-id="{{ $content->id }}"
                data-content-title="{{ $content->title }}"
            >
                <i data-lucide="pencil" class="h-3 w-3"></i>
                Start Refresh
            </button>
            @break
    @endswitch

    {{-- Dropdown for secondary actions --}}
    <details class="relative">
        <summary class="cursor-pointer rounded border border-border bg-surface px-2 py-1 text-[11px] text-textSecondary hover:bg-surfaceSubtle list-none [&::-webkit-details-marker]:hidden">
            <i data-lucide="more-horizontal" class="h-3 w-3"></i>
        </summary>
        <div class="absolute right-0 z-20 mt-1 w-48 rounded-md border border-border bg-surface p-1 shadow-lg">
            {{-- Assign --}}
            <button
                type="button"
                class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-xs text-textPrimary hover:bg-surfaceSubtle"
                data-lifecycle-assign="assignee"
                data-content-id="{{ $content->id }}"
            >
                <i data-lucide="user-plus" class="h-3.5 w-3.5"></i>
                Assign
            </button>

            {{-- Set Reviewer --}}
            <button
                type="button"
                class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-xs text-textPrimary hover:bg-surfaceSubtle"
                data-lifecycle-assign="reviewer"
                data-content-id="{{ $content->id }}"
            >
                <i data-lucide="user-check" class="h-3.5 w-3.5"></i>
                Set Reviewer
            </button>

            <hr class="my-1 border-border">

            {{-- View History --}}
            <a
                href="{{ route('app.content.lifecycle.history', $content) }}"
                class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-xs text-textPrimary hover:bg-surfaceSubtle"
            >
                <i data-lucide="history" class="h-3.5 w-3.5"></i>
                View History
            </a>

            {{-- Open Content --}}
            <a
                href="{{ route('app.content.show', $content) }}"
                class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-xs text-textPrimary hover:bg-surfaceSubtle"
            >
                <i data-lucide="external-link" class="h-3.5 w-3.5"></i>
                Open Content
            </a>

            <hr class="my-1 border-border">

            {{-- Archive --}}
            <button
                type="button"
                class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-xs text-rose-600 hover:bg-rose-50"
                data-lifecycle-transition="archived"
                data-content-id="{{ $content->id }}"
                data-content-title="{{ $content->title }}"
            >
                <i data-lucide="archive" class="h-3.5 w-3.5"></i>
                Archive
            </button>
        </div>
    </details>
</div>
