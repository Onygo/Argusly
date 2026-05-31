<header class="sticky top-0 z-40 border-b border-line/70 bg-white/90 backdrop-blur">
    <div class="container-page flex h-16 items-center justify-between gap-6">
        <x-brand class="text-sm" />
        <nav class="hidden items-center gap-8 text-xs font-semibold text-muted md:flex">
            <a href="#platform" class="hover:text-ink">Platform</a>
            <a href="#intelligence" class="hover:text-ink">Intelligence</a>
            <a href="#agents" class="hover:text-ink">Agents</a>
            <a href="#pricing" class="hover:text-ink">Pricing</a>
            <a href="#docs" class="hover:text-ink">Docs</a>
        </nav>
        <div class="flex items-center gap-3">
            <a href="{{ route('login') }}" class="hidden text-xs font-semibold text-muted hover:text-ink sm:inline">Sign in</a>
            <x-ui.button href="{{ route('login') }}" size="sm">Start monitoring</x-ui.button>
        </div>
    </div>
</header>
