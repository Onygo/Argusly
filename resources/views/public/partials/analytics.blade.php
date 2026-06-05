{{-- Analytics tracking for public pages --}}
@inject('analyticsSettings', 'App\Services\Analytics\AnalyticsSettingsService')

@if ($analyticsSettings->shouldRenderTracking())
    @inject('analyticsRenderer', 'App\Services\Analytics\AnalyticsRenderer')
    {!! $analyticsRenderer->renderHeadTracking() !!}
@endif
