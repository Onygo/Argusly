@php($siteKey = trim((string) config('services.recaptcha.site_key', '')))

@if ($siteKey)
    <script src="https://www.google.com/recaptcha/api.js?render={{ $siteKey }}"></script>
@endif
