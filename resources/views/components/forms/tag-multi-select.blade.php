@props([
    'name',
    'label',
    'options' => [],
    'selected' => [],
    'placeholder' => 'Select one or more options',
    'help' => null,
    'errorKey' => null,
])

@php
    $fieldName = (string) $name;
    $inputName = str_ends_with($fieldName, '[]') ? $fieldName : $fieldName . '[]';
    $selectedValues = collect(is_array($selected) ? $selected : [$selected])
        ->map(fn ($value): string => trim((string) $value))
        ->filter()
        ->values()
        ->all();
    $resolvedErrorKey = $errorKey ?: rtrim($fieldName, '[]');
@endphp

<div class="space-y-2" data-tag-multi-select>
    <label class="block text-xs text-textSecondary">{{ $label }}</label>
    <details class="group relative">
        <summary class="flex min-h-[3rem] cursor-pointer list-none items-center justify-between rounded border border-border bg-background px-3 py-2 text-sm text-textPrimary marker:hidden [&::-webkit-details-marker]:hidden">
            <div class="flex flex-1 flex-wrap items-center gap-2" data-tag-multi-select-summary>
                <span class="text-textSecondary" data-tag-multi-select-placeholder>{{ $placeholder }}</span>
                @foreach ($selectedValues as $value)
                    <span class="inline-flex items-center rounded-full border border-border bg-surface px-2 py-1 text-xs text-textPrimary" data-tag-multi-select-tag="{{ $value }}">
                        {{ $options[$value] ?? $value }}
                    </span>
                @endforeach
            </div>
            <span class="ml-3 text-xs text-textSecondary">Select</span>
        </summary>

        <div class="mt-2 rounded-lg border border-border bg-surface p-3 shadow-sm md:absolute md:left-0 md:right-0 md:z-20">
            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($options as $value => $optionLabel)
                    <label class="flex items-start gap-3 rounded border border-transparent px-2 py-2 text-sm text-textPrimary transition hover:border-border hover:bg-background">
                        <input
                            type="checkbox"
                            name="{{ $inputName }}"
                            value="{{ $value }}"
                            class="mt-0.5 h-4 w-4 rounded border-border text-textPrimary focus:ring-0"
                            @checked(in_array($value, $selectedValues, true))
                        >
                        <span>{{ $optionLabel }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    </details>

    @if ($help)
        <p class="text-xs text-textSecondary">{{ $help }}</p>
    @endif
    @error($resolvedErrorKey)<p class="text-xs text-rose-700">{{ $message }}</p>@enderror
    @error($resolvedErrorKey . '.*')<p class="text-xs text-rose-700">{{ $message }}</p>@enderror
</div>

@once
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-tag-multi-select]').forEach(function (container) {
                const summary = container.querySelector('[data-tag-multi-select-summary]');
                const placeholder = container.querySelector('[data-tag-multi-select-placeholder]');
                const checkboxes = Array.from(container.querySelectorAll('input[type="checkbox"]'));

                const render = function () {
                    summary.querySelectorAll('[data-tag-multi-select-tag]').forEach(function (tag) {
                        tag.remove();
                    });

                    const selected = checkboxes.filter(function (checkbox) {
                        return checkbox.checked;
                    });

                    placeholder.style.display = selected.length === 0 ? 'inline-flex' : 'none';

                    selected.forEach(function (checkbox) {
                        const label = checkbox.closest('label')?.querySelector('span')?.textContent?.trim() || checkbox.value;
                        const tag = document.createElement('span');
                        tag.className = 'inline-flex items-center rounded-full border border-border bg-surface px-2 py-1 text-xs text-textPrimary';
                        tag.setAttribute('data-tag-multi-select-tag', checkbox.value);
                        tag.textContent = label;
                        summary.insertBefore(tag, placeholder.nextSibling);
                    });
                };

                checkboxes.forEach(function (checkbox) {
                    checkbox.addEventListener('change', render);
                });

                render();
            });
        });
    </script>
@endonce
