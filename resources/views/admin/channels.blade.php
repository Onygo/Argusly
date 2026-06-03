<x-app.layout title="Admin Publishing Channels" :show-workspace-header="false">
    @include('admin._nav')
    <h1 class="text-2xl font-bold text-ink">Publishing Channels</h1>
    <div class="mt-4">@include('admin._table', ['rows' => $channels, 'columns' => ['name', 'provider', 'status', 'account.name', 'brand.name', 'connectorInstallation.name', 'last_connected_at']])</div>
</x-app.layout>
