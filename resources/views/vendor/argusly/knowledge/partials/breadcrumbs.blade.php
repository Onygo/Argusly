<nav aria-label="Breadcrumbs">
    <ol>
        @foreach ($breadcrumbs as $breadcrumb)
            <li>
                @if (! $loop->last)
                    <a href="{{ $breadcrumb['url'] }}">{{ $breadcrumb['label'] }}</a>
                @else
                    <span aria-current="page">{{ $breadcrumb['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
