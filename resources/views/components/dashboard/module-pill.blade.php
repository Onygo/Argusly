@props(['module'])

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-md border border-line bg-white px-3 py-2 text-sm font-semibold text-ink']) }}>
    {{ $module->name }}
</span>
