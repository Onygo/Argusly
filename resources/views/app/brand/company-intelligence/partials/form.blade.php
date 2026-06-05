@php
    $profile = $profile ?? null;
    $listValue = static fn (string $field): string => implode("\n", (array) old($field, $profile?->{$field} ?? []));
    $textValue = static fn (string $field, mixed $fallback = ''): string => (string) old($field, $profile?->{$field} ?? $fallback);
@endphp

<div class="grid gap-4 lg:grid-cols-3">
    <div>
        <label class="text-sm text-textSecondary">Brand key</label>
        <input name="brand_key" value="{{ $textValue('brand_key', 'primary') }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" required>
    </div>
    <div>
        <label class="text-sm text-textSecondary">Company name</label>
        <input name="company_name" value="{{ $textValue('company_name', $workspace?->organization?->name ?? '') }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm" required>
    </div>
    <div>
        <label class="text-sm text-textSecondary">Market category</label>
        <input name="market_category" value="{{ $textValue('market_category') }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div>
        <label class="text-sm text-textSecondary">Pricing model</label>
        <input name="pricing_model" value="{{ $textValue('pricing_model') }}" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
    </div>
    <div>
        <label class="text-sm text-textSecondary">Brand voice</label>
        <select name="brand_voice_id" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
            <option value="">None</option>
            @foreach ($brandVoices as $voice)
                <option value="{{ $voice->id }}" @selected(old('brand_voice_id', $profile?->brand_voice_id) === $voice->id)>{{ $voice->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <label class="flex items-center gap-2 pt-7 text-sm text-textPrimary">
            <input type="checkbox" name="is_default" value="1" @checked(old('is_default', $profile?->is_default ?? false))>
            Default
        </label>
        <div>
            <label class="text-sm text-textSecondary">Status</label>
            <select name="status" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                <option value="active" @selected($textValue('status', 'active') === 'active')>Active</option>
                <option value="archived" @selected($textValue('status', 'active') === 'archived')>Archived</option>
            </select>
        </div>
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div>
        <label class="text-sm text-textSecondary">Description</label>
        <textarea name="company_description" rows="4" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ $textValue('company_description') }}</textarea>
    </div>
    <div>
        <label class="text-sm text-textSecondary">Positioning</label>
        <textarea name="positioning" rows="4" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ $textValue('positioning') }}</textarea>
    </div>
    <div>
        <label class="text-sm text-textSecondary">UVP</label>
        <textarea name="uvp" rows="4" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ $textValue('uvp') }}</textarea>
    </div>
</div>

@foreach ([
    'Business' => ['products_services' => 'Products/services', 'regions' => 'Regions/countries', 'locales' => 'Languages/locales'],
    'Audience' => ['icps' => 'ICPs', 'personas' => 'Personas', 'buyer_roles' => 'Buyer roles', 'pain_points' => 'Pain points', 'objections' => 'Objections', 'buying_triggers' => 'Buying triggers', 'funnel_stages' => 'Funnel stages'],
    'Brand' => ['banned_phrases' => 'Banned phrases', 'messaging_rules' => 'Messaging rules', 'brand_differentiators' => 'Brand differentiators', 'proof_points' => 'Proof points'],
    'SEO/AEO' => ['primary_topics' => 'Primary topics', 'authority_areas' => 'Authority areas', 'target_entities' => 'Target entities', 'strategic_keywords' => 'Strategic keywords', 'query_intents' => 'Query intents'],
    'Competitors' => ['direct_competitors' => 'Direct competitors', 'indirect_competitors' => 'Indirect competitors', 'aspirational_competitors' => 'Aspirational competitors'],
] as $section => $fields)
    <div>
        <h3 class="mb-2 text-sm font-semibold text-textPrimary">{{ $section }}</h3>
        <div class="grid gap-4 lg:grid-cols-3">
            @foreach ($fields as $field => $label)
                <div>
                    <label class="text-sm text-textSecondary">{{ $label }}</label>
                    <textarea name="{{ $field }}" rows="3" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ $listValue($field) }}</textarea>
                </div>
            @endforeach
        </div>
    </div>
@endforeach

<div>
    <label class="text-sm text-textSecondary">Tone of voice</label>
    <textarea name="tone_of_voice" rows="3" class="mt-1 w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ $textValue('tone_of_voice') }}</textarea>
</div>
