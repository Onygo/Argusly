@extends('layouts.admin', ['title' => 'Feature Flags'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Feature Flags</x-slot:title>
        <x-slot:description>Database flags override config defaults when present.</x-slot:description>
    </x-page-header>
@endsection

@section('content')

    <div class="mb-6 rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Create flag override</h2>
        <form method="POST" action="{{ route('admin.feature-flags.store') }}" class="mt-3 grid gap-3 md:grid-cols-3">
            @csrf
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Key</label>
                <input name="key" required maxlength="120" class="pl-input w-full" placeholder="network_linking" />
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Description</label>
                <input name="description" maxlength="255" class="pl-input w-full" />
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Enabled</label>
                <select name="enabled" class="pl-select bg-surface w-full">
                    <option value="0">Disabled</option>
                    <option value="1">Enabled</option>
                </select>
            </div>
            <div class="md:col-span-3">
                <button class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Create override</button>
            </div>
        </form>
    </div>

    <x-data-table label="Effective flags" description="Effective feature flags with source, state, and database override actions." density="compact">
            <x-slot:toolbar>
                <x-data-table.toolbar title="Effective flags" />
            </x-slot:toolbar>

            <x-data-table.header>
                <x-data-table.row>
                    <x-data-table.cell heading>Key</x-data-table.cell>
                    <x-data-table.cell heading>Description</x-data-table.cell>
                    <x-data-table.cell heading>Source</x-data-table.cell>
                    <x-data-table.cell heading>State</x-data-table.cell>
                    <x-data-table.cell heading align="right">Actions</x-data-table.cell>
                </x-data-table.row>
            </x-data-table.header>
            <tbody>
                @forelse ($flags as $flag)
                    <x-data-table.row>
                        <x-data-table.cell label="Key" class="font-mono text-xs text-textPrimary">{{ $flag['key'] }}</x-data-table.cell>
                        <x-data-table.cell label="Description" class="text-textSecondary">{{ $flag['description'] ?: '—' }}</x-data-table.cell>
                        <x-data-table.cell label="Source" class="text-xs uppercase tracking-wide text-textFaint">{{ $flag['source'] }}</x-data-table.cell>
                        <x-data-table.cell label="State">
                            <x-data-table.badge :tone="$flag['enabled'] ? 'success' : 'neutral'" :label="$flag['enabled'] ? 'Enabled' : 'Disabled'" />
                        </x-data-table.cell>
                        <x-data-table.cell label="Actions">
                            <x-data-table.actions>
                            @if (($flag['source'] ?? '') === 'database' && isset($flag['id']))
                                <form method="POST" action="{{ route('admin.feature-flags.update', $flag['id']) }}" class="flex items-center gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="enabled" value="{{ $flag['enabled'] ? 0 : 1 }}">
                                    <input type="hidden" name="description" value="{{ $flag['description'] }}">
                                    <button class="rounded border border-border px-2 py-1 text-xs text-textPrimary hover:bg-surfaceSubtle">
                                        Toggle
                                    </button>
                                </form>
                            @else
                                <span class="text-xs text-textFaint">Config only</span>
                            @endif
                            </x-data-table.actions>
                        </x-data-table.cell>
                    </x-data-table.row>
                @empty
                    <x-data-table.empty colspan="5" title="No feature flags configured yet" />
                @endforelse
            </tbody>
    </x-data-table>
@endsection
