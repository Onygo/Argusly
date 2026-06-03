<x-app.layout title="Marketing OS | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Marketing OS</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Marketing dashboard</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">A live operating view for campaigns, work, approvals, recommendations and agents.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button href="{{ route('app.calendar', array_filter($filters)) }}" variant="secondary">Calendar</x-ui.button>
                <x-ui.button href="{{ route('app.audiences') }}" variant="secondary">Audiences</x-ui.button>
                <x-ui.button href="{{ route('app.briefings') }}" variant="secondary">Briefings</x-ui.button>
                <x-ui.button href="{{ route('app.newsletters') }}" variant="secondary">Newsletters</x-ui.button>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 rounded-md border border-line bg-white p-4">
            <form method="GET" action="{{ route('app.marketing') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-[1fr_1fr_1fr_auto]">
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Account</span>
                    <select name="account_id" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        @foreach ($accounts as $filterAccount)
                            <option value="{{ $filterAccount->id }}" @selected($filters['account_id'] === $filterAccount->id)>{{ $filterAccount->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Brand</span>
                    <select name="brand_id" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="0" @selected($filters['brand_id'] === null)>All brands</option>
                        @foreach ($brands as $filterBrand)
                            <option value="{{ $filterBrand->id }}" @selected($filters['brand_id'] === $filterBrand->id)>{{ $filterBrand->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Campaign</span>
                    <select name="campaign_id" class="mt-2 w-full rounded-md border border-line bg-white px-3 py-2 text-sm text-ink">
                        <option value="">All campaigns</option>
                        @foreach ($campaigns as $filterCampaign)
                            <option value="{{ $filterCampaign->id }}" @selected($filters['campaign_id'] === $filterCampaign->id)>{{ $filterCampaign->name }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="flex items-end gap-2">
                    <x-ui.button type="submit">Apply</x-ui.button>
                    <x-ui.button href="{{ route('app.marketing') }}" variant="light">Reset</x-ui.button>
                </div>
            </form>
        </div>

        <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <x-dashboard.info-card label="Active campaigns" :value="$dashboard['activeCampaigns']->count()" />
            <x-dashboard.info-card label="Upcoming tasks" :value="$dashboard['upcomingTasks']->count()" />
            <x-dashboard.info-card label="Overdue tasks" :value="$dashboard['overdueTasks']->count()" />
            <x-dashboard.info-card label="Pending approvals" :value="$dashboard['pendingApprovals']->count()" />
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
            <x-dashboard.section title="Active campaigns" description="Campaigns currently driving marketing execution.">
                <div class="space-y-3">
                    @forelse ($dashboard['activeCampaigns'] as $activeCampaign)
                        <a href="{{ route('app.campaigns.show', $activeCampaign) }}" class="block rounded-md border border-line bg-panel p-4 transition hover:border-slate-300 hover:bg-white">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-ink">{{ $activeCampaign->name }}</p>
                                    <p class="mt-1 text-xs text-muted">{{ $activeCampaign->content_assets_count }} assets · {{ $activeCampaign->board_items_count }} board items</p>
                                </div>
                                <x-ui.badge variant="success">Active</x-ui.badge>
                            </div>
                        </a>
                    @empty
                        <x-dashboard.empty-state title="No active campaigns" message="Active campaigns will appear here when work is in motion." />
                    @endforelse
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Calendar preview" description="The next dated work across the current scope.">
                <div class="space-y-3">
                    @forelse ($dashboard['calendarPreview'] as $item)
                        <a href="{{ route('app.calendar.show', $item) }}" class="block rounded-md border border-line bg-panel p-4 transition hover:border-slate-300 hover:bg-white">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-semibold text-ink">{{ $item->title }}</p>
                                <x-ui.badge>{{ str($item->type)->replace('_', ' ')->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 text-xs text-muted">{{ $item->start_at->format('M j, H:i') }} · {{ $item->campaign?->name ?? $item->brand?->name ?? 'Account-wide' }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-muted">No upcoming calendar items.</p>
                    @endforelse
                </div>
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            <x-dashboard.section title="Upcoming tasks" description="Work due soon or awaiting a date.">
                <div class="space-y-3">
                    @forelse ($dashboard['upcomingTasks'] as $task)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-ink">{{ $task->title }}</p>
                                <x-ui.badge>{{ str($task->priority)->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 text-xs text-muted">Due {{ $task->due_at?->format('M j, H:i') ?? 'not set' }} · {{ $task->assignee?->name ?? 'Unassigned' }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-muted">No upcoming tasks.</p>
                    @endforelse
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Overdue tasks" description="Tasks past due and still open.">
                <div class="space-y-3">
                    @forelse ($dashboard['overdueTasks'] as $task)
                        <div class="rounded-md border border-line bg-white p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-ink">{{ $task->title }}</p>
                                <x-ui.badge variant="blue">{{ str($task->priority)->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 text-xs text-muted">Due {{ $task->due_at?->format('M j, H:i') }} · {{ $task->campaign?->name ?? 'No campaign' }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-muted">No overdue tasks.</p>
                    @endforelse
                </div>
            </x-dashboard.section>
        </div>

        <div class="mt-6">
            <x-dashboard.section title="Objectives progress" description="Progress toward measurable Marketing OS goals.">
                <div class="grid gap-3 lg:grid-cols-2">
                    @forelse ($dashboard['objectives'] as $objective)
                        @php
                            $target = (float) ($objective->target_value ?? 0);
                            $current = (float) ($objective->current_value ?? 0);
                            $progress = $target > 0 ? min(100, round(($current / $target) * 100)) : 0;
                        @endphp
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-ink">{{ $objective->name }}</p>
                                    <p class="mt-1 text-xs text-muted">{{ str($objective->type)->headline() }} · {{ $objective->campaign?->name ?? 'No campaign' }}</p>
                                </div>
                                <x-ui.badge>{{ $progress }}%</x-ui.badge>
                            </div>
                            <div class="mt-4 h-2 overflow-hidden rounded-full bg-white">
                                <div class="h-full rounded-full bg-ink" style="width: {{ $progress }}%"></div>
                            </div>
                            <p class="mt-2 text-xs text-muted">{{ $objective->current_value ?? '0' }} / {{ $objective->target_value ?? '0' }} {{ $objective->unit }}</p>
                        </div>
                    @empty
                        <x-dashboard.empty-state title="No objectives" message="Active objectives will show progress here." />
                    @endforelse
                </div>
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
            <x-dashboard.section title="Approvals pending" description="Briefings, social posts and other work awaiting a decision.">
                <div class="space-y-3">
                    @forelse ($dashboard['pendingApprovals'] as $approval)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <p class="text-sm font-semibold text-ink">{{ class_basename($approval->subject_type) }} approval</p>
                            <p class="mt-2 text-xs text-muted">Requested {{ $approval->requested_at?->format('M j, H:i') ?? $approval->created_at->format('M j, H:i') }} · {{ $approval->requester?->name ?? 'Unknown requester' }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-muted">No pending approvals.</p>
                    @endforelse
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Latest recommendations" description="Recent recommended actions for this operating scope.">
                <div class="space-y-3">
                    @forelse ($dashboard['latestRecommendations'] as $recommendation)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-semibold text-ink">{{ $recommendation->title }}</p>
                                <x-ui.badge>{{ str($recommendation->status)->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-2 line-clamp-2 text-xs leading-5 text-muted">{{ $recommendation->summary }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-muted">No recommendations yet.</p>
                    @endforelse
                </div>
            </x-dashboard.section>
        </div>

        <div class="mt-6">
            <x-dashboard.section title="Active marketing agents" description="Agents available for marketing planning, content, visibility and social follow-up.">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    @forelse ($dashboard['activeAgents'] as $agent)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <p class="text-sm font-semibold text-ink">{{ $agent->name }}</p>
                            <p class="mt-2 line-clamp-2 text-xs leading-5 text-muted">{{ $agent->description }}</p>
                            <p class="mt-3 text-xs font-semibold uppercase tracking-[0.1em] text-muted">{{ $agent->tasks_count }} scoped tasks</p>
                        </div>
                    @empty
                        <x-dashboard.empty-state title="No active agents" message="Marketing agents will appear when the agent framework is enabled." />
                    @endforelse
                </div>
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
