{{-- Quick Planning Sidebar --}}
<aside
    id="calendar-sidebar"
    data-calendar-sidebar
    class="sticky top-4 rounded-lg border border-border bg-surface"
>
    <div class="border-b border-border px-5 py-4">
        <h2 class="text-lg font-semibold text-textPrimary">Snel plannen</h2>
        <p class="mt-1 text-sm text-textSecondary">Plan nieuwe content direct vanuit de kalender.</p>
    </div>

    <form
        method="POST"
        action="{{ route('app.content.calendar.quick-plan') }}"
        class="space-y-4 p-5"
        id="calendar-quick-plan-form"
    >
        @csrf

        {{-- Title --}}
        <div>
            <label class="mb-1 block text-xs text-textSecondary" for="qp-title">Titel</label>
                <input
                    id="qp-title"
                    data-calendar-sidebar-title-input
                    type="text"
                    name="title"
                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-textPrimary placeholder-textMuted focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                placeholder="Geef je content een titel"
                required
                maxlength="255"
                value="{{ old('title') }}"
            >
            @error('title')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
        </div>

        {{-- Serie --}}
        <div>
            <label class="mb-1 block text-xs text-textSecondary" for="qp-series">Serie</label>
            <select
                id="qp-series"
                name="series_id"
                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-textPrimary focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            >
                <option value="">Geen serie</option>
                @foreach ($series as $s)
                    <option value="{{ $s->id }}" @selected(old('series_id') === (string) $s->id)>
                        {{ $s->name }}
                    </option>
                @endforeach
            </select>
            @error('series_id')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
        </div>

        {{-- Content type & Status in 2-col grid --}}
        <div class="grid gap-4 sm:grid-cols-2">
            {{-- Content type --}}
            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="qp-type">Content type</label>
                <select
                    id="qp-type"
                    name="type"
                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-textPrimary focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                >
                    @foreach ($contentTypes as $typeKey => $typeLabel)
                        <option value="{{ $typeKey }}" @selected(old('type', 'article') === $typeKey)>
                            {{ $typeLabel }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Status --}}
            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="qp-status">Status</label>
                <select
                    id="qp-status"
                    name="status"
                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-textPrimary focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                >
                    <option value="brief" @selected(old('status') === 'brief')>Brief</option>
                    <option value="draft" @selected(old('status') === 'draft')>Draft</option>
                    <option value="scheduled" @selected(old('status', 'scheduled') === 'scheduled')>Gepland</option>
                </select>
            </div>
        </div>

        {{-- Datum & Tijd in 2-col grid --}}
        <div class="grid gap-4 sm:grid-cols-2">
            {{-- Datum --}}
            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="qp-date">Datum</label>
                <input
                    id="qp-date"
                    data-calendar-sidebar-date-input
                    type="date"
                    name="scheduled_date"
                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-textPrimary focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    value="{{ old('scheduled_date', $selectedDate?->format('Y-m-d') ?? now()->format('Y-m-d')) }}"
                >
            </div>

            {{-- Tijd --}}
            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="qp-time">Tijd</label>
                <input
                    id="qp-time"
                    type="time"
                    name="scheduled_time"
                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-textPrimary focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    value="{{ old('scheduled_time', '09:00') }}"
                >
            </div>
        </div>

        {{-- Intent (multi-select) --}}
        @if (!empty($intentOptions))
            <x-forms.tag-multi-select
                name="intent_keys"
                label="Intent"
                :options="$intentOptions"
                :selected="old('intent_keys', [])"
                placeholder="Selecteer intent(s)"
                help="Optioneel. Bepaalt de tone en aanpak van de content."
            />
        @endif

        {{-- Site --}}
        @if ($sites->count() > 0)
            <div>
                <label class="mb-1 block text-xs text-textSecondary" for="qp-site">Site</label>
                <select
                    id="qp-site"
                    name="site_id"
                    class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-textPrimary focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    @if ($sites->count() === 1) disabled @endif
                >
                    @if ($sites->count() > 1)
                        <option value="">Selecteer site</option>
                    @endif
                    @foreach ($sites as $site)
                        <option
                            value="{{ $site->id }}"
                            @selected($selectedSiteId === (string) $site->id || ($sites->count() === 1))
                        >
                            {{ $site->name }}
                        </option>
                    @endforeach
                </select>
                @if ($sites->count() === 1)
                    <input type="hidden" name="site_id" value="{{ $sites->first()->id }}">
                @endif
            </div>
        @endif

        {{-- Submit Button --}}
        <button
            type="submit"
            class="flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-textInverse transition-colors hover:bg-primaryHover"
        >
            <i data-lucide="calendar-plus" class="h-4 w-4"></i>
            Plan content
        </button>
    </form>

    {{-- Keyboard shortcut hint --}}
    <div class="border-t border-border px-5 py-3">
        <p class="text-center text-[10px] text-textMuted">
            Klik op een dag om de datum automatisch in te vullen
        </p>
    </div>
</aside>
