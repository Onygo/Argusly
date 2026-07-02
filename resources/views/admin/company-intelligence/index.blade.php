@extends('layouts.admin', ['title' => 'Company Intelligence'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Company Intelligence</x-slot:title>
        <x-slot:description>AI-ready company intelligence profiles across workspaces and brands.</x-slot:description>
    </x-page-header>
@endsection

@section('content')

    <x-data-table label="Company intelligence profiles" description="AI-ready company intelligence profiles across workspaces and brands.">
        <x-data-table.header>
            <x-data-table.row>
                <x-data-table.cell heading>Company</x-data-table.cell>
                <x-data-table.cell heading>Organization</x-data-table.cell>
                <x-data-table.cell heading>Workspace</x-data-table.cell>
                <x-data-table.cell heading>Brand</x-data-table.cell>
                <x-data-table.cell heading>Completeness</x-data-table.cell>
                <x-data-table.cell heading>Embedding</x-data-table.cell>
                <x-data-table.cell heading>Updated</x-data-table.cell>
            </x-data-table.row>
        </x-data-table.header>
        <tbody>
            @forelse ($profiles as $profile)
                <x-data-table.row>
                    <x-data-table.cell label="Company" class="font-medium text-textPrimary">{{ $profile->company_name }}</x-data-table.cell>
                    <x-data-table.cell label="Organization" class="text-textSecondary">{{ $profile->organization?->name }}</x-data-table.cell>
                    <x-data-table.cell label="Workspace" class="text-textSecondary">{{ $profile->workspace?->display_name }}</x-data-table.cell>
                    <x-data-table.cell label="Brand" class="text-textSecondary">{{ $profile->brand_key }} @if($profile->is_default) · default @endif</x-data-table.cell>
                    <x-data-table.cell label="Completeness" class="text-textPrimary">{{ $profile->completeness_score }}%</x-data-table.cell>
                    <x-data-table.cell label="Embedding" class="text-textSecondary">
                        <x-data-table.badge :tone="$profile->embedding_status === 'ready' ? 'success' : 'neutral'" :label="str($profile->embedding_status)->headline()" />
                    </x-data-table.cell>
                    <x-data-table.cell label="Updated" class="text-textSecondary">{{ $profile->updated_at?->diffForHumans() }}</x-data-table.cell>
                </x-data-table.row>
            @empty
                <x-data-table.empty colspan="7" title="No company intelligence profiles yet" />
            @endforelse
        </tbody>
        <x-slot:pagination>{{ $profiles->links() }}</x-slot:pagination>
    </x-data-table>
@endsection
