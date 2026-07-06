@php
    $normalizedType = \App\Models\ClientSite::normalizeType((string) ($siteType ?? 'wordpress'));
@endphp

@if ($normalizedType === \App\Models\ClientSite::TYPE_LARAVEL)
    <ol class="mt-3 list-decimal space-y-1 pl-5 text-xs text-textSecondary">
        <li>Install connector package: <code>composer require onygo/argusly-laravel-connector</code>.</li>
        <li>Configure <code>ARGUSLY_CONNECTOR_API_URL</code>, <code>ARGUSLY_CONNECTOR_API_KEY</code>, <code>ARGUSLY_CONNECTOR_WORKSPACE_ID</code>, <code>ARGUSLY_CONNECTOR_SITE_NAME</code>, and <code>ARGUSLY_CONNECTOR_SITE_URL</code>.</li>
        <li>Run your normal Laravel scheduler. The connector registers its heartbeat automatically.</li>
        <li>Use Test connection here after the scheduler or first connector API call has completed.</li>
    </ol>
@else
    <ol class="mt-3 list-decimal space-y-1 pl-5 text-xs text-textSecondary">
        <li><a href="{{ route('app.sites.wordpress-plugin.download') }}" class="underline">Download the Argusly WordPress plugin (.zip)</a>.</li>
        <li>Paste this Site Key in plugin settings.</li>
        <li>Click Connect in WordPress.</li>
        <li>Run connection test and verify status becomes connected.</li>
    </ol>
@endif
