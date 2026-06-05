@php($recentNotifications = $notificationBell['recent'] ?? collect())

<div class="flex items-center justify-between border-b border-border px-3 py-2">
    <p class="text-sm font-semibold text-textPrimary">Admin notifications</p>
    @if ((int) ($notificationBell['unread_count'] ?? 0) > 0)
        <form method="POST" action="{{ route('admin.notifications.read-all') }}" data-notification-bell-form>
            @csrf
            <button
                type="submit"
                data-loading-label="Marking..."
                class="text-xs text-textSecondary hover:text-textPrimary disabled:cursor-wait disabled:opacity-60"
            >Mark all read</button>
        </form>
    @endif
</div>
<div class="max-h-96 overflow-y-auto">
    @forelse ($recentNotifications as $notification)
        <div class="border-b border-border bg-accentYellow-50/40 px-3 py-3 transition-opacity duration-150">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <p class="truncate text-sm font-medium text-textPrimary">{{ $notification->title }}</p>
                        <span class="inline-flex h-2 w-2 rounded-full bg-primary"></span>
                    </div>
                    @if ($notification->body)
                        <p class="mt-1 text-xs text-textSecondary">{{ \Illuminate\Support\Str::limit((string) $notification->body, 110) }}</p>
                    @endif
                    <p class="mt-1 text-[11px] text-textFaint">
                        {{ optional($notification->created_at)->diffForHumans() }}
                        · {{ str_replace('_', ' ', (string) $notification->type) }}
                    </p>
                </div>
                <form method="POST" action="{{ route('admin.notifications.read', $notification) }}" data-notification-bell-form>
                    @csrf
                    <button
                        type="submit"
                        data-loading-label="Reading..."
                        class="rounded border border-border px-2 py-1 text-[11px] text-textSecondary hover:bg-surfaceSubtle disabled:cursor-wait disabled:opacity-60"
                    >Read</button>
                </form>
            </div>
        </div>
    @empty
        <p class="px-3 py-6 text-sm text-textSecondary">No new notifications</p>
    @endforelse
</div>
