<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? 'Argusly - See how AI talks about your brand' }}</title>
        <meta name="description" content="Argusly monitors visibility across AI assistants, search, competitors and social.">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen antialiased">
        <x-marketing.header />
        <main>{{ $slot }}</main>
        <x-marketing.footer />
    </body>
</html>
