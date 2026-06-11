@extends('public.legal.layout')

@section('legal_content')
<div class="space-y-8">
    {{-- Document header --}}
    <div class="rounded-md border border-border bg-white p-6 md:p-8">
        <div class="flex items-start gap-4">
            <div class="flex h-12 w-12 flex-none items-center justify-center rounded-md bg-[#f8fafc]">
                <x-public.icon name="cookie" size="md" />
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

    {{-- Cookie table --}}
    <article class="rounded-md border border-border bg-white p-6 md:p-8">
        <div class="flex items-start gap-3">
            <x-public.icon name="list" size="md" />
            <div>
                <h3 class="pl-public-heading pl-public-heading-h3">{{ __('public.legal.cookies.table_title') }}</h3>
                <p class="mt-2 text-sm leading-6 text-textSecondary">{{ __('public.legal.cookies.table_intro') }}</p>
            </div>
        </div>

        <div class="mt-6 overflow-x-auto rounded-md border border-border">
            <table class="min-w-full border-collapse text-left text-sm">
                <thead>
                    <tr class="border-b border-border bg-[#f8fafc] text-textMuted">
                        <th class="px-4 py-3 font-medium">{{ __('public.legal.cookies.columns.category') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('public.legal.cookies.columns.provider') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('public.legal.cookies.columns.purpose') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('public.legal.cookies.columns.type') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('public.legal.cookies.columns.persistence') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach(trans('public.legal.cookies.rows') as $row)
                        <tr class="align-top bg-white">
                            <td class="px-4 py-3.5 font-medium text-textPrimary">{{ $row['category'] }}</td>
                            <td class="px-4 py-3.5 text-textSecondary">{{ $row['provider'] }}</td>
                            <td class="px-4 py-3.5 text-textSecondary">{{ $row['purpose'] }}</td>
                            <td class="px-4 py-3.5 text-textSecondary">{{ $row['type'] }}</td>
                            <td class="px-4 py-3.5 text-textSecondary">{{ $row['persistence'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </article>

    {{-- Articles (policy sections) --}}
    @if(!empty($document['articles']))
        <div class="space-y-4">
            @foreach($document['articles'] as $index => $article)
                <article class="rounded-md border border-border bg-white p-6 md:p-7">
                    <div class="flex items-start gap-4">
                        <span class="flex h-8 w-8 flex-none items-center justify-center rounded-md bg-publicPrimary/10 text-sm font-semibold text-publicPrimary">
                            {{ $index + 1 }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <h3 class="pl-public-heading pl-public-heading-h3">{{ $article['title'] }}</h3>
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
                <article class="rounded-md border border-border bg-white p-6">
                    <h3 class="pl-public-heading pl-public-heading-h3">{{ $section['title'] }}</h3>
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
