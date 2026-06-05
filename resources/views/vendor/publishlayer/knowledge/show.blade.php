@extends(config('publishlayer.layout', 'layouts.app'))

@section('title', $meta['title'])

@push('head')
    @include('publishlayer::knowledge.partials.head', ['meta' => $meta, 'structuredData' => $structuredData])
@endpush

@section('content')
    <article class="publishlayer-knowledge-article">
        @includeWhen(!empty($breadcrumbs), 'publishlayer::knowledge.partials.breadcrumbs', ['breadcrumbs' => $breadcrumbs])

        <header>
            <p><a href="{{ route('publishlayer.knowledge.index') }}">{{ $labels['back_to_knowledge_base'] ?? 'Back to Knowledge Base' }}</a></p>
            <h1>{{ $article->title }}</h1>

            @if ($article->category)
                <p>
                    {{ $labels['category'] ?? 'Category' }}:
                    <a href="{{ route('publishlayer.knowledge.category', ['slug' => $article->category->slug]) }}">{{ $article->category->name }}</a>
                </p>
            @endif

            <p>
                @if ($article->published_at)
                    <span>{{ $labels['published'] ?? 'Published' }} {{ $article->published_at->toFormattedDateString() }}</span>
                @endif
                @if (config('publishlayer.features.show_last_updated', true) && $lastUpdatedAt)
                    <span> · {{ $labels['last_updated'] ?? 'Last updated' }} {{ $lastUpdatedAt->toFormattedDateString() }}</span>
                @endif
                @if (config('publishlayer.features.show_reading_time', true))
                    <span> · {{ str_replace(':minutes', (string) $readingTimeMinutes, $labels['reading_time'] ?? ':minutes min read') }}</span>
                @endif
            </p>

            @if ($article->summary)
                <p>{{ $article->summary }}</p>
            @endif

            @php($imageAttribution = is_array($article->metadata ?? null) ? data_get($article->metadata, 'image_attribution', []) : [])
            @if ($article->featured_image_url)
                <figure class="publishlayer-featured-image">
                    <img src="{{ $article->featured_image_url }}" alt="{{ $article->title }}">
                    @if (is_array($imageAttribution) && !empty($imageAttribution['photographer_name']) && !empty($imageAttribution['photographer_url']) && !empty($imageAttribution['provider_name']) && !empty($imageAttribution['provider_url']))
                        <figcaption class="publishlayer-image-attribution" style="font-size:0.8125rem; line-height:1.4; color:#64748b; margin-top:0.35rem;">
                            Photo by
                            <a href="{{ $imageAttribution['photographer_url'] }}" target="_blank" rel="noopener">{{ $imageAttribution['photographer_name'] }}</a>
                            on
                            <a href="{{ $imageAttribution['provider_url'] }}" target="_blank" rel="noopener">{{ $imageAttribution['provider_name'] }}</a>
                        </figcaption>
                    @endif
                </figure>
            @endif
        </header>

        <section>
            {!! $article->content_html !!}
        </section>

        @if ($relatedArticles->isNotEmpty())
            <aside aria-label="{{ $labels['related_articles'] ?? 'Related articles' }}">
                <h2>{{ $labels['related_articles'] ?? 'Related articles' }}</h2>
                <div>
                    @foreach ($relatedArticles as $relatedArticle)
                        <article>
                            <h3>
                                <a href="{{ route('publishlayer.knowledge.show', ['slug' => $relatedArticle->slug]) }}">{{ $relatedArticle->title }}</a>
                            </h3>
                            @if ($relatedArticle->summary)
                                <p>{{ $relatedArticle->summary }}</p>
                            @endif
                            <p>
                                @if ($relatedArticle->category)
                                    <span>{{ $relatedArticle->category->name }}</span>
                                @endif
                                @if ($relatedArticle->published_at)
                                    <span> · {{ $labels['updated'] ?? 'Updated' }} {{ $relatedArticle->published_at->toFormattedDateString() }}</span>
                                @endif
                            </p>
                        </article>
                    @endforeach
                </div>
            </aside>
        @endif
    </article>
@endsection
