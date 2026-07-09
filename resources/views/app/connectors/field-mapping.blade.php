@extends('layouts.app', ['title' => $account->account_name.' field mapping'])

@section('pageHeader')
    <x-page-header :title="$account->account_name.' field mapping'" />
@endsection

@section('pageDescription')
    <x-page-description>Prepared source fields for future normalized CRM and marketing tables. Phase 28 keeps synced data raw-only.</x-page-description>
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="Objects" :value="$account->fieldMappingPreparations->count()" />
        <x-metric-card label="Datasets" :value="$account->datasets->count()" />
        <x-metric-card label="Provider" :value="$account->provider?->name ?? \Illuminate\Support\Str::headline($account->provider_key)" />
    </x-metric-section>
@endsection

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('app.connectors.show', $account) }}" class="pl-btn-secondary">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>
                <span>Back</span>
            </a>
            <form method="POST" action="{{ route('app.connectors.field-mapping.prepare', $account) }}">
                @csrf
                <button type="submit" class="pl-btn-primary">
                    <i data-lucide="list-checks" class="h-4 w-4"></i>
                    <span>Refresh Prep</span>
                </button>
            </form>
        </div>

        <div class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-base font-semibold text-textPrimary">Prepared object fields</h2>
                <p class="mt-1 text-sm text-textSecondary">Mappings are staged for review only; no normalized tables are written in this phase.</p>
            </div>

            @if ($account->fieldMappingPreparations->isEmpty())
                <div class="p-8 text-center">
                    <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-md border border-border bg-background">
                        <i data-lucide="list-checks" class="h-5 w-5 text-textSecondary"></i>
                    </div>
                    <p class="mt-3 text-sm font-medium text-textPrimary">No field mapping prep yet</p>
                    <p class="mt-1 text-sm text-textSecondary">Run discovery or refresh prep after CRM schemas are available.</p>
                </div>
            @else
                <div class="divide-y divide-border">
                    @foreach ($account->fieldMappingPreparations as $prep)
                        <div class="px-5 py-4">
                            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                <div>
                                    <p class="font-medium text-textPrimary">{{ \Illuminate\Support\Str::headline($prep->object_key) }}</p>
                                    <p class="mt-1 text-xs text-textSecondary">{{ collect((array) $prep->source_fields_json)->count() }} source fields · Prepared {{ $prep->prepared_at?->diffForHumans() ?? 'recently' }}</p>
                                </div>
                                @include('app.connectors.partials.status-badge', ['status' => $prep->status])
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach (collect((array) $prep->source_fields_json)->take(12) as $field)
                                    <span class="rounded-full border border-border bg-background px-2 py-0.5 text-xs text-textSecondary">{{ $field['label'] ?? $field['name'] ?? 'Field' }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
