@extends('layouts.admin', ['title' => 'Company Intelligence'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-textPrimary">Company Intelligence</h1>
        <p class="mt-1 text-sm text-textSecondary">AI-ready company intelligence profiles across workspaces and brands.</p>
    </div>

    <div class="overflow-hidden rounded-lg border border-border bg-surface">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-border bg-surfaceMuted text-xs uppercase text-textSecondary">
                <tr>
                    <th class="px-4 py-3">Company</th>
                    <th class="px-4 py-3">Organization</th>
                    <th class="px-4 py-3">Workspace</th>
                    <th class="px-4 py-3">Brand</th>
                    <th class="px-4 py-3">Completeness</th>
                    <th class="px-4 py-3">Embedding</th>
                    <th class="px-4 py-3">Updated</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($profiles as $profile)
                    <tr>
                        <td class="px-4 py-3 font-medium text-textPrimary">{{ $profile->company_name }}</td>
                        <td class="px-4 py-3 text-textSecondary">{{ $profile->organization?->name }}</td>
                        <td class="px-4 py-3 text-textSecondary">{{ $profile->workspace?->display_name }}</td>
                        <td class="px-4 py-3 text-textSecondary">{{ $profile->brand_key }} @if($profile->is_default) · default @endif</td>
                        <td class="px-4 py-3 text-textPrimary">{{ $profile->completeness_score }}%</td>
                        <td class="px-4 py-3 text-textSecondary">{{ str($profile->embedding_status)->headline() }}</td>
                        <td class="px-4 py-3 text-textSecondary">{{ $profile->updated_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-textSecondary">No company intelligence profiles yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $profiles->links() }}
    </div>
@endsection
