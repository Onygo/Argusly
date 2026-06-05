@extends('layouts.admin', ['title' => 'Briefs'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Briefs</h1>
        <p class="text-textSecondary mt-1">All briefs across organizations.</p>
    </div>

    <div class="rounded-lg border border-border bg-surface p-4">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-textSecondary">
                    <th class="pb-2 font-medium">Title</th>
                    <th class="pb-2 font-medium">Organization</th>
                    <th class="pb-2 font-medium">Status</th>
                    <th class="pb-2 font-medium">Created</th>
                    <th class="pb-2 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($briefs as $brief)
                    <tr>
                        <td class="py-3">{{ $brief->title }}</td>
                        <td class="py-3">{{ $brief->clientSite?->workspace?->organization?->name ?? 'n a' }}</td>
                        <td class="py-3">{{ $brief->status }}</td>
                        <td class="py-3">{{ $brief->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="py-3">
                            <form method="POST" action="{{ route('admin.briefs.destroy', $brief) }}">
                                @csrf
                                @method('DELETE')
                                <button class="inline-flex items-center justify-center rounded-md border border-rose-500/40 bg-rose-500/10 px-3 py-1 text-xs font-medium text-rose-700">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td class="py-6 text-center text-textSecondary" colspan="5">No briefs found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $briefs->links() }}</div>
@endsection
