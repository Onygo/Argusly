@php
    $normalizedType = \App\Models\ClientSite::normalizeType((string) ($siteType ?? 'wordpress'));
@endphp

@if ($normalizedType === \App\Models\ClientSite::TYPE_LARAVEL)
    <ol class="mt-3 list-decimal space-y-1 pl-5 text-xs text-textSecondary">
        <li>Install connector package: <code>composer require publishlayer/laravel-connector</code>.</li>
        <li>Configure <code>PL_CONNECTOR_BASE_URL</code>, <code>PL_CONNECTOR_API_KEY</code> (this key), and <code>PL_CONNECTOR_WORKSPACE_ID</code>.</li>
        <li>Legacy aliases still supported: <code>PUBLISHLAYER_BASE_URL</code>, <code>PUBLISHLAYER_API_KEY</code>, <code>PUBLISHLAYER_WORKSPACE_ID</code>.</li>
        <li>Use the connector client/facade to create briefs and drafts from your Laravel app.</li>
        <li>Call "Check Laravel connector activity" here after first API calls.</li>
    </ol>
@else
    <ol class="mt-3 list-decimal space-y-1 pl-5 text-xs text-textSecondary">
        <li><a href="{{ route('app.sites.wordpress-plugin.download') }}" class="underline">Download the PublishLayer WordPress plugin (.zip)</a>.</li>
        <li>Paste this Site Key in plugin settings.</li>
        <li>Click Connect in WordPress.</li>
        <li>Run connection test and verify status becomes connected.</li>
    </ol>
@endif
