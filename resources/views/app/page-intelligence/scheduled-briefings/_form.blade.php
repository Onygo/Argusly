@php
    $briefing = $briefing ?? null;
    $selectedWorkspace = old('workspace', $workspace->id);
    $selectedFrequency = old('frequency', $briefing?->frequency ?? 'weekly');
    $selectedTimezone = old('timezone', $briefing?->timezone ?? config('app.timezone', 'UTC'));
    $recipients = old('recipients', implode("\n", (array) ($briefing?->recipients_json ?? [])));
    $channels = old('delivery_channels', (array) ($briefing?->delivery_channels_json ?? []));
    $inAppChecked = $briefing ? in_array('in_app', $channels, true) : true;
    $emailChecked = in_array('email', $channels, true) || in_array('email_placeholder', $channels, true);
@endphp

<div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
    <label class="block">
        <span class="text-xs text-textSecondary">Workspace</span>
        <select name="workspace" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm" @disabled($briefing)>
            @foreach ($workspaces as $option)
                <option value="{{ $option->id }}" @selected((string) $option->id === (string) $selectedWorkspace)>{{ $option->display_name }}</option>
            @endforeach
        </select>
    </label>
    <label class="block">
        <span class="text-xs text-textSecondary">Client site</span>
        <select name="client_site_id" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
            <option value="">All sites</option>
            @foreach ($clientSites as $site)
                <option value="{{ $site->id }}" @selected((string) old('client_site_id', $briefing?->client_site_id) === (string) $site->id)>{{ $site->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="block">
        <span class="text-xs text-textSecondary">Report type</span>
        <select name="report_type" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
            @foreach ($reportTypes as $key => $type)
                <option value="{{ $key }}" @selected(old('report_type', $briefing?->report_type ?? \App\Services\PageIntelligence\Reports\ReportBuilder::TYPE_WEEKLY) === $key)>{{ $type['label'] }}</option>
            @endforeach
        </select>
    </label>
    <label class="block">
        <span class="text-xs text-textSecondary">Market pack</span>
        <select name="market_pack_key" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
            <option value="">All markets</option>
            @foreach ($marketPacks as $pack)
                <option value="{{ $pack->key }}" @selected(old('market_pack_key', $briefing?->market_pack_key) === $pack->key)>{{ $pack->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="block">
        <span class="text-xs text-textSecondary">Frequency</span>
        <select name="frequency" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
            <option value="weekly" @selected($selectedFrequency === 'weekly')>Weekly</option>
            <option value="monthly" @selected($selectedFrequency === 'monthly')>Monthly</option>
        </select>
    </label>
    <label class="block">
        <span class="text-xs text-textSecondary">Day of week</span>
        <select name="day_of_week" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
            @foreach ($daysOfWeek as $value => $label)
                <option value="{{ $value }}" @selected((int) old('day_of_week', $briefing?->day_of_week ?? 1) === (int) $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="block">
        <span class="text-xs text-textSecondary">Day of month</span>
        <input name="day_of_month" type="number" min="1" max="31" value="{{ old('day_of_month', $briefing?->day_of_month ?? 1) }}" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
    </label>
    <label class="block">
        <span class="text-xs text-textSecondary">Timezone</span>
        <select name="timezone" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
            @foreach ($timezones as $timezone)
                <option value="{{ $timezone }}" @selected($selectedTimezone === $timezone)>{{ $timezone }}</option>
            @endforeach
        </select>
    </label>
    <label class="block md:col-span-2">
        <span class="text-xs text-textSecondary">Recipients</span>
        <textarea name="recipients" rows="3" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">{{ $recipients }}</textarea>
    </label>
    <fieldset class="rounded border border-border px-3 py-2">
        <legend class="px-1 text-xs text-textSecondary">Delivery</legend>
        <label class="mt-1 flex items-center gap-2 text-sm text-textSecondary">
            <input type="checkbox" name="delivery_channels[]" value="in_app" @checked($inAppChecked)>
            <span>In-app</span>
        </label>
        <label class="mt-1 flex items-center gap-2 text-sm text-textSecondary">
            <input type="checkbox" name="delivery_channels[]" value="email" @checked($emailChecked)>
            <span>Email placeholder</span>
        </label>
    </fieldset>
    <label class="flex items-center gap-2 self-end rounded border border-border px-3 py-2 text-sm text-textSecondary">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $briefing?->is_active ?? true))>
        <span>Active</span>
    </label>
</div>
