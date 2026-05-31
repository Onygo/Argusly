<x-app.settings.layout title="Social profiles" description="Control which accounts and brands may view, prepare, schedule or publish through connected social profiles.">
    @if ($profiles->isEmpty())
        <x-dashboard.empty-state title="No social profiles shared" message="Connected LinkedIn profiles and their sharing rules will appear here after a profile is connected or shared with this account." />
    @else
        <div class="space-y-4">
            @foreach ($profiles as $profile)
                @php
                    $canView = $socialProfiles->canView(auth()->user(), $profile, $account, $brand);
                    $canPrepare = $socialProfiles->canPrepare(auth()->user(), $profile, $account, $brand);
                    $canSchedule = $socialProfiles->canSchedule(auth()->user(), $profile, $account, $brand);
                    $canPublish = $socialProfiles->canPublish(auth()->user(), $profile, $account, $brand);
                    $canManage = $socialProfiles->canManage(auth()->user(), $profile, $account, $brand);
                @endphp

                <x-ui.card class="p-5">
                    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-base font-semibold text-ink">{{ $profile->display_name }}</h2>
                                <x-ui.badge>{{ str($profile->provider)->headline() }}</x-ui.badge>
                                <x-ui.badge>{{ str($profile->type)->headline() }}</x-ui.badge>
                            </div>
                            <p class="mt-1 text-sm text-muted">
                                Owner: {{ $profile->owner?->name ?? 'Unknown' }}{{ $profile->brand ? ' · '.$profile->brand->name : '' }}
                            </p>
                            @if ($profile->profile_url)
                                <a href="{{ $profile->profile_url }}" class="mt-2 inline-flex text-sm font-medium text-blue" target="_blank" rel="noreferrer">View profile</a>
                            @endif
                        </div>
                        <x-ui.badge variant="success">{{ str($profile->status)->headline() }}</x-ui.badge>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-5">
                        <div class="rounded-md border border-line p-3">
                            <p class="text-xs font-semibold text-muted">View</p>
                            <p class="mt-1 text-sm font-medium text-ink">{{ $canView ? 'Allowed' : 'No access' }}</p>
                        </div>
                        <div class="rounded-md border border-line p-3">
                            <p class="text-xs font-semibold text-muted">Prepare</p>
                            <p class="mt-1 text-sm font-medium text-ink">{{ $canPrepare ? 'Allowed' : 'No access' }}</p>
                        </div>
                        <div class="rounded-md border border-line p-3">
                            <p class="text-xs font-semibold text-muted">Schedule</p>
                            <p class="mt-1 text-sm font-medium text-ink">{{ $canSchedule ? 'Allowed' : 'No access' }}</p>
                        </div>
                        <div class="rounded-md border border-line p-3">
                            <p class="text-xs font-semibold text-muted">Publish</p>
                            <p class="mt-1 text-sm font-medium text-ink">{{ $canPublish ? 'Allowed' : 'No access' }}</p>
                        </div>
                        <div class="rounded-md border border-line p-3">
                            <p class="text-xs font-semibold text-muted">Manage</p>
                            <p class="mt-1 text-sm font-medium text-ink">{{ $canManage ? 'Allowed' : 'No access' }}</p>
                        </div>
                    </div>

                    <div class="mt-4 rounded-md border border-dashed border-line p-4 text-sm text-muted">
                        Sharing editor placeholder. Account, brand and user-specific overrides will be managed here.
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</x-app.settings.layout>
