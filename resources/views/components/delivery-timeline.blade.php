@props([
    'content' => null,
    'presenter' => null,
    'limit' => 10,
    'collapsible' => true,
])

@php
    use App\View\Presenters\ContentStatusPresenter;
    use App\Models\ContentDeliveryEvent;

    $presenter = $presenter ?? ($content ? ContentStatusPresenter::for($content) : null);
    $events = $presenter?->recentDeliveryEvents($limit) ?? collect();
    $destinationLabel = $presenter?->destinationLabel() ?? 'destination';

    $eventConfig = [
        ContentDeliveryEvent::TYPE_CREATE_REMOTE => ['icon' => 'plus-circle', 'label' => 'Created on ' . $destinationLabel],
        ContentDeliveryEvent::TYPE_UPDATE_REMOTE => ['icon' => 'refresh-cw', 'label' => 'Updated on ' . $destinationLabel],
        ContentDeliveryEvent::TYPE_RECREATE_REMOTE => ['icon' => 'copy-plus', 'label' => 'Recreated on ' . $destinationLabel],
        ContentDeliveryEvent::TYPE_VERIFY_REMOTE => ['icon' => 'search', 'label' => 'Verified remote existence'],
        ContentDeliveryEvent::TYPE_FAIL_REMOTE => ['icon' => 'x-circle', 'label' => 'Delivery failed'],
    ];
@endphp

@if($events->isNotEmpty())
    <div {{ $attributes->class(['rounded-lg border border-border bg-surface']) }}>
        @if($collapsible)
            <details class="group">
                <summary class="px-4 py-3 cursor-pointer list-none flex items-center justify-between text-sm font-medium text-textPrimary hover:bg-surfaceSubtle [&::-webkit-details-marker]:hidden">
                    <span class="flex items-center gap-2">
                        <i data-lucide="history" class="h-4 w-4 text-textSecondary" aria-hidden="true"></i>
                        Delivery Timeline
                    </span>
                    <span class="flex items-center gap-2">
                        <span class="text-xs text-textSecondary">{{ $events->count() }} event{{ $events->count() === 1 ? '' : 's' }}</span>
                        <i data-lucide="chevron-down" class="h-4 w-4 text-textSecondary transition-transform group-open:rotate-180" aria-hidden="true"></i>
                    </span>
                </summary>
                <div class="border-t border-border">
                    @include('components.delivery-timeline-content', ['events' => $events, 'eventConfig' => $eventConfig])
                </div>
            </details>
        @else
            <div class="px-4 py-3 border-b border-border">
                <h4 class="text-sm font-medium text-textPrimary flex items-center gap-2">
                    <i data-lucide="history" class="h-4 w-4 text-textSecondary" aria-hidden="true"></i>
                    Delivery Timeline
                </h4>
            </div>
            @include('components.delivery-timeline-content', ['events' => $events, 'eventConfig' => $eventConfig])
        @endif
    </div>
@endif
