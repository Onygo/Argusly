<x-app.layout title="Admin Connectors" :show-workspace-header="false">
    @include('admin._nav')
    <h1 class="text-2xl font-bold text-ink">Connectors</h1>
    <div class="mt-4 space-y-4">
        <section class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Connector Manifests</h2>
            @include('admin._table', ['rows' => $manifests, 'columns' => ['name', 'key', 'type', 'status', 'installations_count', 'capabilities_count']])
        </section>
        <section class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Installations</h2>
            @include('admin._table', ['rows' => $installations, 'columns' => ['name', 'status', 'account.name', 'brand.name', 'manifest.name', 'version.version', 'last_health_checked_at', 'last_health_check']])
        </section>
        <section class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Tokens</h2>
            <div class="overflow-hidden rounded-md border border-line">
                <table class="min-w-full divide-y divide-line text-left">
                    <thead class="bg-panel"><tr><th class="px-4 py-3 text-xs font-semibold uppercase text-muted">Name</th><th class="px-4 py-3 text-xs font-semibold uppercase text-muted">Tenant</th><th class="px-4 py-3 text-xs font-semibold uppercase text-muted">State</th><th class="px-4 py-3 text-xs font-semibold uppercase text-muted">Action</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($tokens as $token)
                            <tr>
                                <td class="px-4 py-3 text-sm font-semibold text-ink">{{ $token->name }}</td>
                                <td class="px-4 py-3 text-sm text-muted">{{ $token->account?->name }} / {{ $token->brand?->name ?? 'Account' }}</td>
                                <td class="px-4 py-3">@include('admin._status', ['value' => $token->revoked_at ? 'revoked' : 'active'])</td>
                                <td class="px-4 py-3">
                                    <form method="POST" action="{{ route('admin.connectors.tokens.revoke', $token) }}">
                                        @csrf
                                        <button class="rounded-md border border-line px-3 py-1.5 text-sm font-semibold text-ink">Revoke</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-center text-sm text-muted">No connector tokens found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app.layout>
