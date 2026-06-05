<?php

namespace App\Services\Analytics;

use Illuminate\Support\HtmlString;

class AnalyticsRenderer
{
    public function __construct(
        private readonly AnalyticsSettingsService $settings
    ) {}

    public function renderHeadTracking(): HtmlString
    {
        if (! $this->settings->shouldRenderTracking()) {
            return new HtmlString('');
        }

        $provider = $this->settings->getProvider();

        $output = match ($provider) {
            AnalyticsSettingsService::PROVIDER_GOOGLE_ANALYTICS => $this->renderGoogleAnalytics(),
            AnalyticsSettingsService::PROVIDER_GOOGLE_TAG_MANAGER => $this->renderGoogleTagManagerHead(),
            AnalyticsSettingsService::PROVIDER_CUSTOM_SCRIPT => $this->renderCustomScript(),
            default => '',
        };

        return new HtmlString($output);
    }

    public function renderBodyTracking(): HtmlString
    {
        if (! $this->settings->shouldRenderTracking()) {
            return new HtmlString('');
        }

        $provider = $this->settings->getProvider();

        if ($provider !== AnalyticsSettingsService::PROVIDER_GOOGLE_TAG_MANAGER) {
            return new HtmlString('');
        }

        return new HtmlString($this->renderGoogleTagManagerBody());
    }

    private function renderGoogleAnalytics(): string
    {
        $measurementId = e($this->settings->getMeasurementId());

        if ($measurementId === '' || $measurementId === null) {
            return '';
        }

        return <<<HTML
<!-- Google Analytics (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id={$measurementId}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '{$measurementId}');
</script>
HTML;
    }

    private function renderGoogleTagManagerHead(): string
    {
        $containerId = e($this->settings->getContainerId());

        if ($containerId === '' || $containerId === null) {
            return '';
        }

        return <<<HTML
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','{$containerId}');</script>
<!-- End Google Tag Manager -->
HTML;
    }

    private function renderGoogleTagManagerBody(): string
    {
        $containerId = e($this->settings->getContainerId());

        if ($containerId === '' || $containerId === null) {
            return '';
        }

        return <<<HTML
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id={$containerId}"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
HTML;
    }

    private function renderCustomScript(): string
    {
        $script = $this->settings->getCustomScript();

        if ($script === null || trim($script) === '') {
            return '';
        }

        return <<<HTML
<!-- Custom Analytics Script -->
{$script}
<!-- End Custom Analytics Script -->
HTML;
    }
}
