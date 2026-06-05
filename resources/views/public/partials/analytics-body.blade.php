{{-- Analytics body tracking for public pages (GTM noscript) --}}
@inject('analyticsSettings', 'App\Services\Analytics\AnalyticsSettingsService')

@if ($analyticsSettings->shouldRenderTracking())
    @inject('analyticsRenderer', 'App\Services\Analytics\AnalyticsRenderer')
    {!! $analyticsRenderer->renderBodyTracking() !!}
@endif
