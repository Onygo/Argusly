@extends('layouts.admin', ['title' => 'Editorial Taxonomy'])

@section('pageHeader')
    <x-page-header title="Editorial Taxonomy">
        <x-slot:description>Manage taxonomy sets, tenant assignments, and taxonomy items.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first() }}</div>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="rounded-lg border border-border bg-surface p-4">
            <h2 class="text-sm font-semibold text-textPrimary">Taxonomy sets</h2>
            <div class="mt-3 space-y-2">
                @forelse($sets as $set)
                    <a href="{{ route('admin.editorial-taxonomy.index', ['set' => $set->id]) }}" class="block rounded border px-3 py-2 text-sm {{ (string) ($selectedSet?->id ?? '') === (string) $set->id ? 'border-primary bg-primarySoftBg text-textPrimary' : 'border-border text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary' }}">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-medium">{{ $set->name }}</span>
                            <span class="text-xs">{{ $set->items_count }} items</span>
                        </div>
                        <div class="mt-1 text-xs">
                            @if($set->is_default)
                                <span class="inline-flex rounded bg-sky-100 px-2 py-0.5 text-sky-700">default</span>
                            @endif
                        </div>
                    </a>
                @empty
                    <p class="text-sm text-textSecondary">No taxonomy sets yet.</p>
                @endforelse
            </div>

            <form method="POST" action="{{ route('admin.editorial-taxonomy.sets.store') }}" class="mt-4 space-y-2 rounded border border-border p-3">
                @csrf
                <p class="text-xs font-semibold text-textPrimary">Create new set</p>
                <input type="text" name="name" class="w-full rounded border border-border bg-background px-2 py-2 text-sm" placeholder="Set name" required>
                <input type="text" name="description" class="w-full rounded border border-border bg-background px-2 py-2 text-sm" placeholder="Description (optional)">
                <label class="inline-flex items-center gap-2 text-xs text-textSecondary">
                    <input type="checkbox" name="is_default" value="1"> Mark as default
                </label>
                <button class="rounded border border-border px-3 py-1.5 text-xs">Create set</button>
            </form>
        </section>

        <section class="rounded-lg border border-border bg-surface p-4 lg:col-span-2">
            @if($selectedSet)
                <div class="grid gap-4 xl:grid-cols-2">
                    <div class="rounded border border-border p-3">
                        <h2 class="text-sm font-semibold text-textPrimary">Set details</h2>
                        <form method="POST" action="{{ route('admin.editorial-taxonomy.sets.update', $selectedSet) }}" class="mt-3 space-y-2">
                            @csrf
                            <input type="text" name="name" value="{{ old('name', $selectedSet->name) }}" class="w-full rounded border border-border bg-background px-2 py-2 text-sm" required>
                            <input type="text" name="description" value="{{ old('description', $selectedSet->description) }}" class="w-full rounded border border-border bg-background px-2 py-2 text-sm" placeholder="Description">
                            <label class="inline-flex items-center gap-2 text-xs text-textSecondary">
                                <input type="checkbox" name="is_default" value="1" @checked((bool) $selectedSet->is_default)> Default set
                            </label>
                            <button class="rounded border border-border px-3 py-1.5 text-xs">Save set</button>
                        </form>
                        <form method="POST" action="{{ route('admin.editorial-taxonomy.sets.destroy', $selectedSet) }}" class="mt-2" onsubmit="return confirm('Delete this taxonomy set and all items?');">
                            @csrf
                            @method('DELETE')
                            <button class="rounded border border-rose-300 px-3 py-1.5 text-xs text-rose-700">Delete set</button>
                        </form>
                    </div>

                    <div class="rounded border border-border p-3">
                        <h2 class="text-sm font-semibold text-textPrimary">Assign to tenants</h2>
                        <form method="POST" action="{{ route('admin.editorial-taxonomy.assignments.update', $selectedSet) }}" class="mt-3">
                            @csrf
                            <div class="max-h-52 space-y-1 overflow-auto rounded border border-border bg-background p-2">
                                @foreach($organizations as $organization)
                                    <label class="flex items-center gap-2 rounded px-2 py-1 text-xs hover:bg-surfaceMuted">
                                        <input type="checkbox" name="tenant_ids[]" value="{{ $organization->id }}" @checked($assignedTenantIds->contains($organization->id))>
                                        <span>{{ $organization->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <button class="mt-2 rounded border border-border px-3 py-1.5 text-xs">Save assignments</button>
                        </form>
                    </div>
                </div>

                <div class="mt-4 rounded border border-border p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold text-textPrimary">Taxonomy items</h2>
                        <div class="flex items-center gap-2 text-xs">
                            <a href="{{ route('admin.editorial-taxonomy.index', ['set' => $selectedSet->id]) }}" class="rounded border border-border px-2 py-1 {{ $typeFilter === '' ? 'bg-surfaceMuted text-textPrimary' : 'text-textSecondary' }}">All</a>
                            @foreach($allowedTypes as $allowedType)
                                <a href="{{ route('admin.editorial-taxonomy.index', ['set' => $selectedSet->id, 'type' => $allowedType]) }}" class="rounded border border-border px-2 py-1 {{ $typeFilter === $allowedType ? 'bg-surfaceMuted text-textPrimary' : 'text-textSecondary' }}">{{ ucfirst($allowedType) }}</a>
                            @endforeach
                        </div>
                    </div>

                    <form method="POST" action="{{ route('admin.editorial-taxonomy.items.store', $selectedSet) }}" class="mt-3 grid gap-2 md:grid-cols-5">
                        @csrf
                        <select name="type" class="rounded border border-border bg-background px-2 py-2 text-sm" required>
                            @foreach($allowedTypes as $allowedType)
                                <option value="{{ $allowedType }}">{{ ucfirst($allowedType) }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="name" class="rounded border border-border bg-background px-2 py-2 text-sm md:col-span-2" placeholder="Item name" required>
                        <input type="text" name="slug" class="rounded border border-border bg-background px-2 py-2 text-sm" placeholder="slug (optional)">
                        <select name="parent_id" class="rounded border border-border bg-background px-2 py-2 text-sm">
                            <option value="">No parent</option>
                            @foreach($parentOptions as $parentOption)
                                <option value="{{ $parentOption->id }}">{{ ucfirst((string) $parentOption->type) }} · {{ $parentOption->name }}</option>
                            @endforeach
                        </select>
                        <label class="inline-flex items-center gap-2 text-xs text-textSecondary md:col-span-2">
                            <input type="checkbox" name="is_active" value="1" checked> Active
                        </label>
                        <button class="rounded border border-border px-3 py-2 text-xs md:col-span-3">Add item</button>
                    </form>

                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                            <tr class="text-left text-textSecondary">
                                <th class="pb-2 font-medium">Type</th>
                                <th class="pb-2 font-medium">Name</th>
                                <th class="pb-2 font-medium">Slug</th>
                                <th class="pb-2 font-medium">Parent</th>
                                <th class="pb-2 font-medium">Active</th>
                                <th class="pb-2 font-medium">Actions</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                            @forelse($items as $item)
                                <tr>
                                    <td class="py-2">{{ ucfirst((string) $item->type) }}</td>
                                    <td class="py-2">{{ $item->name }}</td>
                                    <td class="py-2 text-textSecondary">{{ $item->slug }}</td>
                                    <td class="py-2 text-textSecondary">{{ $item->parent?->name ?: '-' }}</td>
                                    <td class="py-2">{{ $item->is_active ? 'yes' : 'no' }}</td>
                                    <td class="py-2">
                                        <details>
                                            <summary class="cursor-pointer text-xs text-textSecondary">Edit</summary>
                                            <form method="POST" action="{{ route('admin.editorial-taxonomy.items.update', ['set' => $selectedSet, 'item' => $item]) }}" class="mt-2 grid gap-2 rounded border border-border bg-background p-2 text-xs">
                                                @csrf
                                                <select name="type" class="rounded border border-border bg-background px-2 py-1">
                                                    @foreach($allowedTypes as $allowedType)
                                                        <option value="{{ $allowedType }}" @selected($item->type === $allowedType)>{{ ucfirst($allowedType) }}</option>
                                                    @endforeach
                                                </select>
                                                <input type="text" name="name" value="{{ $item->name }}" class="rounded border border-border bg-background px-2 py-1" required>
                                                <input type="text" name="slug" value="{{ $item->slug }}" class="rounded border border-border bg-background px-2 py-1" required>
                                                <select name="parent_id" class="rounded border border-border bg-background px-2 py-1">
                                                    <option value="">No parent</option>
                                                    @foreach($parentOptions as $parentOption)
                                                        @continue((string) $parentOption->id === (string) $item->id)
                                                        <option value="{{ $parentOption->id }}" @selected((string) ($item->parent_id ?? '') === (string) $parentOption->id)>{{ ucfirst((string) $parentOption->type) }} · {{ $parentOption->name }}</option>
                                                    @endforeach
                                                </select>
                                                <label class="inline-flex items-center gap-2">
                                                    <input type="checkbox" name="is_active" value="1" @checked($item->is_active)> Active
                                                </label>
                                                <button class="rounded border border-border px-2 py-1">Save</button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.editorial-taxonomy.items.destroy', ['set' => $selectedSet, 'item' => $item]) }}" class="mt-2" onsubmit="return confirm('Delete this taxonomy item?');">
                                                @csrf
                                                @method('DELETE')
                                                <button class="rounded border border-rose-300 px-2 py-1 text-rose-700">Delete</button>
                                            </form>
                                        </details>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-4 text-center text-textSecondary">No items found for this filter.</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
                <p class="text-sm text-textSecondary">Create a taxonomy set to start.</p>
            @endif
        </section>
    </div>
@endsection
