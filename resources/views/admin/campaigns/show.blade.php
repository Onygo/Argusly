@extends('layouts.admin', ['title' => $campaign->name])

@section('content')
    <div class="mb-6">
        <a href="{{ route('admin.campaigns.index') }}" class="text-sm text-primary hover:underline">Back to campaigns</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-textPrimary">{{ $campaign->name }}</h1>
        <p class="mt-1 text-sm text-textSecondary">{{ $campaign->objective ?: 'No objective recorded.' }}</p>
    </div>

    <div class="mb-6 grid gap-4 md:grid-cols-4">
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">Status</p>
            <p class="mt-2 font-semibold text-textPrimary">{{ str_replace('_', ' ', $campaign->status?->value ?? $campaign->status) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">Approval</p>
            <p class="mt-2 font-semibold text-textPrimary">{{ str_replace('_', ' ', $campaign->approval_status?->value ?? $campaign->approval_status) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">Tone Profile</p>
            <p class="mt-2 font-semibold text-textPrimary">{{ $campaign->toneProfile?->name ?? '-' }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">CTA Preset</p>
            <p class="mt-2 font-semibold text-textPrimary">{{ $campaign->ctaPreset?->name ?? '-' }}</p>
        </div>
    </div>

    <div class="mb-6 rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Content Assets</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-textSecondary">
                <tr>
                    <th class="px-3 py-2">Order</th>
                    <th class="px-3 py-2">Asset</th>
                    <th class="px-3 py-2">Content</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Campaign Date</th>
                    <th class="px-3 py-2">Publish State</th>
                    <th class="px-3 py-2">Live URL</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-border">
                @forelse ($campaign->contents as $asset)
                    @php
                        $content = $asset->content;
                        $deliveredPublication = $content?->publications
                            ?->first(fn ($publication) => (string) $publication->delivery_status === \App\Models\ContentPublication::STATUS_DELIVERED);
                    @endphp
                    <tr>
                        <td class="px-3 py-2 text-textSecondary">{{ $asset->sequence_order }}</td>
                        <td class="px-3 py-2">
                            <div class="font-medium text-textPrimary">{{ str_replace('_', ' ', $asset->asset_type?->value ?? $asset->asset_type) }}</div>
                            <div class="text-xs text-textSecondary">{{ $asset->working_title ?: '-' }}</div>
                        </td>
                        <td class="px-3 py-2 text-textSecondary">{{ $asset->content?->title ?? $asset->content_id ?? '-' }}</td>
                        <td class="px-3 py-2 text-textSecondary">{{ $asset->status }} / {{ str_replace('_', ' ', $asset->approval_status?->value ?? $asset->approval_status) }}</td>
                        <td class="px-3 py-2 text-textSecondary">{{ $asset->scheduled_for?->format('Y-m-d H:i') ?? '-' }}</td>
                        <td class="px-3 py-2 text-textSecondary">
                            @if ($content)
                                <div>{{ str_replace('_', ' ', (string) ($content->publish_status ?: $content->status ?: 'draft')) }}</div>
                                <div class="text-xs">{{ $content->scheduled_publish_at ? 'Publish at '.$content->scheduled_publish_at->format('Y-m-d H:i') : 'No publish schedule' }}</div>
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-3 py-2 text-textSecondary">
                            @php($liveUrl = $deliveredPublication?->remote_url ?: $content?->published_url)
                            @if ($liveUrl)
                                <a href="{{ $liveUrl }}" target="_blank" rel="noopener" class="text-primary hover:underline">Open</a>
                            @else
                                -
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-3 py-6 text-center text-textSecondary">No content assets attached.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Distribution Plan</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-textSecondary">
                <tr>
                    <th class="px-3 py-2">Channel</th>
                    <th class="px-3 py-2">Asset Type</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Scheduled</th>
                    <th class="px-3 py-2">Distributed</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-border">
                @forelse ($campaign->distributionPlans as $plan)
                    <tr>
                        <td class="px-3 py-2 text-textPrimary">{{ $plan->distributionChannel?->name ?? $plan->distribution_channel_id }}</td>
                        <td class="px-3 py-2 text-textSecondary">{{ str_replace('_', ' ', $plan->asset_type?->value ?? $plan->asset_type ?? '-') }}</td>
                        <td class="px-3 py-2 text-textSecondary">{{ str_replace('_', ' ', $plan->status?->value ?? $plan->status) }}</td>
                        <td class="px-3 py-2 text-textSecondary">{{ $plan->scheduled_for?->format('Y-m-d H:i') ?? '-' }}</td>
                        <td class="px-3 py-2 text-textSecondary">{{ $plan->distributed_at?->format('Y-m-d H:i') ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-3 py-6 text-center text-textSecondary">No distribution plans created.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6 rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Social Timeline</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-textSecondary">
                <tr>
                    <th class="px-3 py-2">Platform</th>
                    <th class="px-3 py-2">Account</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Scheduled</th>
                    <th class="px-3 py-2">Remote</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-border">
                @forelse ($campaign->socialPublications as $publication)
                    <tr>
                        <td class="px-3 py-2 text-textPrimary">{{ $publication->platform?->value ?? $publication->platform }}</td>
                        <td class="px-3 py-2 text-textSecondary">{{ $publication->socialAccount?->display_name ?? '-' }}</td>
                        <td class="px-3 py-2 text-textSecondary">{{ str_replace('_', ' ', $publication->status?->value ?? $publication->status) }}</td>
                        <td class="px-3 py-2 text-textSecondary">{{ $publication->scheduled_for?->format('Y-m-d H:i') ?? '-' }}</td>
                        <td class="px-3 py-2 text-textSecondary">{{ $publication->remote_url ?: $publication->last_error_code ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-3 py-6 text-center text-textSecondary">No social publications scheduled.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
