<div class="space-y-8">
    {{-- Document header --}}
    <div class="pl-public-card p-6 md:p-8">
        <div class="flex items-start gap-4">
            <div class="flex h-12 w-12 flex-none items-center justify-center rounded-xl bg-[#f8fafc]">
                <x-public.icon name="{{ $documentIcon ?? 'file-text' }}" size="md" />
            </div>
            <div class="min-w-0 flex-1">
                <h2 class="text-2xl font-semibold text-textPrimary md:text-3xl">{{ $document['heading'] }}</h2>
                <p class="mt-3 text-sm leading-7 text-textSecondary md:text-base">{{ $document['intro'] }}</p>
                @if (in_array((string) ($activeLegal ?? ''), ['privacy', 'terms'], true))
                    <p class="mt-2 text-sm leading-7 text-textSecondary md:text-base">
                        {{ __('public.legal.ownership_line', ['product' => \App\Support\Brand::product(), 'parent' => \App\Support\Brand::parent()]) }}
                    </p>
                @endif
                <div class="mt-4 flex items-center gap-2 rounded-lg bg-[#f8fafc] px-3 py-2 text-xs uppercase tracking-wide text-textMuted">
                    <i data-lucide="calendar" class="h-3.5 w-3.5"></i>
                    <span>{{ __('public.legal.last_updated_label') }}: {{ $lastUpdated }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Articles (numbered policy sections) --}}
    @if(!empty($document['articles']))
        <div class="space-y-4">
            @foreach($document['articles'] as $index => $article)
                <article class="pl-public-card p-6 md:p-7">
                    <div class="flex items-start gap-4">
                        <span class="flex h-8 w-8 flex-none items-center justify-center rounded-lg bg-publicPrimary/10 text-sm font-semibold text-publicPrimary">
                            {{ $index + 1 }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-lg font-semibold text-textPrimary">{{ $article['title'] }}</h3>
                            <ul class="mt-4 space-y-3 text-sm leading-7 text-textSecondary">
                                @foreach($article['points'] as $point)
                                    <li class="flex items-start gap-3">
                                        <span class="mt-2 h-1.5 w-1.5 flex-none rounded-full bg-publicPrimary/40"></span>
                                        <span>{{ $point }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    {{-- Sections (check-marked feature blocks) --}}
    @if(!empty($document['sections']))
        <div class="grid gap-4 md:grid-cols-2">
            @foreach($document['sections'] as $section)
                <article class="pl-public-card p-6">
                    <h3 class="text-lg font-semibold text-textPrimary">{{ $section['title'] }}</h3>
                    <ul class="mt-4 space-y-3">
                        @foreach($section['bullets'] as $bullet)
                            <li class="flex items-start gap-3 text-sm leading-6 text-textSecondary">
                                <x-public.icon name="check" size="xs" class="mt-0.5 flex-none" />
                                <span>{{ $bullet }}</span>
                            </li>
                        @endforeach
                    </ul>
                </article>
            @endforeach
        </div>
    @endif

    {{-- Related documents --}}
    @if(!empty($relatedLinks))
        <div class="pl-public-card-soft p-6">
            <div class="flex items-center gap-2">
                <x-public.icon name="files" size="sm" />
                <h3 class="text-base font-semibold text-textPrimary">{{ __('public.legal.related_documents') }}</h3>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach($relatedLinks as $item)
                    <a href="{{ $item['url'] }}" class="inline-flex items-center gap-2 pl-public-card-compact px-4 py-2.5 text-sm font-medium text-textSecondary transition-colors hover:border-publicPrimary/30 hover:text-textPrimary">
                        {{ $item['label'] }}
                        <i data-lucide="arrow-right" class="h-3.5 w-3.5"></i>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Closing CTA block --}}
    <div class="pl-public-cta-panel pl-public-cta-panel--split p-6 md:p-8">
        <div class="flex flex-col gap-5 md:flex-row md:items-center md:justify-between">
            <div>
                <h3 class="text-lg font-semibold text-white">{{ __('public.legal.cta_title') }}</h3>
                <p class="mt-2 text-sm leading-6 text-white/75">{{ __('public.legal.cta_text') }}</p>
            </div>
            <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.contact') }}" class="pl-public-cta-primary flex-none">
                {{ __('public.legal.cta_button') }}
            </a>
        </div>
    </div>
</div>
