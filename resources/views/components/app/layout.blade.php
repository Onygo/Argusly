<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? 'Argusly Dashboard' }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-panel antialiased">
        <div class="min-h-screen lg:grid lg:grid-cols-[260px_1fr]">
            <x-app.sidebar />
            <div class="min-w-0">
                <x-app.topbar />
                <main class="p-4 sm:p-6 lg:p-8">{{ $slot }}</main>
            </div>
        </div>
    </body>
</html>
