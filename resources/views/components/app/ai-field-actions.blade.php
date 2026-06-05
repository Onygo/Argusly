@props([
    'target',
    'context' => null,
])

@php
    $rt = function (string $value, array $replace = []): string {
        $key = 'app.runtime.'.$value;
        $translated = __($key, $replace);

        return $translated === $key ? strtr($value, collect($replace)->mapWithKeys(fn ($replacement, $placeholder) => [':'.$placeholder => $replacement])->all()) : $translated;
    };
@endphp

<div
    class="mt-2 flex flex-wrap items-center gap-2 text-xs"
    data-ai-field-actions
    data-target="{{ $target }}"
    data-context="{{ $context }}"
>
    @foreach ([
        'improve' => 'Improve',
        'shorten' => 'Shorten',
        'make_technical' => 'Make more technical',
        'make_commercial' => 'Make more commercial',
    ] as $action => $label)
        <button
            type="button"
            data-ai-field-action="{{ $action }}"
            class="inline-flex items-center rounded-md border border-border px-2.5 py-1 text-xs font-medium text-textSecondary transition hover:bg-surfaceSubtle hover:text-textPrimary"
        >
            {{ $rt($label) }}
        </button>
    @endforeach
    <span class="hidden text-textSecondary" data-ai-field-status>{{ $rt('Working...') }}</span>
</div>

@once
    <script>
        (() => {
            if (window.__plAiFieldActionsInitialized) {
                return;
            }

            window.__plAiFieldActionsInitialized = true;

            document.addEventListener('click', async (event) => {
                const button = event.target.closest('[data-ai-field-action]');
                if (!button) {
                    return;
                }

                const root = button.closest('[data-ai-field-actions]');
                const targetSelector = root?.dataset.target;
                const target = targetSelector ? document.querySelector(targetSelector) : null;
                const status = root?.querySelector('[data-ai-field-status]');

                if (!root || !target || !target.value.trim()) {
                    return;
                }

                const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                if (!csrf) {
                    return;
                }

                const originalLabel = button.textContent;
                const action = button.dataset.aiFieldAction;

                button.disabled = true;
                if (status) {
                    status.textContent = @json($rt('Working...'));
                    status.classList.remove('hidden');
                }

                try {
                    const response = await fetch(@json(route('app.api.brand.field-actions')), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({
                            field_value: target.value,
                            action: action,
                            field_context: root.dataset.context || null,
                        }),
                    });

                    const payload = await response.json();
                    if (!response.ok || !payload.success) {
                        throw new Error(payload.message || @json($rt('AI action failed.')));
                    }

                    target.value = payload.transformed_value || target.value;
                    target.dispatchEvent(new Event('input', { bubbles: true }));
                    target.dispatchEvent(new Event('change', { bubbles: true }));

                    if (status) {
                        status.textContent = @json($rt('Updated'));
                    }
                } catch (error) {
                    if (status) {
                        status.textContent = error?.message || @json($rt('AI action failed.'));
                    }
                } finally {
                    button.disabled = false;
                    button.textContent = originalLabel;

                    if (status) {
                        window.setTimeout(() => {
                            status.classList.add('hidden');
                        }, 1800);
                    }
                }
            });
        })();
    </script>
@endonce
