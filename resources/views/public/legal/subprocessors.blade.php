@extends('public.legal.layout')

@section('legal_content')
<div class="space-y-8">
    {{-- Document header --}}
    <div class="rounded-md border border-border bg-white p-6 md:p-8">
        <div class="flex items-start gap-4">
            <div class="flex h-12 w-12 flex-none items-center justify-center rounded-md bg-[#f8fafc]">
                <x-public.icon name="users" size="md" />
            </div>
            <div class="min-w-0 flex-1">
                <h2 class="pl-public-heading pl-public-heading-h2">{{ $document['heading'] }}</h2>
                <p class="mt-3 text-sm leading-7 text-textSecondary md:text-base">{{ $document['intro'] }}</p>
                <div class="mt-4 flex items-center gap-2 rounded-md bg-[#f8fafc] px-3 py-2 text-xs uppercase tracking-wide text-textMuted">
                    <i data-lucide="calendar" class="h-3.5 w-3.5"></i>
                    <span>{{ __('public.legal.last_updated_label') }}: {{ $lastUpdated }}</span>
                </div>
            </div>
        </div>
    </div>

    <article class="rounded-md border border-border bg-white p-6 md:p-8">
        <div class="flex items-start gap-3">
            <x-public.icon name="server" size="md" />
            <div>
                <h3 class="pl-public-heading pl-public-heading-h3">{{ __('public.legal.subprocessors.table_title') }}</h3>
                <p class="mt-2 text-sm text-textSecondary">{{ __('public.legal.subprocessors.table_intro') }}</p>
            </div>
        </div>

        <div class="mt-8 grid gap-4 lg:grid-cols-2">
            @foreach($subprocessors as $provider)
                <section class="pl-public-card-compact pl-public-canvas p-5">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-md bg-white text-sm font-semibold text-publicPrimary">
                            {{ substr($provider['name'], 0, 2) }}
                        </span>
                        <h4 class="pl-public-heading pl-public-heading-card">{{ $provider['name'] }}</h4>
                    </div>

                    <dl class="mt-5 space-y-3 text-sm">
                        <div class="grid grid-cols-[auto_1fr] gap-x-3">
                            <dt class="text-textMuted">{{ __('public.legal.subprocessors.fields.service_category') }}:</dt>
                            <dd class="text-textSecondary">{{ $provider['service_category'] }}</dd>
                        </div>
                        <div class="grid grid-cols-[auto_1fr] gap-x-3">
                            <dt class="text-textMuted">{{ __('public.legal.subprocessors.fields.location') }}:</dt>
                            <dd class="text-textSecondary">{{ $provider['location'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-textMuted">{{ __('public.legal.subprocessors.fields.purpose') }}:</dt>
                            <dd class="mt-1 text-textSecondary">{{ $provider['purpose'] }}</dd>
                        </div>
                    </dl>

                    <div class="mt-4 flex flex-wrap gap-2 border-t border-border/60 pt-4">
                        <a href="{{ $provider['website'] }}" class="inline-flex items-center gap-1 rounded-md bg-white px-3 py-1.5 text-xs font-medium text-textSecondary transition-colors hover:text-textPrimary" rel="noopener noreferrer" target="_blank">
                            <i data-lucide="external-link" class="h-3 w-3"></i>
                            Website
                        </a>
                        @if($provider['privacy_url'])
                            <a href="{{ $provider['privacy_url'] }}" class="inline-flex items-center gap-1 rounded-md bg-white px-3 py-1.5 text-xs font-medium text-textSecondary transition-colors hover:text-textPrimary" rel="noopener noreferrer" target="_blank">
                                <i data-lucide="shield" class="h-3 w-3"></i>
                                Privacy
                            </a>
                        @endif
                        @if($provider['dpa_url'])
                            <a href="{{ $provider['dpa_url'] }}" class="inline-flex items-center gap-1 rounded-md bg-white px-3 py-1.5 text-xs font-medium text-textSecondary transition-colors hover:text-textPrimary" rel="noopener noreferrer" target="_blank">
                                <i data-lucide="file-text" class="h-3 w-3"></i>
                                DPA
                            </a>
                        @endif
                    </div>

                    <p class="mt-3 text-xs text-textMuted">{{ __('public.legal.subprocessors.fields.last_updated') }}: {{ $provider['last_updated'] }}</p>
                </section>
            @endforeach
        </div>
    </article>

    @if(!empty($relatedLinks))
        <div class="pl-public-card-compact pl-public-canvas p-6">
            <div class="flex items-center gap-2">
                <x-public.icon name="files" size="sm" />
                <h3 class="pl-public-heading pl-public-heading-card">{{ __('public.legal.related_documents') }}</h3>
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
                <h3 class="pl-public-heading pl-public-heading-h3 text-white">{{ __('public.legal.cta_title') }}</h3>
                <p class="mt-2 text-sm leading-6 text-white/75">{{ __('public.legal.cta_text') }}</p>
            </div>
            <a href="{{ \App\Support\LocalizedMarketingUrl::route('public.contact') }}" class="pl-public-cta-primary flex-none">
                {{ __('public.legal.cta_button') }}
            </a>
        </div>
    </div>
</div>
@endsection
