@extends('layouts.admin', ['title' => $campaign->name])

@section('pageHeader')
    <x-page-header :title="$campaign->name">
        <x-slot:description>{{ $campaign->objective ?: 'No objective recorded.' }}</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <a href="{{ route('admin.campaigns.index') }}" class="pl-btn-secondary">Back to campaigns</a>
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="Status" :value="str_replace('_', ' ', $campaign->status?->value ?? $campaign->status)" />
        <x-metric-card label="Approval" :value="str_replace('_', ' ', $campaign->approval_status?->value ?? $campaign->approval_status)" />
        <x-metric-card label="Tone Profile" :value="$campaign->toneProfile?->name ?? '-'" />
        <x-metric-card label="CTA Preset" :value="$campaign->ctaPreset?->name ?? '-'" />
    </x-metric-section>
@endsection

@section('content')
    <div class="mb-6 rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Content Assets</h2>
        <x-data-table label="Campaign content assets" description="Content assets attached to this campaign with campaign dates, publish state, and live URLs." density="compact" class="mt-4 border-0 shadow-none">
            <x-data-table.header>
                <x-data-table.row>
                    <x-data-table.cell heading>Order</x-data-table.cell>
                    <x-data-table.cell heading>Asset</x-data-table.cell>
                    <x-data-table.cell heading>Content</x-data-table.cell>
                    <x-data-table.cell heading>Status</x-data-table.cell>
                    <x-data-table.cell heading>Campaign Date</x-data-table.cell>
                    <x-data-table.cell heading>Publish State</x-data-table.cell>
                    <x-data-table.cell heading>Live URL</x-data-table.cell>
                </x-data-table.row>
            </x-data-table.header>
            <tbody class="divide-y divide-border">
            @forelse ($campaign->contents as $asset)
                @php
                    $content = $asset->content;
                    $deliveredPublication = $content?->publications
                        ?->first(fn ($publication) => (string) $publication->delivery_status === \App\Models\ContentPublication::STATUS_DELIVERED);
                @endphp
                <x-data-table.row>
                    <x-data-table.cell label="Order" class="text-textSecondary">{{ $asset->sequence_order }}</x-data-table.cell>
                    <x-data-table.cell label="Asset">
                        <div class="font-medium text-textPrimary">{{ str_replace('_', ' ', $asset->asset_type?->value ?? $asset->asset_type) }}</div>
                        <div class="text-xs text-textSecondary">{{ $asset->working_title ?: '-' }}</div>
                    </x-data-table.cell>
                    <x-data-table.cell label="Content" class="text-textSecondary">{{ $asset->content?->title ?? $asset->content_id ?? '-' }}</x-data-table.cell>
                    <x-data-table.cell label="Status" class="text-textSecondary">{{ $asset->status }} / {{ str_replace('_', ' ', $asset->approval_status?->value ?? $asset->approval_status) }}</x-data-table.cell>
                    <x-data-table.cell label="Campaign Date" class="text-textSecondary">{{ $asset->scheduled_for?->format('Y-m-d H:i') ?? '-' }}</x-data-table.cell>
                    <x-data-table.cell label="Publish State" class="text-textSecondary">
                        @if ($content)
                            <div>{{ str_replace('_', ' ', (string) ($content->publish_status ?: $content->status ?: 'draft')) }}</div>
                            <div class="text-xs">{{ $content->scheduled_publish_at ? 'Publish at '.$content->scheduled_publish_at->format('Y-m-d H:i') : 'No publish schedule' }}</div>
                        @else
                            -
                        @endif
                    </x-data-table.cell>
                    <x-data-table.cell label="Live URL" class="text-textSecondary">
                        @php($liveUrl = $deliveredPublication?->remote_url ?: $content?->published_url)
                        @if ($liveUrl)
                            <a href="{{ $liveUrl }}" target="_blank" rel="noopener" class="text-primary hover:underline">Open</a>
                        @else
                            -
                        @endif
                    </x-data-table.cell>
                </x-data-table.row>
            @empty
                <x-data-table.empty colspan="7" title="No content assets attached" />
            @endforelse
            </tbody>
        </x-data-table>
    </div>

    <div class="rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Distribution Plan</h2>
        <x-data-table label="Campaign distribution plan" description="Distribution plans for this campaign by channel, asset type, status, scheduled time, and distribution time." density="compact" class="mt-4 border-0 shadow-none">
            <x-data-table.header>
                <x-data-table.row>
                    <x-data-table.cell heading>Channel</x-data-table.cell>
                    <x-data-table.cell heading>Asset Type</x-data-table.cell>
                    <x-data-table.cell heading>Status</x-data-table.cell>
                    <x-data-table.cell heading>Scheduled</x-data-table.cell>
                    <x-data-table.cell heading>Distributed</x-data-table.cell>
                </x-data-table.row>
            </x-data-table.header>
            <tbody class="divide-y divide-border">
            @forelse ($campaign->distributionPlans as $plan)
                <x-data-table.row>
                    <x-data-table.cell label="Channel" class="text-textPrimary">{{ $plan->distributionChannel?->name ?? $plan->distribution_channel_id }}</x-data-table.cell>
                    <x-data-table.cell label="Asset Type" class="text-textSecondary">{{ str_replace('_', ' ', $plan->asset_type?->value ?? $plan->asset_type ?? '-') }}</x-data-table.cell>
                    <x-data-table.cell label="Status" class="text-textSecondary">{{ str_replace('_', ' ', $plan->status?->value ?? $plan->status) }}</x-data-table.cell>
                    <x-data-table.cell label="Scheduled" class="text-textSecondary">{{ $plan->scheduled_for?->format('Y-m-d H:i') ?? '-' }}</x-data-table.cell>
                    <x-data-table.cell label="Distributed" class="text-textSecondary">{{ $plan->distributed_at?->format('Y-m-d H:i') ?? '-' }}</x-data-table.cell>
                </x-data-table.row>
            @empty
                <x-data-table.empty colspan="5" title="No distribution plans created" />
            @endforelse
            </tbody>
        </x-data-table>
    </div>

    <div class="mt-6 rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Social Timeline</h2>
        <x-data-table label="Campaign social timeline" description="Social publications for this campaign by platform, account, status, scheduled time, and remote status." density="compact" class="mt-4 border-0 shadow-none">
            <x-data-table.header>
                <x-data-table.row>
                    <x-data-table.cell heading>Platform</x-data-table.cell>
                    <x-data-table.cell heading>Account</x-data-table.cell>
                    <x-data-table.cell heading>Status</x-data-table.cell>
                    <x-data-table.cell heading>Scheduled</x-data-table.cell>
                    <x-data-table.cell heading>Remote</x-data-table.cell>
                </x-data-table.row>
            </x-data-table.header>
            <tbody class="divide-y divide-border">
            @forelse ($campaign->socialPublications as $publication)
                <x-data-table.row>
                    <x-data-table.cell label="Platform" class="text-textPrimary">{{ $publication->platform?->value ?? $publication->platform }}</x-data-table.cell>
                    <x-data-table.cell label="Account" class="text-textSecondary">{{ $publication->socialAccount?->display_name ?? '-' }}</x-data-table.cell>
                    <x-data-table.cell label="Status" class="text-textSecondary">{{ str_replace('_', ' ', $publication->status?->value ?? $publication->status) }}</x-data-table.cell>
                    <x-data-table.cell label="Scheduled" class="text-textSecondary">{{ $publication->scheduled_for?->format('Y-m-d H:i') ?? '-' }}</x-data-table.cell>
                    <x-data-table.cell label="Remote" class="text-textSecondary">{{ $publication->remote_url ?: $publication->last_error_code ?: '-' }}</x-data-table.cell>
                </x-data-table.row>
            @empty
                <x-data-table.empty colspan="5" title="No social publications scheduled" />
            @endforelse
            </tbody>
        </x-data-table>
    </div>
@endsection
