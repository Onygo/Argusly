<header class="sticky top-0 z-40 border-b border-line/70 bg-white/90 backdrop-blur">
    <div class="container-page flex h-16 items-center justify-between gap-6">
        <x-brand />
        <div class="flex items-center gap-3">
            <a href="{{ route('login') }}" class="hidden text-xs font-semibold text-muted hover:text-ink sm:inline">Sign in</a>
            <x-ui.button href="{{ route('marketing.signup') }}" variant="dark" size="sm" shape="pill">
                Request pilot
                <x-app.icon name="arrow-right" class="size-3.5" />
            </x-ui.button>
        </div>
    </div>
</header>
