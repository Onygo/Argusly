@extends('layouts.admin', ['title' => 'Content Policies'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Content Policies</h1>
        <p class="mt-1 text-textSecondary">Reusable policy rules for product-level governance.</p>
    </div>

    <div class="mb-6 rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Create policy</h2>
        <form method="POST" action="{{ route('admin.content-policies.store') }}" class="mt-3 grid gap-3 md:grid-cols-2">
            @csrf
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Name</label>
                <input name="name" required maxlength="120" class="pl-input w-full" />
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Description</label>
                <input name="description" maxlength="1000" class="pl-input w-full" />
            </div>
            <div class="md:col-span-2">
                <label class="mb-1 block text-xs text-textSecondary">Rules (JSON)</label>
                <textarea name="rules" rows="4" class="pl-textarea w-full" placeholder='{"max_links":5}'></textarea>
            </div>
            <div class="md:col-span-2">
                <button class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Create policy</button>
            </div>
        </form>
    </div>

    <div class="space-y-3">
        @forelse ($policies as $policy)
            <div class="rounded-lg border border-border bg-surface p-4">
                <form method="POST" action="{{ route('admin.content-policies.update', $policy) }}" class="grid gap-3 md:grid-cols-2">
                    @csrf
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Name</label>
                        <input name="name" value="{{ $policy->name }}" required maxlength="120" class="pl-input w-full" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Description</label>
                        <input name="description" value="{{ $policy->description }}" maxlength="1000" class="pl-input w-full" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs text-textSecondary">Rules (JSON)</label>
                        <textarea name="rules" rows="3" class="pl-textarea w-full">{{ $policy->rules ? json_encode($policy->rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '' }}</textarea>
                    </div>
                    <div class="md:col-span-2">
                        <button class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Save</button>
                    </div>
                </form>
                <form method="POST" action="{{ route('admin.content-policies.destroy', $policy) }}" class="mt-2">
                    @csrf
                    @method('DELETE')
                    <button class="rounded border border-danger/30 px-3 py-2 text-sm text-danger hover:bg-danger/5">Delete</button>
                </form>
            </div>
        @empty
            <div class="rounded-lg border border-border bg-surface p-6 text-sm text-textSecondary">No content policies yet.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $policies->links() }}</div>
@endsection
