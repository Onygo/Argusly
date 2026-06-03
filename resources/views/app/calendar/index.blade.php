<x-app.layout title="Marketing calendar | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Marketing OS</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Marketing calendar</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Plan tasks, campaigns, approvals, social posts and publishing work{{ $brand ? ' for '.$brand->name : ' across all brands' }}.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.badge variant="blue">{{ $items->total() }} items</x-ui.badge>
                <x-ui.button href="{{ route('app.marketing') }}" variant="secondary">Marketing OS</x-ui.button>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 rounded-md border border-line bg-white p-4">
            <form method="GET" action="{{ route('app.calendar') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-[0.9fr_0.9fr_0.9fr_0.9fr_0.9fr_auto]">
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">View</span>
                    <select name="mode" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="month" @selected($mode === 'month')>Monthly</option>
                        <option value="week" @selected($mode === 'week')>Weekly</option>
                        <option value="list" @selected($mode === 'list')>List</option>
                    </select>
                </label>
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Brand</span>
                    <select name="brand_id" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="0" @selected(($filters['brand_id'] ?? null) === null)>All brands</option>
                        @foreach ($brands as $filterBrand)
                            <option value="{{ $filterBrand->id }}" @selected((int) ($filters['brand_id'] ?? $currentBrand->id) === $filterBrand->id)>{{ $filterBrand->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Campaign</span>
                    <select name="campaign_id" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All campaigns</option>
                        @foreach ($campaigns as $campaign)
                            <option value="{{ $campaign->id }}" @selected((int) ($filters['campaign_id'] ?? 0) === $campaign->id)>{{ $campaign->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Type</span>
                    <select name="type" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All types</option>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ str($type)->replace('_', ' ')->headline() }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Assignee</span>
                    <select name="assigned_to" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">Everyone</option>
                        @foreach ($assignableUsers as $assignableUser)
                            <option value="{{ $assignableUser->id }}" @selected((int) ($filters['assigned_to'] ?? 0) === $assignableUser->id)>{{ $assignableUser->name }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex items-end gap-2">
                    <x-ui.button type="submit">Filter</x-ui.button>
                    <x-ui.button href="{{ route('app.calendar') }}" variant="light">Reset</x-ui.button>
                </div>
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Starts</span>
                    <input type="date" name="starts" value="{{ $filters['starts'] ?? '' }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                </label>
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Ends</span>
                    <input type="date" name="ends" value="{{ $filters['ends'] ?? '' }}" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                </label>
            </form>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1fr_0.38fr]">
            <div>
                <x-dashboard.section :title="$mode === 'week' ? 'Weekly view' : ($mode === 'list' ? 'List view' : 'Monthly view')" description="Click any item to inspect ownership, related records and scheduling details.">
                    @if ($mode === 'list')
                        <div class="overflow-hidden rounded-md border border-line">
                            <div class="hidden grid-cols-[0.8fr_1.3fr_0.7fr_0.7fr_0.8fr] gap-4 border-b border-line bg-panel px-4 py-3 text-xs font-semibold uppercase tracking-[0.1em] text-muted md:grid">
                                <span>Date</span>
                                <span>Item</span>
                                <span>Type</span>
                                <span>Owner</span>
                                <span>Status</span>
                            </div>
                            @forelse ($items as $item)
                                <a href="{{ route('app.calendar.show', $item) }}" class="grid gap-3 border-b border-line px-4 py-4 transition last:border-b-0 hover:bg-panel md:grid-cols-[0.8fr_1.3fr_0.7fr_0.7fr_0.8fr] md:items-center">
                                    <span class="text-sm font-medium text-ink">{{ $item->start_at->format('M j, H:i') }}</span>
                                    <span>
                                        <span class="block text-sm font-semibold text-ink">{{ $item->title }}</span>
                                        <span class="mt-1 block text-xs text-muted">{{ $item->campaign?->name ?: $item->brand?->name ?: 'Account-wide' }}</span>
                                    </span>
                                    <span class="text-sm text-muted">{{ str($item->type)->replace('_', ' ')->headline() }}</span>
                                    <span class="text-sm text-muted">{{ $item->assignee?->name ?? 'Unassigned' }}</span>
                                    <span><x-ui.badge>{{ str($item->status)->replace('_', ' ')->headline() }}</x-ui.badge></span>
                                </a>
                            @empty
                                <x-dashboard.empty-state title="No calendar items" message="Create tasks or schedule marketing work to populate this view." />
                            @endforelse
                        </div>
                        <div class="mt-5">{{ $items->links() }}</div>
                    @else
                        <div class="grid grid-cols-7 rounded-md border border-line bg-white text-center text-xs font-semibold uppercase tracking-[0.08em] text-muted">
                            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dayName)
                                <div class="border-r border-line px-2 py-3 last:border-r-0">{{ $dayName }}</div>
                            @endforeach
                        </div>
                        <div class="mt-2 grid grid-cols-1 gap-2 md:grid-cols-7">
                            @foreach ($calendarDays as $day)
                                @php($dayItems = $itemsByDay->get($day->toDateString(), collect()))
                                <div class="min-h-[138px] rounded-md border border-line bg-white p-3 {{ $day->isCurrentMonth() || $mode === 'week' ? '' : 'opacity-60' }}">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-semibold text-ink">{{ $day->format('j') }}</p>
                                        <p class="text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">{{ $day->format('M') }}</p>
                                    </div>
                                    <div class="mt-3 space-y-2">
                                        @foreach ($dayItems->take(4) as $item)
                                            <a href="{{ route('app.calendar.show', $item) }}" class="block rounded-md border border-line bg-panel px-2 py-2 text-left transition hover:border-slate-300 hover:bg-white">
                                                <span class="block truncate text-xs font-semibold text-ink">{{ $item->start_at->format('H:i') }} {{ $item->title }}</span>
                                                <span class="mt-1 block truncate text-[11px] text-muted">{{ str($item->type)->replace('_', ' ')->headline() }} · {{ $item->assignee?->name ?? $item->brand?->name ?? 'Account' }}</span>
                                            </a>
                                        @endforeach
                                        @if ($dayItems->count() > 4)
                                            <p class="text-xs font-medium text-muted">+{{ $dayItems->count() - 4 }} more</p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section>
            </div>

            <div class="space-y-6">
                <x-dashboard.section title="Create task" description="Add work directly onto the calendar.">
                    <form method="POST" action="{{ route('app.calendar.tasks.store') }}" class="space-y-4">
                        @csrf
                        <input type="hidden" name="mode" value="{{ $mode }}">
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Title</span>
                            <input name="title" required class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Prepare launch post">
                        </label>
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Scope</span>
                                <select name="scope" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                    <option value="brand">{{ $currentBrand->name }}</option>
                                    <option value="account">Account-wide</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Due</span>
                                <input name="due_at" type="datetime-local" required class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                            </label>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Status</span>
                                <select name="status" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                    @foreach ($taskStatuses as $status)
                                        <option value="{{ $status }}" @selected($status === 'todo')>{{ str($status)->headline() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Priority</span>
                                <select name="priority" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                    @foreach ($taskPriorities as $priority)
                                        <option value="{{ $priority }}" @selected($priority === 'medium')>{{ str($priority)->headline() }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Assignee</span>
                            <select name="assigned_to" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                <option value="">Unassigned</option>
                                @foreach ($assignableUsers as $assignableUser)
                                    <option value="{{ $assignableUser->id }}">{{ $assignableUser->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.12em] text-muted">Campaign</span>
                            <select name="campaign_id" class="mt-1 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                                <option value="">No campaign</option>
                                @foreach ($campaigns as $campaign)
                                    <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <textarea name="description" rows="2" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink" placeholder="Task notes"></textarea>
                        <x-ui.button type="submit">Create task</x-ui.button>
                    </form>
                </x-dashboard.section>

                <x-dashboard.section title="Upcoming" description="Next dated work in this calendar scope.">
                    <div class="space-y-3">
                        @forelse ($upcoming as $item)
                            <a href="{{ route('app.calendar.show', $item) }}" class="block rounded-md border border-line bg-panel p-4 transition hover:border-slate-300 hover:bg-white">
                                <div class="flex items-start justify-between gap-3">
                                    <p class="text-sm font-semibold text-ink">{{ $item->title }}</p>
                                    <x-ui.badge>{{ str($item->type)->replace('_', ' ')->headline() }}</x-ui.badge>
                                </div>
                                <p class="mt-2 text-xs text-muted">{{ $item->start_at->format('M j, Y H:i') }} · {{ str($item->status)->headline() }}</p>
                            </a>
                        @empty
                            <p class="text-sm text-muted">No upcoming calendar items yet.</p>
                        @endforelse
                    </div>
                </x-dashboard.section>
            </div>
        </div>
    </div>
</x-app.layout>
