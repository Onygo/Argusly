@extends(config('argusly.layout', 'layouts.app'))

@section('title', $meta['title'])

@push('head')
    @include('argusly::knowledge.partials.head', ['meta' => $meta, 'structuredData' => $structuredData])
@endpush

@section('content')
    <section class="argusly-knowledge-category">
        @includeWhen(!empty($breadcrumbs), 'argusly::knowledge.partials.breadcrumbs', ['breadcrumbs' => $breadcrumbs])

        <header>
            <p><a href="{{ route('argusly.knowledge.index') }}">{{ $labels['back_to_knowledge_base'] ?? 'Back to Knowledge Base' }}</a></p>
            <h1>{{ $category->name }}</h1>
            @if ($category->description)
                <p>{{ $category->description }}</p>
            @endif
        </header>

        @if (config('argusly.features.search', true))
            <section aria-label="{{ $labels['search_label'] ?? 'Search articles' }}">
                <form method="GET" action="{{ route('argusly.knowledge.category', ['slug' => $category->slug]) }}" role="search">
                    <label for="argusly-category-search">{{ $labels['search_label'] ?? 'Search articles' }}</label>
                    <input
                        id="argusly-category-search"
                        type="search"
                        name="q"
                        value="{{ $searchQuery }}"
                        placeholder="{{ $labels['search_placeholder'] ?? 'Search the knowledge base' }}"
                    >
                    <button type="submit">{{ $labels['search_button'] ?? 'Search' }}</button>
                    @if ($searchQuery !== '')
                        <a href="{{ route('argusly.knowledge.category', ['slug' => $category->slug]) }}">{{ $labels['search_clear'] ?? 'Clear search' }}</a>
                    @endif
                </form>
            </section>
        @endif

        <section aria-label="{{ $labels['articles'] ?? 'Articles' }}">
            @forelse ($articles as $article)
                <article>
                    <header>
                        <h2><a href="{{ route('argusly.knowledge.show', ['slug' => $article->slug]) }}">{{ $article->title }}</a></h2>
                        @if ($article->published_at)
                            <p>{{ $labels['published'] ?? 'Published' }} {{ $article->published_at->toFormattedDateString() }}</p>
                        @endif
                    </header>

                    @if ($article->summary)
                        <p>{{ $article->summary }}</p>
                    @endif
                </article>
            @empty
                <p>{{ $labels['no_category_articles'] ?? 'No published articles are available in this category.' }}</p>
            @endforelse
        </section>

        @include('argusly::knowledge.partials.pagination', ['paginator' => $articles, 'labels' => $labels])
    </section>
@endsection
