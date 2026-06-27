@php
    $audienceOptions = (array) ($audienceOptions ?? []);
    $selected = collect((array) ($selected ?? []))
        ->map(fn ($value): string => (string) $value)
        ->filter()
        ->values()
        ->all();
    $impersonationActive = session()->has('admin_impersonator_id');
@endphp

<div>
    <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
        <label class="block text-xs text-textSecondary">Target audience</label>
        @can('admin-area-superadmin')
            <a href="{{ route('admin.editorial-taxonomy.index', ['type' => 'audience']) }}" class="text-xs font-medium text-primary hover:underline">
                Manage audiences
            </a>
        @endcan
    </div>

    @if (count($audienceOptions) > 0)
        <div class="flex min-h-11 flex-wrap gap-2 rounded border border-border bg-background p-2">
            @foreach ($audienceOptions as $value => $label)
                @php
                    $checked = in_array((string) $value, $selected, true);
                @endphp
                <label class="group inline-flex cursor-pointer items-center">
                    <input
                        type="checkbox"
                        name="audience_keys[]"
                        value="{{ $value }}"
                        class="peer sr-only"
                        @checked($checked)
                    >
                    <span class="inline-flex min-h-8 items-center rounded-md border px-3 py-1.5 text-sm font-medium transition peer-checked:border-primary peer-checked:bg-primary peer-checked:text-white {{ $checked ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-textPrimary hover:border-primary/50 hover:bg-surfaceMuted' }}">
                        {{ $label }}
                    </span>
                </label>
            @endforeach
        </div>
    @else
        <div class="rounded border border-dashed border-border bg-surfaceMuted px-3 py-3 text-sm text-textSecondary">
            No audience tags are available yet.
        </div>
    @endif

    <p class="mt-1 text-xs text-textSecondary">
        Select one or more audience tags.
        @if ($impersonationActive)
            To add audiences, stop impersonating and open Admin > Editorial taxonomy > Audience.
        @elseif (! auth()->user()?->isSuperadmin())
            Audiences are managed in Admin > Editorial taxonomy by a superadmin.
        @endif
    </p>
    @error('audience_keys')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
    @error('audience_keys.*')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
</div>
