@extends('public.legal.layout')

@section('legal_content')
<div class="space-y-10">
    {{-- Intro --}}
    <div class="max-w-3xl">
        <p class="text-sm leading-6 text-textSecondary md:text-base">
            {{ __('public.legal.hub.intro') }}
        </p>
    </div>

    {{-- Legal Entry Cards --}}
    <div class="grid gap-4 md:grid-cols-2">
        @php
            $cardIcons = [
                'Privacy' => 'shield',
                'Terms' => 'file-text',
                'Security' => 'lock',
                'Cookies' => 'cookie',
                'Subprocessors' => 'server',
                'Privacybeleid' => 'shield',
                'Voorwaarden' => 'file-text',
                'Beveiliging' => 'lock',
                'Subverwerkers' => 'server',
            ];
        @endphp
        @foreach($hubCards as $card)
            <article class="group pl-public-card p-6 transition-colors hover:border-borderStrong">
                <div class="flex items-start gap-4">
                    <x-public.icon :name="$cardIcons[$card['title']] ?? 'file-text'" size="md" />
                    <div class="flex-1">
                        <h2 class="pl-public-heading pl-public-heading-h3">{{ $card['title'] }}</h2>
                        <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $card['description'] }}</p>
                        <a href="{{ $card['url'] }}" class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-publicPrimary hover:text-publicPrimaryHover">
                            {{ $card['link_label'] }}
                            <i data-lucide="arrow-right" class="h-4 w-4"></i>
                        </a>
                    </div>
                </div>
            </article>
        @endforeach
    </div>

    {{-- Dark Governance Section --}}
    <section class="pl-public-cta-panel pl-public-cta-panel--split p-6 md:p-8">
        <div class="flex items-start gap-3 text-white">
            <x-public.icon name="shield-check" size="md" class="flex-none bg-white/10 text-white" />
            <div>
                <h2 class="text-lg font-semibold">{{ __('public.legal.hub.section_1_title') }}</h2>
                <p class="mt-2 text-sm leading-6 text-white/80">{{ __('public.legal.hub.section_1_text') }}</p>
            </div>
        </div>
    </section>

    {{-- Document Updates Section --}}
    <section class="pl-public-card-soft p-6">
        <h2 class="pl-public-heading pl-public-heading-h3">{{ __('public.legal.hub.section_2_title') }}</h2>
        <p class="mt-2 text-sm leading-6 text-textSecondary">{{ __('public.legal.hub.section_2_text') }}</p>
    </section>

    {{-- Contact Section --}}
    <section class="pl-public-card p-6">
        <h2 class="pl-public-heading pl-public-heading-h3">{{ __('public.legal.hub.section_3_title') }}</h2>
        <p class="mt-2 text-sm leading-6 text-textSecondary">{{ __('public.legal.hub.section_3_text') }}</p>
        <a
            href="{{ $contactUrl }}"
            class="mt-4 pl-public-primary-button"
        >
            {{ __('public.legal.hub.section_3_cta') }}
        </a>
    </section>
</div>
@endsection
