<x-app.layout title="Admin Integrations" :show-workspace-header="false">
    @include('admin._nav')
    <h1 class="text-2xl font-bold text-ink">Integrations</h1>
    <div class="mt-4 grid gap-4 xl:grid-cols-2">
        <section class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Integration Catalog</h2>
            @include('admin._table', ['rows' => $integrations, 'columns' => ['name', 'key', 'auth_type', 'is_active', 'connections_count']])
        </section>
        <section class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Connections</h2>
            @include('admin._table', ['rows' => $connections, 'columns' => ['integration.name', 'name', 'status', 'account.name', 'brand.name', 'owner.name', 'last_used_at', 'revoked_at']])
        </section>
    </div>
</x-app.layout>
