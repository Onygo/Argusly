@props([
    'title' => null,
    'showWorkspaceHeader' => true,
    'mainClass' => 'w-full p-4 sm:p-6 lg:p-8',
])

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? 'Argusly Dashboard' }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-panel antialiased">
        <div data-mobile-backdrop class="fixed inset-0 z-40 hidden bg-ink/30 lg:hidden"></div>
        <x-app.sidebar mobile />
        <div class="app-shell min-h-screen lg:grid lg:grid-cols-[272px_1fr]" data-shell>
            <x-app.sidebar />
            <div class="min-w-0">
                @if (session('impersonator_user_id'))
                    <div class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900 sm:px-6 lg:px-8">
                        <form method="POST" action="{{ route('impersonation.stop') }}" class="flex flex-wrap items-center justify-between gap-2">
                            @csrf
                            <span class="font-semibold">
                                Impersonation active: {{ session('impersonator_user_name', 'Platform admin') }} is viewing Argusly as {{ auth()->user()?->name }}.
                            </span>
                            <button class="rounded-md border border-amber-300 bg-white px-3 py-1.5 text-sm font-semibold text-amber-900">Stop impersonating</button>
                        </form>
                    </div>
                @endif
                <x-app.topbar />
                @if ($showWorkspaceHeader)
                    <x-app.workspace-header />
                @endif
                <main {{ $attributes->class($mainClass) }}>{{ $slot }}</main>
            </div>
        </div>
    </body>
</html>
