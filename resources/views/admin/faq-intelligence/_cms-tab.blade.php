@php
    $pageType = (string) ($pageType ?? 'resource');
    $pageSlug = (string) ($pageSlug ?? 'unknown');
    $locale = (string) ($locale ?? app()->getLocale());
    $title = (string) ($title ?? '');
    $metaTitle = (string) ($metaTitle ?? '');
    $metaDescription = (string) ($metaDescription ?? '');
    $h1 = (string) ($h1 ?? '');
    $content = (string) ($content ?? '');
    $sector = (string) ($sector ?? '');
    $solutionType = (string) ($solutionType ?? '');
    $latestAudit = \App\Models\FaqOpportunityAudit::query()
        ->where('page_type', $pageType)
        ->where('page_slug', $pageSlug)
        ->where('locale', strtolower((string) $locale))
        ->latest()
        ->first();
    $linkedFaqs = app(\App\Repositories\FaqQuestionRepository::class)
        ->publishedForPage((string) $pageType, (string) $pageSlug, (string) $locale);
@endphp

<section class="rounded-lg border border-border bg-surface p-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-textSecondary">FAQ Intelligence</p>
            <h2 class="mt-1 text-lg font-semibold text-textPrimary">{{ $title ?: $pageSlug }}</h2>
            <p class="mt-1 text-sm text-textSecondary">Detecteer ontbrekende buyer questions en publiceer FAQ's vanuit de centrale knowledge base.</p>
        </div>
        <form method="POST" action="{{ route('admin.faq-intelligence.analyze') }}">
            @csrf
            <input type="hidden" name="page_type" value="{{ $pageType }}">
            <input type="hidden" name="page_slug" value="{{ $pageSlug }}">
            <input type="hidden" name="locale" value="{{ $locale }}">
            <input type="hidden" name="page_title" value="{{ $title }}">
            <input type="hidden" name="meta_title" value="{{ $metaTitle }}">
            <input type="hidden" name="meta_description" value="{{ $metaDescription }}">
            <input type="hidden" name="h1" value="{{ $h1 }}">
            <input type="hidden" name="content" value="{{ $content }}">
            <input type="hidden" name="sector" value="{{ $sector }}">
            <input type="hidden" name="solution_type" value="{{ $solutionType }}">
            <button class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white">Analyseer pagina</button>
        </form>
    </div>

    <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ([
            'Coverage' => $latestAudit?->faq_coverage_score,
            'Opportunity' => $latestAudit?->faq_opportunity_score,
            'AI Visibility' => $latestAudit?->ai_visibility_impact_score,
            'SEO' => $latestAudit?->seo_impact_score,
            'Conversion' => $latestAudit?->conversion_impact_score,
        ] as $label => $score)
            <div class="rounded border border-border bg-background p-3">
                <p class="text-xs text-textSecondary">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ $score === null ? '-' : number_format((float) $score, 1) }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-3">
        <div>
            <h3 class="text-sm font-semibold text-textPrimary">Gekoppelde FAQ's</h3>
            <div class="mt-3 space-y-2">
                @forelse ($linkedFaqs as $faq)
                    <div class="rounded border border-border bg-background p-3 text-sm">
                        <p class="font-medium text-textPrimary">{{ $faq->question }}</p>
                        <p class="mt-1 text-xs text-textSecondary">{{ $faq->faq_type?->label() }} · priority {{ $faq->priority }}</p>
                    </div>
                @empty
                    <p class="text-sm text-textSecondary">Nog geen gepubliceerde FAQ's gekoppeld.</p>
                @endforelse
            </div>
        </div>
        <div>
            <h3 class="text-sm font-semibold text-textPrimary">Ontbrekende vragen</h3>
            <div class="mt-3 space-y-2">
                @forelse (collect((array) $latestAudit?->missing_questions)->flatten()->take(6) as $question)
                    <p class="rounded border border-border bg-background px-3 py-2 text-sm text-textSecondary">{{ $question }}</p>
                @empty
                    <p class="text-sm text-textSecondary">Nog geen analyse beschikbaar.</p>
                @endforelse
            </div>
        </div>
        <div>
            <h3 class="text-sm font-semibold text-textPrimary">Concept FAQ's</h3>
            <div class="mt-3 space-y-2">
                @forelse (collect((array) $latestAudit?->generated_faqs)->take(4) as $faq)
                    <p class="rounded border border-border bg-background px-3 py-2 text-sm text-textSecondary">{{ $faq['question'] ?? '' }}</p>
                @empty
                    <p class="text-sm text-textSecondary">Genereer voorstellen via analyse.</p>
                @endforelse
            </div>
        </div>
    </div>
</section>
