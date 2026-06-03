<x-app.layout title="Admin Publishing Troubleshooting" :show-workspace-header="false">
    @include('admin._nav')
    <h1 class="text-2xl font-bold text-ink">Publishing Troubleshooting</h1>
    <p class="mt-2 text-sm text-muted">Inspect content asset, channel, connector installation, payload, response and error context. Retry action wiring is a placeholder for the next operational pass.</p>
    <div class="mt-4">@include('admin._table', ['rows' => $actions, 'columns' => ['contentAsset.title', 'publishingChannel.name', 'publishingChannel.connectorInstallation.name', 'action', 'status', 'request_payload', 'response_payload', 'error_message', 'created_at']])</div>
</x-app.layout>
