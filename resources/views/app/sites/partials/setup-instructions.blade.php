@php
    $normalizedType = \App\Models\ClientSite::normalizeType((string) ($siteType ?? 'wordpress'));
@endphp

@if ($normalizedType === \App\Models\ClientSite::TYPE_LARAVEL)
    <ol class="mt-3 list-decimal space-y-1 pl-5 text-xs text-textSecondary">
        <li>Install connector package: <code>composer require onygo/argusly-laravel-connector</code>.</li>
        <li>Configure <code>ARGUSLY_CONNECTOR_API_URL</code>, <code>ARGUSLY_CONNECTOR_TOKEN</code> (this token), and <code>ARGUSLY_CONNECTOR_SITE_ID</code>.</li>
        <li>Use the connector client/facade to create briefs and drafts from your Laravel app.</li>
        <li>Call "Check Laravel connector activity" here after first API calls.</li>
    </ol>
@else
    <ol class="mt-3 list-decimal space-y-1 pl-5 text-xs text-textSecondary">
        <li><a href="{{ route('app.sites.wordpress-plugin.download') }}" class="underline">Download the Argusly WordPress plugin (.zip)</a>.</li>
        <li>Paste this Site Key in plugin settings.</li>
        <li>Click Connect in WordPress.</li>
        <li>Run connection test and verify status becomes connected.</li>
    </ol>
@endif
