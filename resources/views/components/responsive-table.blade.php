@props([
    'class' => '',
    'tableClass' => '',
])

<div {{ $attributes->class(['pl-desktop-table', $class]) }}>
    <div class="overflow-x-auto rounded-2xl border border-border/80 bg-surface">
        <table class="w-full text-sm {{ $tableClass }}">
            {{ $slot }}
        </table>
    </div>
</div>
