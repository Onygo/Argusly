@props([
    'content' => null,
])

@php
    use App\View\Presenters\ContentStatusPresenter;

    $presenter = $content ? ContentStatusPresenter::for($content) : null;
    $fullStatus = $presenter?->fullStatus();
    $recoveryMessage = $presenter?->recoveryMessage();
    $needsAttention = $presenter?->needsAttention() ?? false;
    $existence = $presenter?->existenceStatus();
@endphp

@if($presenter && $fullStatus)
    <div {{ $attributes->class(['space-y-4']) }}>
        {{-- Main Status Card --}}
        <div class="rounded-lg border border-border bg-surface">
            {{-- Section 1: PublishLayer Status --}}
            <div class="p-4 border-b border-border">
                <h3 class="mb-3 text-sm font-medium text-textPrimary flex items-center gap-2">
                    <i data-lucide="layers" class="h-4 w-4 text-textSecondary" aria-hidden="true"></i>
                    PublishLayer Status
                </h3>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-textSecondary">Content Lifecycle</span>
                    <x-status-badge
                        :label="$fullStatus['publishlayer']['value']"
                        :color="$fullStatus['publishlayer']['color']"
                        :icon="$fullStatus['publishlayer']['icon']"
                    />
                </div>
            </div>

            {{-- Section 2: Destination Delivery --}}
            <div class="p-4 border-b border-border">
                <h3 class="mb-3 text-sm font-medium text-textPrimary flex items-center gap-2">
                    <i data-lucide="send" class="h-4 w-4 text-textSecondary" aria-hidden="true"></i>
                    Destination Delivery
                </h3>
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-textSecondary">{{ $fullStatus['delivery']['label'] }}</span>
                        <x-status-badge
                            :label="$fullStatus['delivery']['value']"
                            :color="$fullStatus['delivery']['color']"
                            :icon="$fullStatus['delivery']['icon']"
                        />
                    </div>
                    @if($fullStatus['sync']['value'])
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-textSecondary">{{ $fullStatus['sync']['label'] }}</span>
                            <span class="text-textPrimary">{{ $fullStatus['sync']['value'] }}</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Section 3: Remote State --}}
            <div class="p-4 border-b border-border">
                <h3 class="mb-3 text-sm font-medium text-textPrimary flex items-center gap-2">
                    <i data-lucide="globe" class="h-4 w-4 text-textSecondary" aria-hidden="true"></i>
                    Remote State
                </h3>
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-textSecondary">Existence</span>
                        <x-status-badge
                            :label="$existence?->label() ?? 'Unknown'"
                            :color="$existence?->color() ?? 'slate'"
                            :icon="$existence?->icon() ?? 'help-circle'"
                        />
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-textSecondary">{{ $fullStatus['remote']['label'] }}</span>
                        <div class="flex items-center gap-2">
                            <x-status-badge
                                :label="$fullStatus['remote']['value']"
                                :color="$fullStatus['remote']['color']"
                                :icon="$fullStatus['remote']['icon']"
                            />
                            @if($fullStatus['remote']['url'])
                                <a
                                    href="{{ $fullStatus['remote']['url'] }}"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-primary hover:text-primaryHover"
                                    title="Open on destination"
                                >
                                    <i data-lucide="external-link" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Section 4: Recovery Message (when needs attention) --}}
            @if($needsAttention && $recoveryMessage)
                <div class="p-4 border-b border-border">
                    <div class="rounded-md border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950">
                        <div class="flex items-start gap-2">
                            <i data-lucide="alert-triangle" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden="true"></i>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-amber-800 dark:text-amber-200">Attention Required</p>
                                <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">{{ $recoveryMessage }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Section 5: Error Details (if any) --}}
            @if($fullStatus['error'])
                <div class="p-4 border-b border-border">
                    <div class="rounded-md border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-950">
                        <div class="flex items-start gap-2">
                            <i data-lucide="x-circle" class="mt-0.5 h-4 w-4 shrink-0 text-red-600 dark:text-red-400" aria-hidden="true"></i>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-red-800 dark:text-red-200">{{ $fullStatus['error']['label'] }}</p>
                                <p class="mt-1 text-sm text-red-700 dark:text-red-300 font-mono text-xs break-words">{{ $fullStatus['error']['value'] }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Section 6: Action Buttons --}}
            <div class="p-4">
                <x-delivery-actions :content="$content" :presenter="$presenter" />
            </div>
        </div>

        {{-- Section 7: Delivery Timeline (separate card) --}}
        <x-delivery-timeline :content="$content" :presenter="$presenter" />
    </div>
@endif
