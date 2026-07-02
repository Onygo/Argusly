@extends('layouts.admin', ['title' => 'Product Updates'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Product updates</x-slot:title>
        <x-slot:description>Manage internal changelog entries and release notes.</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
        <a href="{{ route('admin.product-updates.create') }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">
            Create update
        </a>
@endsection

@section('content')

    @if (session('status'))
        <div class="mb-4 rounded border border-border bg-surface px-3 py-2 text-sm text-textPrimary">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Recent updates</h2>

        <div class="mt-3 space-y-3">
            @forelse ($updates as $update)
                <article class="rounded border border-border p-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="font-semibold text-textPrimary">{{ $update->title }}</h3>
                                @if ($update->version)
                                    <span class="rounded border border-border px-2 py-0.5 text-[11px] text-textSecondary">{{ $update->version }}</span>
                                @endif
                                <span class="rounded border px-2 py-0.5 text-[11px] {{ $update->is_public ? 'border-emerald-500/30 text-emerald-700' : 'border-border text-textSecondary' }}">
                                    {{ $update->is_public ? 'public' : 'private' }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-textSecondary">{{ $update->summary }}</p>
                            <p class="mt-1 text-xs text-textFaint">
                                Published at {{ optional($update->published_at)->format('Y-m-d H:i') }}
                            </p>
                            @if (!empty($update->tags))
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    @foreach ((array) $update->tags as $tag)
                                        <span class="rounded border border-border px-2 py-0.5 text-[11px] text-textSecondary">{{ $tag }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('admin.product-updates.edit', $update) }}" class="rounded border border-border px-3 py-1.5 text-xs text-textPrimary hover:bg-surfaceSubtle">Edit</a>
                            <form method="POST" action="{{ route('admin.product-updates.destroy', $update) }}" onsubmit="return confirm('Delete this product update?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded border border-danger/30 px-3 py-1.5 text-xs text-danger hover:bg-danger/5">Delete</button>
                            </form>
                        </div>
                    </div>
                </article>
            @empty
                <p class="text-sm text-textSecondary">No product updates yet.</p>
            @endforelse
        </div>
    </div>

    <div class="mt-4">{{ $updates->links() }}</div>
@endsection
