@php($languages = $languages ?? ['badges' => [], 'summary' => ''])

<div class="max-w-xs">
    <div class="flex flex-wrap gap-1.5">
        @foreach (($languages['badges'] ?? []) as $badge)
            @if ($badge['is_missing'] ?? false)
                <x-locale-badge
                    :label="'+' . (string) ($badge['locale'] ?? '')"
                    :tone="(string) ($badge['tone'] ?? 'slate')"
                    :tooltip="$badge['tooltip'] ?? null"
                />
            @else
                <x-locale-badge
                    :label="(string) ($badge['locale'] ?? '')"
                    :tone="(string) ($badge['tone'] ?? (($badge['is_source'] ?? false) ? 'source' : 'variant'))"
                    :source="(bool) ($badge['is_source'] ?? false)"
                    :tooltip="$badge['tooltip'] ?? null"
                    :href="route('app.content.show', $badge['content'])"
                    class="transition-colors hover:border-primary/30 hover:text-textPrimary"
                />
            @endif
        @endforeach
    </div>

    @if (filled($languages['summary'] ?? ''))
        <div class="mt-1 text-[11px] text-textSecondary">{{ $languages['summary'] }}</div>
    @endif
</div>
