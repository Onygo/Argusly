@extends('layouts.admin', ['title' => 'Briefs'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Briefs</x-slot:title>
        <x-slot:description>All briefs across organizations.</x-slot:description>
    </x-page-header>
@endsection

@section('content')

    <x-data-table label="Admin briefs" description="Briefs across organizations with status, creation time, and delete actions.">
        <x-data-table.header>
            <x-data-table.row>
                <x-data-table.cell heading>Title</x-data-table.cell>
                <x-data-table.cell heading>Organization</x-data-table.cell>
                <x-data-table.cell heading>Status</x-data-table.cell>
                <x-data-table.cell heading>Created</x-data-table.cell>
                <x-data-table.cell heading>Actions</x-data-table.cell>
            </x-data-table.row>
        </x-data-table.header>
        <tbody>
            @forelse ($briefs as $brief)
                <x-data-table.row>
                    <x-data-table.cell label="Title">{{ $brief->title }}</x-data-table.cell>
                    <x-data-table.cell label="Organization">{{ $brief->clientSite?->workspace?->organization?->name ?? 'n a' }}</x-data-table.cell>
                    <x-data-table.cell label="Status">
                        <x-data-table.badge :label="$brief->status" />
                    </x-data-table.cell>
                    <x-data-table.cell label="Created">{{ $brief->created_at?->format('Y-m-d H:i') }}</x-data-table.cell>
                    <x-data-table.cell label="Actions">
                        <x-data-table.actions align="start">
                            <form method="POST" action="{{ route('admin.briefs.destroy', $brief) }}">
                                @csrf
                                @method('DELETE')
                                <button class="inline-flex items-center justify-center rounded-md border border-rose-500/40 bg-rose-500/10 px-3 py-1 text-xs font-medium text-rose-700">
                                    Delete
                                </button>
                            </form>
                        </x-data-table.actions>
                    </x-data-table.cell>
                </x-data-table.row>
            @empty
                <x-data-table.empty colspan="5" title="No briefs found" />
            @endforelse
        </tbody>
        <x-slot:pagination>{{ $briefs->links() }}</x-slot:pagination>
    </x-data-table>
@endsection
