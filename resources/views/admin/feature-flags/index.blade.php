@extends('layouts.admin', ['title' => 'Feature Flags'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Feature Flags</h1>
        <p class="mt-1 text-textSecondary">Database flags override config defaults when present.</p>
    </div>

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

    <div class="rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Effective flags</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead>
                <tr class="border-b border-border text-xs uppercase tracking-wide text-textFaint">
                    <th class="px-3 py-2">Key</th>
                    <th class="px-3 py-2">Description</th>
                    <th class="px-3 py-2">Source</th>
                    <th class="px-3 py-2">State</th>
                    <th class="px-3 py-2"></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($flags as $flag)
                    <tr class="border-b border-border/60">
                        <td class="px-3 py-2 font-mono text-xs text-textPrimary">{{ $flag['key'] }}</td>
                        <td class="px-3 py-2 text-textSecondary">{{ $flag['description'] ?: '—' }}</td>
                        <td class="px-3 py-2 text-xs uppercase tracking-wide text-textFaint">{{ $flag['source'] }}</td>
                        <td class="px-3 py-2">
                            <span class="rounded border px-2 py-0.5 text-xs {{ $flag['enabled'] ? 'border-success/30 text-success' : 'border-border text-textSecondary' }}">
                                {{ $flag['enabled'] ? 'Enabled' : 'Disabled' }}
                            </span>
                        </td>
                        <td class="px-3 py-2">
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
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-3 py-6 text-sm text-textSecondary">No feature flags configured yet.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
