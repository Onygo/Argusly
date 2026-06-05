@props([
    'content' => null,
    'presenter' => null,
    'compact' => false,
])

@php
    use App\View\Presenters\ContentStatusPresenter;

    $presenter = $presenter ?? ($content ? ContentStatusPresenter::for($content) : null);
    $actions = $presenter?->deliveryActions() ?? [];
    $contentModel = $presenter?->getContent() ?? $content;
@endphp

@if($presenter && count($actions) > 0)
    <div {{ $attributes->class(['flex flex-wrap gap-2']) }}>
        @foreach($actions as $key => $action)
            @if($action['external'] ?? false)
                <a
                    href="{{ $action['route'] }}"
                    target="_blank"
                    rel="noopener"
                    class="{{ $compact ? 'px-2 py-1 text-xs' : 'px-3 py-2 text-sm' }} rounded border border-border font-medium text-textPrimary hover:bg-surfaceSubtle inline-flex items-center gap-1.5"
                >
                    <i data-lucide="{{ $action['icon'] }}" class="{{ $compact ? 'h-3 w-3' : 'h-4 w-4' }}" aria-hidden="true"></i>
                    <span>{{ $action['label'] }}</span>
                </a>
            @else
                <form method="POST" action="{{ route($action['route'], $contentModel) }}" class="inline-flex">
                    @csrf
                    <button
                        type="submit"
                        class="{{ $compact ? 'px-2 py-1 text-xs' : 'px-3 py-2 text-sm' }} rounded border border-border font-medium text-textPrimary hover:bg-surfaceSubtle inline-flex items-center gap-1.5"
                        @if($action['confirm'] ?? false)
                            onclick="return confirm('{{ $action['label'] }}? This may create a new remote resource with a different identifier.')"
                        @endif
                    >
                        <i data-lucide="{{ $action['icon'] }}" class="{{ $compact ? 'h-3 w-3' : 'h-4 w-4' }}" aria-hidden="true"></i>
                        <span>{{ $action['label'] }}</span>
                    </button>
                </form>
            @endif
        @endforeach
    </div>
@endif
