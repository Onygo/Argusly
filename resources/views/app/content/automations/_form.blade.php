@php
    $isEdit = $automation->exists;
    $selectedWorkspace = old('workspace_id', (string) ($automation->workspace_id ?? $selectedWorkspaceId ?? ''));
    $defaultLocale = old('source_locale', old('locale', $automation->sourceLocale() ?? ($workspaces->first()?->defaultContentLanguageCode() ?? 'en')));
    $selectedLocales = collect(old('target_locales', old('locales', (array) ($automation->locales ?? [$defaultLocale]))))
        ->filter()
        ->values()
        ->all();
@endphp

<div class="grid gap-6 lg:grid-cols-2">
    <div class="space-y-4 rounded-lg border border-border bg-surface p-4">
        <div>
            <h2 class="text-sm font-semibold text-textPrimary">Core settings</h2>
            <p class="mt-1 text-xs text-textSecondary">Define scope, cadence, output mode, and where runs should land.</p>
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Name</label>
            <input type="text" name="name" value="{{ old('name', $automation->name) }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="191" required>
            @error('name')
                <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
            @enderror
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Workspace</label>
                <select name="workspace_id" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required>
                    @foreach ($workspaces as $workspace)
                        <option value="{{ $workspace->id }}" @selected($selectedWorkspace === (string) $workspace->id)>{{ $workspace->name }}</option>
                    @endforeach
                </select>
                @error('workspace_id')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary">Client site</label>
                <select name="client_site_id" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
                    <option value="">Workspace wide</option>
                    @foreach ($sites as $site)
                        <option value="{{ $site->id }}" @selected((string) old('client_site_id', $automation->client_site_id) === (string) $site->id)>{{ $site->name }}</option>
                    @endforeach
                </select>
                @error('client_site_id')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Mode</label>
                <select name="mode" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required>
                    @foreach (\App\Enums\ContentAutomationMode::cases() as $mode)
                        <option value="{{ $mode->value }}" @selected(old('mode', $automation->mode?->value ?? $automation->mode) === $mode->value)>{{ $mode->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary">Chain size</label>
                <input type="number" min="1" max="10" name="chain_size" value="{{ old('chain_size', $automation->chain_size ?: 5) }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required>
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary">Publication</label>
                <select name="publication_mode" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required>
                    @foreach (\App\Enums\ContentAutomationPublicationMode::cases() as $mode)
                        <option value="{{ $mode->value }}" @selected(old('publication_mode', $automation->publication_mode?->value ?? $automation->publication_mode) === $mode->value)>{{ $mode->label() }}</option>
                    @endforeach
                </select>
                @error('publication_mode')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Every</label>
                <input type="number" min="1" max="90" name="generation_frequency_value" value="{{ old('generation_frequency_value', $automation->generation_frequency_value ?: 3) }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required>
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary">Unit</label>
                <select name="generation_frequency_unit" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required>
                    @foreach (\App\Enums\ContentAutomationFrequencyUnit::cases() as $unit)
                        <option value="{{ $unit->value }}" @selected(old('generation_frequency_unit', $automation->generation_frequency_unit?->value ?? $automation->generation_frequency_unit) === $unit->value)>{{ $unit->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary">Destination</label>
                <select name="content_destination_id" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
                    <option value="">Use site default</option>
                    @foreach ($destinations as $destination)
                        <option value="{{ $destination->id }}" @selected((string) old('content_destination_id', $automation->content_destination_id) === (string) $destination->id)>{{ $destination->name }}</option>
                    @endforeach
                </select>
                @error('content_destination_id')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">End date</label>
                <input type="datetime-local" name="end_at" value="{{ old('end_at', optional($automation->end_at)->format('Y-m-d\TH:i')) }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
                @error('end_at')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary">Max runs</label>
                <input type="number" min="1" max="1000" name="max_runs" value="{{ old('max_runs', $automation->max_runs) }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
                @error('max_runs')
                    <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Topic scope</label>
            <textarea name="topic_scope" rows="4" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required>{{ old('topic_scope', $automation->topic_scope) }}</textarea>
            @error('topic_scope')
                <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Content goal</label>
            <textarea name="content_goal" rows="3" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ old('content_goal', $automation->content_goal) }}</textarea>
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Company context override</label>
            <textarea name="company_context_override" rows="3" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ old('company_context_override', $automation->company_context_override) }}</textarea>
        </div>
    </div>

    <div class="space-y-4 rounded-lg border border-border bg-surface p-4">
        <div>
            <h2 class="text-sm font-semibold text-textPrimary">Audience and delivery</h2>
            <p class="mt-1 text-xs text-textSecondary">Attach locale, voice, persona, and supporting editorial context.</p>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Source language</label>
                <select name="source_locale" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" required>
                    @foreach (\App\Enums\SupportedLanguage::cases() as $language)
                        <option value="{{ $language->value }}" @selected($defaultLocale === $language->value)>{{ $language->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary">Preferred length</label>
                <select name="preferred_length" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
                    <option value="">Default</option>
                    @foreach (['short', 'medium', 'long', 'pillar'] as $length)
                        <option value="{{ $length }}" @selected(old('preferred_length', data_get($automation->settings, 'preferred_length')) === $length)>{{ ucfirst($length) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Target languages</label>
            <div class="grid gap-2 md:grid-cols-2">
                @foreach (\App\Enums\SupportedLanguage::cases() as $language)
                    <label class="flex items-center gap-2 rounded border border-border bg-background px-3 py-2 text-sm">
                        <input type="checkbox" name="target_locales[]" value="{{ $language->value }}" @checked(in_array($language->value, $selectedLocales, true))>
                        <span>{{ $language->label() }}</span>
                    </label>
                @endforeach
            </div>
            @error('locales')
                <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
            @enderror
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Brand voice</label>
                <select name="use_brand_voice_id" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
                    <option value="">Automatic / none</option>
                    @foreach ($brandVoices as $voice)
                        <option value="{{ $voice->id }}" @selected((string) old('use_brand_voice_id', $automation->use_brand_voice_id) === (string) $voice->id)>{{ $voice->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary">Team persona</label>
                <select name="use_team_persona_id" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
                    <option value="">None</option>
                    @foreach ($teamPersonas as $persona)
                        <option value="{{ $persona->id }}" @selected((string) old('use_team_persona_id', $automation->use_team_persona_id) === (string) $persona->id)>{{ $persona->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs text-textSecondary">Buyer persona</label>
                <select name="use_buyer_persona_id" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">
                    <option value="">None</option>
                    @foreach ($buyerPersonas as $persona)
                        <option value="{{ $persona->id }}" @selected((string) old('use_buyer_persona_id', $automation->use_buyer_persona_id) === (string) $persona->id)>{{ $persona->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs text-textSecondary">Funnel stage</label>
                <input type="text" name="funnel_stage" value="{{ old('funnel_stage', $automation->funnel_stage) }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="64">
            </div>
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Campaign context</label>
            <input type="text" name="campaign_context" value="{{ old('campaign_context', $automation->campaign_context) }}" class="w-full rounded border border-border bg-background px-3 py-2 text-sm" maxlength="191">
        </div>

        <div>
            <label class="mb-1 block text-xs text-textSecondary">Content pillars</label>
            <textarea name="content_pillars" rows="3" class="w-full rounded border border-border bg-background px-3 py-2 text-sm">{{ old('content_pillars', data_get($automation->settings, 'content_pillars')) }}</textarea>
        </div>

        <div class="space-y-2">
            <label class="flex items-start gap-3 rounded border border-border bg-background px-3 py-2 text-sm">
                <input type="hidden" name="include_internal_linking" value="0">
                <input class="mt-0.5" type="checkbox" name="include_internal_linking" value="1" @checked(old('include_internal_linking', $automation->include_internal_linking ?? true))>
                <span>
                    <span class="block font-medium text-textPrimary">Run internal linking</span>
                    <span class="block text-xs text-textSecondary">Reuse the existing linking agent after generation.</span>
                </span>
            </label>

            <label class="flex items-start gap-3 rounded border border-border bg-background px-3 py-2 text-sm">
                <input type="hidden" name="include_translation" value="0">
                <input class="mt-0.5" type="checkbox" name="include_translation" value="1" @checked(old('include_translation', $automation->include_translation ?? true))>
                <span>
                    <span class="block font-medium text-textPrimary">Auto-translate generated content</span>
                    <span class="block text-xs text-textSecondary">Translate generated source content into the selected target languages.</span>
                </span>
            </label>

            <label class="flex items-start gap-3 rounded border border-border bg-background px-3 py-2 text-sm">
                <input type="hidden" name="auto_publish_translations" value="0">
                <input class="mt-0.5" type="checkbox" name="auto_publish_translations" value="1" @checked(old('auto_publish_translations', $automation->autoPublishTranslationsWithSource()))>
                <span>
                    <span class="block font-medium text-textPrimary">Auto-publish translations with source</span>
                    <span class="block text-xs text-textSecondary">Keep translations on the same publish schedule as the source locale when family sync is enabled.</span>
                </span>
            </label>

            <div class="rounded border border-border bg-background px-3 py-3 text-sm">
                <label class="mb-2 block text-xs text-textSecondary">Publish mode</label>
                <div class="grid gap-2 md:grid-cols-2">
                    @foreach (['synced' => 'Synced family publishing', 'independent' => 'Independent'] as $value => $label)
                        <label class="flex items-center gap-2 rounded border border-border px-3 py-2">
                            <input type="radio" name="publish_mode" value="{{ $value }}" @checked(old('publish_mode', $automation->familyPublishMode()) === $value)>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <label class="flex items-start gap-3 rounded border border-border bg-background px-3 py-2 text-sm">
                <input type="hidden" name="avoid_topic_overlap" value="0">
                <input class="mt-0.5" type="checkbox" name="avoid_topic_overlap" value="1" @checked(old('avoid_topic_overlap', $automation->avoid_topic_overlap ?? true))>
                <span>
                    <span class="block font-medium text-textPrimary">Avoid topic overlap</span>
                    <span class="block text-xs text-textSecondary">Bias planning away from recent site topics and duplicate titles.</span>
                </span>
            </label>

            <label class="flex items-start gap-3 rounded border border-border bg-background px-3 py-2 text-sm">
                <input type="hidden" name="generate_structured_answers" value="0">
                <input class="mt-0.5" type="checkbox" name="generate_structured_answers" value="1" @checked(old('generate_structured_answers', data_get($automation->settings, 'generate_structured_answers', true)))>
                <span>
                    <span class="block font-medium text-textPrimary">Generate Structured Answers</span>
                    <span class="block text-xs text-textSecondary">Queue answer blocks after each automation run.</span>
                </span>
            </label>

            <label class="flex items-start gap-3 rounded border border-border bg-background px-3 py-2 text-sm">
                <input type="hidden" name="optimize_for_aeo" value="0">
                <input class="mt-0.5" type="checkbox" name="optimize_for_aeo" value="1" @checked(old('optimize_for_aeo', data_get($automation->settings, 'optimize_for_aeo', true)))>
                <span>
                    <span class="block font-medium text-textPrimary">Optimize for AEO</span>
                    <span class="block text-xs text-textSecondary">Keep AEO scoring active for automation output.</span>
                </span>
            </label>

            <label class="flex items-start gap-3 rounded border border-border bg-background px-3 py-2 text-sm">
                <input type="hidden" name="is_active" value="0">
                <input class="mt-0.5" type="checkbox" name="is_active" value="1" @checked(old('is_active', $automation->is_active ?? true))>
                <span>
                    <span class="block font-medium text-textPrimary">Active</span>
                    <span class="block text-xs text-textSecondary">Inactive automations stay saved but will not be picked up by the scheduler.</span>
                </span>
            </label>
        </div>
    </div>
</div>

<div class="mt-6 flex flex-wrap items-center gap-3">
    <button class="rounded border border-border bg-textPrimary px-4 py-2 text-sm font-medium text-white">{{ $isEdit ? 'Save automation' : 'Create automation' }}</button>
    <a href="{{ $isEdit ? route('app.content.automations.show', $automation) : route('app.content.automations.index', ['workspace' => old('workspace_id', $automation->workspace_id)]) }}" class="rounded border border-border px-4 py-2 text-sm">Cancel</a>
</div>
