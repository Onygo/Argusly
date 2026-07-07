<!DOCTYPE html>
<html lang="{{ session('public_lang', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ isset($title) ? $title.' | '.\App\Support\Brand::product() : \App\Support\Brand::product() }}</title>
    @include('partials.brand-meta')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="pl-marketing-v2 min-h-screen bg-background antialiased text-textPrimary">
@if (($fullBleed ?? false) === true)
    @yield('content')
@else
    <div class="min-h-screen flex items-center justify-center px-4">
        <div class="w-full {{ $containerClass ?? 'max-w-sm' }} space-y-6">
            @yield('content')
            @if (config('brand.show_parent_branding', true))
                <p class="mt-4 text-center text-xs text-textMuted">
                    {{ \App\Support\Brand::product() }} by {{ \App\Support\Brand::parentLinked() }}
                </p>
            @endif
        </div>
    </div>
@endif
<script>
    if (window.lucide) {
        lucide.createIcons();
    }
</script>
</body>
</html>
