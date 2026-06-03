<x-app.layout title="Notifications | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Notification center</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Notifications</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">In-app notifications for {{ $account->name }}{{ $brand ? ' and '.$brand->name : '' }}. Email, Slack and webhook channels are preference-ready for later delivery.</p>
            </div>
            <x-ui.badge variant="blue">{{ $unreadCount }} unread</x-ui.badge>
        </div>

        <div class="mt-8 grid gap-6 xl:grid-cols-[1fr_0.85fr]">
            <x-ui.card class="overflow-hidden">
                <div class="border-b border-line bg-panel px-5 py-4">
                    <h2 class="text-base font-semibold text-ink">Inbox</h2>
                </div>
                @forelse ($events as $event)
                    <div class="border-b border-line px-5 py-4 last:border-b-0">
                        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ui.badge>{{ str($event->type)->replace('_', ' ')->headline() }}</x-ui.badge>
                                    @if (! $event->read_at)
                                        <span class="rounded-full bg-blue/10 px-2 py-1 text-xs font-semibold text-blue">Unread</span>
                                    @endif
                                </div>
                                <h3 class="mt-3 text-sm font-semibold text-ink">{{ $event->title }}</h3>
                                <p class="mt-1 text-sm leading-6 text-muted">{{ $event->body }}</p>
                                <time class="mt-2 block text-xs text-muted" datetime="{{ $event->created_at?->toIso8601String() }}">{{ $event->created_at?->diffForHumans() }}</time>
                            </div>
                            @if (! $event->read_at)
                                <form method="POST" action="{{ route('app.notifications.read', $event) }}">
                                    @csrf
                                    <x-ui.button type="submit" variant="secondary" size="sm">Mark read</x-ui.button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <x-dashboard.empty-state title="No notifications" message="Domain events will create in-app notifications when enabled by your preferences." />
                @endforelse
            </x-ui.card>

            <x-ui.card class="p-5">
                <div>
                    <h2 class="text-base font-semibold text-ink">Preferences</h2>
                    <p class="mt-1 text-sm text-muted">Manage notification channels for this account context.</p>
                </div>

                <form method="POST" action="{{ route('app.notifications.preferences') }}" class="mt-5 space-y-4">
                    @csrf
                    <div class="overflow-hidden rounded-md border border-line">
                        <div class="grid grid-cols-[1fr_repeat(4,72px)] gap-2 border-b border-line bg-panel px-3 py-2 text-xs font-semibold uppercase tracking-[0.1em] text-muted">
                            <span>Type</span>
                            @foreach ($channels as $channel)
                                <span class="text-center">{{ str($channel)->headline() }}</span>
                            @endforeach
                        </div>
                        @foreach ($types as $type)
                            <div class="grid grid-cols-[1fr_repeat(4,72px)] gap-2 border-b border-line px-3 py-3 last:border-b-0">
                                <span class="text-sm font-semibold text-ink">{{ str($type)->replace('_', ' ')->headline() }}</span>
                                @foreach ($channels as $channel)
                                    <label class="flex items-center justify-center">
                                        <input
                                            type="checkbox"
                                            name="preferences[{{ $type }}][{{ $channel }}]"
                                            value="1"
                                            @checked($preferences[$type][$channel] ?? false)
                                            class="size-4 rounded border-line text-blue"
                                        >
                                    </label>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                    <x-ui.button type="submit">Save preferences</x-ui.button>
                </form>
            </x-ui.card>
        </div>
    </div>
</x-app.layout>
