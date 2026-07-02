@props([
    'drawer' => [],
    'open' => null,
    'key' => null,
    'mode' => null,
    'modal' => null,
    'width' => null,
    'title' => null,
    'subtitle' => null,
    'description' => null,
    'tabs' => null,
    'sections' => null,
    'footerActions' => null,
    'state' => null,
    'loadingState' => null,
    'emptyState' => null,
    'errorState' => null,
    'focusReturnTarget' => null,
    'keyboardEscape' => null,
])

@php
    $drawerKey = $key ?? data_get($drawer, 'key', 'drawer');
    $drawerMode = $mode ?? data_get($drawer, 'mode', 'inspect');
    $drawerState = $state ?? data_get($drawer, 'state', []);
    $isOpen = $open ?? data_get($drawerState, 'open', true);
    $isModal = $modal ?? data_get($drawer, 'modal', false);
    $drawerWidth = $width ?? data_get($drawer, 'width', 'md');
    $drawerTitle = $title ?? data_get($drawer, 'title');
    $drawerSubtitle = $subtitle ?? data_get($drawer, 'subtitle');
    $drawerDescription = $description ?? data_get($drawer, 'description');
    $drawerTabs = $tabs ?? data_get($drawer, 'tabs', []);
    $drawerSections = $sections ?? data_get($drawer, 'sections', []);
    $drawerFooterActions = $footerActions ?? data_get($drawer, 'footer_actions', []);
    $drawerLoadingState = $loadingState ?? data_get($drawer, 'loading_state', []);
    $drawerEmptyState = $emptyState ?? data_get($drawer, 'empty_state', []);
    $drawerErrorState = $errorState ?? data_get($drawer, 'error_state', []);
    $drawerFocusReturnTarget = $focusReturnTarget ?? data_get($drawer, 'focus_return_target');
    $drawerKeyboardEscape = $keyboardEscape ?? data_get($drawer, 'keyboard_escape', []);
    $titleId = 'drawer-' . \Illuminate\Support\Str::slug((string) $drawerKey) . '-title';
    $descriptionId = 'drawer-' . \Illuminate\Support\Str::slug((string) $drawerKey) . '-description';
    $widthClass = match ($drawerWidth) {
        'sm' => 'max-w-sm',
        'lg' => 'max-w-2xl',
        'xl' => 'max-w-4xl',
        'full' => 'max-w-full',
        default => 'max-w-xl',
    };
@endphp

<aside
    {{ $attributes
        ->class(['pl-right-drawer fixed inset-y-0 right-0 z-50 flex w-full flex-col border-l border-border bg-surface shadow-xl', $widthClass])
        ->merge([
            'data-drawer' => $drawerKey,
            'data-drawer-mode' => $drawerMode,
            'data-drawer-modal' => $isModal ? 'true' : 'false',
            'data-focus-return-target' => $drawerFocusReturnTarget,
            'data-escape-enabled' => data_get($drawerKeyboardEscape, 'enabled', true) ? 'true' : 'false',
            'role' => $isModal ? 'dialog' : 'region',
            'aria-modal' => $isModal ? 'true' : null,
            'aria-labelledby' => filled($drawerTitle) ? $titleId : null,
            'aria-describedby' => filled($drawerDescription) ? $descriptionId : null,
        ]) }}
    @if (! $isOpen) hidden @endif
>
    <x-drawer.drawer-header
        :title="$drawerTitle"
        :subtitle="$drawerSubtitle"
        :description="$drawerDescription"
        :title-id="$titleId"
        :description-id="$descriptionId"
        :keyboard-escape="$drawerKeyboardEscape"
        :focus-return-target="$drawerFocusReturnTarget"
    >
        @isset($close)
            <x-slot:close>{{ $close }}</x-slot:close>
        @endif
        @isset($headerActions)
            <x-slot:actions>{{ $headerActions }}</x-slot:actions>
        @endif
    </x-drawer.drawer-header>

    @if (data_get($drawerState, 'loading'))
        <x-drawer.drawer-loading :state="$drawerLoadingState" :message="data_get($drawerState, 'message')" />
    @elseif (data_get($drawerState, 'error'))
        <x-drawer.drawer-error :state="$drawerErrorState" :message="data_get($drawerState, 'message')" />
    @elseif (data_get($drawerState, 'empty'))
        <x-drawer.drawer-empty :state="$drawerEmptyState" :message="data_get($drawerState, 'message')" />
    @else
        <x-drawer.drawer-tabs :tabs="$drawerTabs" />

        <div class="min-h-0 flex-1 overflow-y-auto p-5">
            @if ($slot->isNotEmpty())
                {{ $slot }}
            @else
                @foreach ($drawerSections as $section)
                    <x-drawer.drawer-section :section="$section" />
                @endforeach
            @endif
        </div>
    @endif

    <x-drawer.drawer-footer :actions="$drawerFooterActions">
        @isset($footer)
            {{ $footer }}
        @endif
    </x-drawer.drawer-footer>
</aside>
