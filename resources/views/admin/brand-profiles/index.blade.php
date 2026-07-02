@extends('layouts.admin', ['title' => 'Default Brand Profiles'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Default Brand Profiles</x-slot:title>
        <x-slot:description>Global defaults for tone and style rules.</x-slot:description>
    </x-page-header>
@endsection

@section('content')

    <div class="mb-6 rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Create profile</h2>
        <form method="POST" action="{{ route('admin.brand-profiles.store') }}" class="mt-3 grid gap-3 md:grid-cols-2">
            @csrf
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Name</label>
                <input name="name" required maxlength="120" class="pl-input w-full" />
            </div>
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Tone</label>
                <input name="tone" maxlength="255" class="pl-input w-full" />
            </div>
            <div class="md:col-span-2">
                <label class="mb-1 block text-xs text-textSecondary">Style Rules (JSON)</label>
                <textarea name="style_rules" rows="4" class="pl-textarea w-full" placeholder='{"voice":"expert"}'></textarea>
            </div>
            <div class="md:col-span-2">
                <button class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Create profile</button>
            </div>
        </form>
    </div>

    <div class="space-y-3">
        @forelse ($profiles as $profile)
            <div class="rounded-lg border border-border bg-surface p-4">
                <form method="POST" action="{{ route('admin.brand-profiles.update', $profile) }}" class="grid gap-3 md:grid-cols-2">
                    @csrf
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Name</label>
                        <input name="name" value="{{ $profile->name }}" required maxlength="120" class="pl-input w-full" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Tone</label>
                        <input name="tone" value="{{ $profile->tone }}" maxlength="255" class="pl-input w-full" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs text-textSecondary">Style Rules (JSON)</label>
                        <textarea name="style_rules" rows="3" class="pl-textarea w-full">{{ $profile->style_rules ? json_encode($profile->style_rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '' }}</textarea>
                    </div>
                    <div class="md:col-span-2">
                        <button class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Save</button>
                    </div>
                </form>
                <form method="POST" action="{{ route('admin.brand-profiles.destroy', $profile) }}" class="mt-2">
                    @csrf
                    @method('DELETE')
                    <button class="rounded border border-danger/30 px-3 py-2 text-sm text-danger hover:bg-danger/5">Delete</button>
                </form>
            </div>
        @empty
            <div class="rounded-lg border border-border bg-surface p-6 text-sm text-textSecondary">No default brand profiles yet.</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $profiles->links() }}</div>
@endsection
