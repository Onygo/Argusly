<section class="bg-background">
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6 md:py-14">
        <div class="grid gap-10 lg:grid-cols-[1fr_280px] lg:items-start">
            <main class="min-w-0 order-2 lg:order-1">
                @yield('legal_content')
            </main>

            <aside class="order-1 lg:order-2 lg:sticky lg:top-24">
                {{-- Mobile select --}}
                <div class="lg:hidden">
                    <label for="legal-page-picker" class="mb-2 block text-xs font-semibold uppercase tracking-wide text-textMuted">{{ __('public.nav.legal') }}</label>
                    <select
                        id="legal-page-picker"
                        class="w-full rounded-md border border-border bg-white px-4 py-2.5 text-sm text-textPrimary focus:border-publicPrimary focus:outline-none focus:ring-1 focus:ring-publicPrimary"
                        onchange="if (this.value) window.location.href = this.value;"
                    >
                        @foreach($items as $item)
                            <option value="{{ $item['url'] }}" @selected($activeLegal === $item['key'])>{{ $item['label'] }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Desktop sidebar --}}
                <nav class="hidden pl-public-card p-4 lg:block" aria-label="Legal navigation">
                    <p class="mb-3 px-3 text-xs font-semibold uppercase tracking-wide text-textMuted">{{ __('public.nav.legal') }}</p>
                    <ul class="space-y-1">
                        @foreach($items as $item)
                            <li>
                                <a
                                    href="{{ $item['url'] }}"
                                    class="flex items-center gap-2 rounded-md px-3 py-2.5 text-sm font-medium transition-colors {{ $activeLegal === $item['key'] ? 'bg-publicPrimary text-white' : 'text-textSecondary hover:bg-[#f8fafc] hover:text-textPrimary' }}"
                                    @if($activeLegal === $item['key']) aria-current="page" @endif
                                >
                                    @if($activeLegal === $item['key'])
                                        <i data-lucide="check" class="h-4 w-4"></i>
                                    @endif
                                    <span>{{ $item['label'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            </aside>
        </div>
    </div>
</section>
