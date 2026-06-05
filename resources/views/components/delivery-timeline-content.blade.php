@php
    use App\Models\ContentDeliveryEvent;
@endphp

<div class="divide-y divide-border max-h-80 overflow-y-auto">
    @foreach($events as $event)
        @php
            $config = $eventConfig[$event->event_type] ?? ['icon' => 'activity', 'label' => ucfirst(str_replace('_', ' ', $event->event_type))];
            $isSuccess = $event->isSuccess();
        @endphp
        <div class="px-4 py-3 flex items-start gap-3 text-sm">
            <div class="flex-shrink-0 mt-0.5">
                <span @class([
                    'inline-flex items-center justify-center h-6 w-6 rounded-full',
                    'bg-emerald-100 dark:bg-emerald-900/30' => $isSuccess,
                    'bg-red-100 dark:bg-red-900/30' => !$isSuccess,
                ])>
                    <i data-lucide="{{ $config['icon'] }}" @class([
                        'h-3.5 w-3.5',
                        'text-emerald-600 dark:text-emerald-400' => $isSuccess,
                        'text-red-600 dark:text-red-400' => !$isSuccess,
                    ]) aria-hidden="true"></i>
                </span>
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-medium text-textPrimary">
                        {{ $config['label'] }}
                    </span>
                    <span @class([
                        'inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-medium',
                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' => $isSuccess,
                        'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' => !$isSuccess,
                    ])>
                        {{ $isSuccess ? 'Success' : 'Failed' }}
                    </span>
                    @if($event->http_status)
                        <span class="text-xs text-textSecondary">HTTP {{ $event->http_status }}</span>
                    @endif
                </div>
                <p class="text-xs text-textSecondary mt-0.5">
                    {{ $event->created_at->diffForHumans() }}
                    @if($event->duration_ms)
                        <span class="text-textFaint">&middot; {{ number_format($event->duration_ms) }}ms</span>
                    @endif
                </p>
                @if($event->message && !$isSuccess)
                    <details class="mt-2">
                        <summary class="text-xs text-red-600 dark:text-red-400 cursor-pointer hover:underline">
                            Show error details
                        </summary>
                        <div class="mt-1 p-2 rounded bg-red-50 dark:bg-red-950/50 text-xs text-red-700 dark:text-red-300 font-mono break-words">
                            {{ $event->message }}
                        </div>
                    </details>
                @endif
            </div>
        </div>
    @endforeach
</div>
