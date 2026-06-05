@extends('layouts.app', ['title' => 'Settings'])

@section('content')
    @php
        $workspaceName = $workspace?->display_name ?: ($workspace?->name ?? 'n/a');
        $canManageOrganization = auth()->user()?->can('manage-organization') ?? false;
        $roleLabels = [
            'owner' => 'Owner',
            'admin' => 'Admin',
            'editor' => 'Editor',
            'reviewer' => 'Reviewer',
            'viewer' => 'Viewer',
            'member' => 'Member',
        ];
    @endphp

    <script>
        if (window.location.hash === '#api') {
            window.location.replace(@json(route('app.developer.api')));
        }
    </script>

    <div class="space-y-6">
        <header class="space-y-3">
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Settings</h1>
            <p class="text-textSecondary">Manage workspace preferences, team access, and technical configuration.</p>
            <div class="flex flex-wrap items-center gap-2 text-xs text-textSecondary">
                <span class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1">Workspace: {{ $workspaceName }}</span>
                <span class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1">Organization: {{ $organization->name }}</span>
            </div>
        </header>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-800">
                <p class="font-medium">Some settings could not be saved.</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <x-settings.section-card
            title="Workspace"
            description="Workspace identity and domain settings used across your organization."
            :context="'Workspace: ' . $workspaceName"
        >
            <div class="grid gap-5 lg:grid-cols-2">
                <div class="rounded-lg border border-border bg-background p-4">
                    <h3 class="text-sm font-semibold text-textPrimary">Workspace identity</h3>
                    <p class="mt-1 text-xs text-textSecondary">This name appears in navigation and collaborator context.</p>

                    @if ($canEditWorkspaceName && $workspace)
                        <form method="POST" action="{{ route('app.settings.workspace-name.update') }}" class="mt-4 space-y-3">
                            @csrf
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary" for="workspace_display_name">Workspace name</label>
                                <input
                                    id="workspace_display_name"
                                    name="display_name"
                                    value="{{ old('display_name', $workspace->display_name) }}"
                                    class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm"
                                    maxlength="120"
                                    required
                                >
                                @error('display_name')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                            </div>
                            <x-settings.form-actions>
                                <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Save workspace name</button>
                            </x-settings.form-actions>
                        </form>
                    @elseif($workspace)
                        <div class="mt-4 rounded-md border border-border bg-surfaceSubtle px-3 py-2 text-sm text-textPrimary">
                            {{ $workspace->display_name }}
                        </div>
                        <p class="mt-2 text-xs text-textSecondary">Only users with workspace naming permission can edit this value.</p>
                    @else
                        <p class="mt-4 text-sm text-textSecondary">No workspace found for this organization.</p>
                    @endif
                </div>

                <div class="rounded-lg border border-border bg-background p-4">
                    <h3 class="text-sm font-semibold text-textPrimary">Organization and domain</h3>
                    <p class="mt-1 text-xs text-textSecondary">Set the company identity and optional custom domain.</p>

                    @if ($canManageOrganization)
                        <form method="POST" action="{{ route('app.settings.organization') }}" class="mt-4 space-y-3">
                            @csrf
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary" for="org_name">Organization name</label>
                                <input id="org_name" name="name" value="{{ old('name', $organization->name) }}" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" required>
                                @error('name')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                            </div>

                            <div>
                                <label class="mb-1 block text-xs text-textSecondary" for="custom_domain">Custom domain</label>
                                <input id="custom_domain" name="custom_domain" value="{{ old('custom_domain', $organization->custom_domain) }}" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" placeholder="app.example.com">
                                <p class="mt-1 text-xs text-textSecondary">Optional. Used when your workspace is mapped to a dedicated domain.</p>
                                @error('custom_domain')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                            </div>

                            <div class="rounded-md border border-border bg-surfaceSubtle px-3 py-2">
                                <label class="mb-1 block text-xs text-textSecondary" for="org_slug">Technical identifier</label>
                                <input id="org_slug" value="{{ $organization->slug }}" readonly class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-textSecondary">
                                <p class="mt-1 text-xs text-textSecondary">Used internally in URLs and system references.</p>
                            </div>

                            <x-settings.form-actions>
                                <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Save organization settings</button>
                            </x-settings.form-actions>
                        </form>
                    @else
                        <div class="mt-4 space-y-2 text-sm">
                            <div class="rounded-md border border-border bg-surfaceSubtle px-3 py-2">
                                <p class="text-xs text-textSecondary">Organization name</p>
                                <p class="text-textPrimary">{{ $organization->name }}</p>
                            </div>
                            <div class="rounded-md border border-border bg-surfaceSubtle px-3 py-2">
                                <p class="text-xs text-textSecondary">Custom domain</p>
                                <p class="text-textPrimary">{{ $organization->custom_domain ?: 'Not set' }}</p>
                            </div>
                        </div>
                        <p class="mt-2 text-xs text-textSecondary">Read-only. Workspace admins can update organization settings.</p>
                    @endif
                </div>
            </div>
        </x-settings.section-card>

        <x-settings.section-card
            title="Integrations"
            description="Connect channels used for approved distribution workflows."
        >
            <div class="rounded-lg border border-border bg-background p-4">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 items-center justify-center rounded-md border border-border bg-surface">
                            <i data-lucide="linkedin" class="h-4 w-4 text-textSecondary"></i>
                        </span>
                        <div>
                            <h3 class="text-sm font-semibold text-textPrimary">LinkedIn</h3>
                            <p class="mt-1 text-xs text-textSecondary">Personal profile posting for reviewed text and article shares.</p>
                        </div>
                    </div>
                    <a href="{{ route('app.settings.integrations.linkedin') }}" class="pl-btn-secondary">
                        <i data-lucide="settings" class="h-4 w-4"></i>
                        <span>Manage</span>
                    </a>
                </div>
            </div>
        </x-settings.section-card>

        <x-settings.section-card
            title="Languages"
            description="Keep UI locale selection separate from the languages your workspace can create and translate content in."
        >
            <div class="grid gap-5 lg:grid-cols-2">
                <div class="rounded-lg border border-border bg-background p-4">
                    <h3 class="text-sm font-semibold text-textPrimary">Content languages</h3>
                    <p class="mt-1 text-xs text-textSecondary">English is the platform fallback. Content language controls drafts, SEO, and publish destinations, not the UI copy.</p>

                    @if ($canEditWorkspaceName && $workspace)
                        <form method="POST" action="{{ route('app.settings.workspace-languages.update') }}" class="mt-4 space-y-4">
                            @csrf
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary" for="default_content_language">Default content language</label>
                                <select id="default_content_language" name="default_content_language" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                    @foreach (($contentLanguageOptions ?? []) as $languageOption)
                                        <option value="{{ $languageOption['value'] }}" @selected(old('default_content_language', $workspace->default_content_language->value) === $languageOption['value'])>
                                            {{ $languageOption['englishLabel'] }} ({{ strtoupper($languageOption['value']) }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <fieldset>
                                <legend class="mb-2 text-xs text-textSecondary">Enabled content languages</legend>
                                <div class="grid gap-2 md:grid-cols-2">
                                    @foreach (($contentLanguageOptions ?? []) as $languageOption)
                                        @php
                                            $enabledLanguages = old('enabled_content_languages', $workspace->enabled_content_languages ?? []);
                                        @endphp
                                        <label class="flex items-start gap-3 rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                            <input
                                                class="mt-0.5"
                                                type="checkbox"
                                                name="enabled_content_languages[]"
                                                value="{{ $languageOption['value'] }}"
                                                @checked(in_array($languageOption['value'], $enabledLanguages, true))
                                            >
                                            <span>
                                                <span class="block font-medium text-textPrimary">{{ $languageOption['englishLabel'] }}</span>
                                                <span class="block text-xs text-textSecondary">{{ strtoupper($languageOption['value']) }} · {{ $languageOption['label'] }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('enabled_content_languages')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                            </fieldset>

                            <x-settings.form-actions>
                                <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Save content languages</button>
                            </x-settings.form-actions>
                        </form>
                    @else
                        <x-settings.empty-state class="mt-4" title="Read-only language settings" description="Workspace owners can update content language availability." />
                    @endif
                </div>

                <div class="rounded-lg border border-border bg-background p-4">
                    <h3 class="text-sm font-semibold text-textPrimary">UI locale behavior</h3>
                    <div class="mt-3 space-y-3 text-sm text-textSecondary">
                        <p>App and public UI default to English. Dutch browsers resolve to Dutch. Other browser locales fall back to English.</p>
                        <p>Manual EN/NL selection persists separately from content language so editors can work in one UI locale while translating content into another.</p>
                        <div class="rounded-md border border-border bg-surfaceSubtle px-3 py-2 text-xs">
                            Current default content language:
                            <span class="font-medium text-textPrimary">{{ strtoupper($workspace?->default_content_language->value ?? 'EN') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </x-settings.section-card>

        <x-settings.section-card
            title="Content Optimization"
            description="Define the default automation guardrails for recommendations in this workspace."
        >
            <div class="grid gap-5 lg:grid-cols-2">
                <div class="rounded-lg border border-border bg-background p-4">
                    <h3 class="text-sm font-semibold text-textPrimary">Workspace defaults</h3>
                    <p class="mt-1 text-xs text-textSecondary">These settings control safe, assistive automation. Site-level settings can override them.</p>

                    @if ($canEditWorkspaceName && $workspace)
                        <form method="POST" action="{{ route('app.settings.workspace-agent-automation.update') }}" class="mt-4 space-y-3">
                            @csrf
                            @php
                                $automation = $workspaceAutomationSettings ?? [];
                            @endphp
                            <label class="flex items-start gap-3 rounded-md border border-border bg-surface px-3 py-2.5 text-sm">
                                <input class="mt-0.5" type="hidden" name="automatic_recommendation_generation_enabled" value="0">
                                <input class="mt-0.5" type="checkbox" name="automatic_recommendation_generation_enabled" value="1" @checked(old('automatic_recommendation_generation_enabled', $automation['automatic_recommendation_generation_enabled'] ?? true))>
                                <span>
                                    <span class="block font-medium text-textPrimary">Automatic recommendation generation</span>
                                    <span class="block text-xs text-textSecondary">Allow lifecycle events and scheduled scans to generate recommendations automatically.</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3 rounded-md border border-border bg-surface px-3 py-2.5 text-sm">
                                <input class="mt-0.5" type="hidden" name="smart_suggestions_enabled" value="0">
                                <input class="mt-0.5" type="checkbox" name="smart_suggestions_enabled" value="1" @checked(old('smart_suggestions_enabled', $automation['smart_suggestions_enabled'] ?? true))>
                                <span>
                                    <span class="block font-medium text-textPrimary">Smart suggestions</span>
                                    <span class="block text-xs text-textSecondary">Precompute internal-link suggestions automatically when recommendation generation is enabled.</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3 rounded-md border border-border bg-surface px-3 py-2.5 text-sm">
                                <input class="mt-0.5" type="hidden" name="automatic_refresh_draft_creation_enabled" value="0">
                                <input class="mt-0.5" type="checkbox" name="automatic_refresh_draft_creation_enabled" value="1" @checked(old('automatic_refresh_draft_creation_enabled', $automation['automatic_refresh_draft_creation_enabled'] ?? false))>
                                <span>
                                    <span class="block font-medium text-textPrimary">Automatic refresh draft creation</span>
                                    <span class="block text-xs text-textSecondary">Only high-urgency refresh candidates create drafts automatically. Live content is never overwritten.</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3 rounded-md border border-border bg-surface px-3 py-2.5 text-sm">
                                <input class="mt-0.5" type="hidden" name="localization_checks_enabled" value="0">
                                <input class="mt-0.5" type="checkbox" name="localization_checks_enabled" value="1" @checked(old('localization_checks_enabled', $automation['localization_checks_enabled'] ?? true))>
                                <span>
                                    <span class="block font-medium text-textPrimary">Localization checks</span>
                                    <span class="block text-xs text-textSecondary">Run multilingual consistency checks automatically and surface the recommendations for review.</span>
                                </span>
                            </label>

                            <x-settings.form-actions>
                                <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Save automation defaults</button>
                            </x-settings.form-actions>
                        </form>
                    @else
                        <x-settings.empty-state class="mt-4" title="Read-only automation settings" description="Workspace owners can update optimization defaults." />
                    @endif
                </div>

                <div class="rounded-lg border border-border bg-background p-4">
                    <h3 class="text-sm font-semibold text-textPrimary">Safety model</h3>
                    <div class="mt-3 space-y-3 text-sm text-textSecondary">
                        <p>Automatic mode only covers low-risk work: recommendations, suggestion generation, and refresh draft creation inside the editorial workflow.</p>
                        <p>Publishing, live overwrites, locale reassignment, and destructive content changes still require explicit approval.</p>
                        <div class="rounded-md border border-border bg-surfaceSubtle px-3 py-2 text-xs">
                            Site-level automation settings live on each site detail page so connector-specific risk can stay bounded.
                        </div>
                    </div>
                </div>
            </div>
        </x-settings.section-card>

        <x-settings.section-card
            title="Agentic Marketing Execution"
            description="Choose how PublishLayer may turn Agentic Marketing opportunities into work for this workspace."
        >
            @php
                $agenticSettings = $agenticExecutionSettings;
                $allowedSiteIds = collect(old('allowed_site_ids', $agenticSettings?->allowed_site_ids ?? []))->map(fn ($id) => (string) $id)->all();
                $allowedDestinationIds = collect(old('allowed_publishing_destination_ids', $agenticSettings?->allowed_publishing_destination_ids ?? []))->map(fn ($id) => (string) $id)->all();
                $currentMode = old('agentic_execution_mode', $agenticSettings?->agentic_execution_mode ?? 'guided');
            @endphp

            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
                <div class="rounded-lg border border-border bg-background p-4">
                    <h3 class="text-sm font-semibold text-textPrimary">Execution mode</h3>
                    <p class="mt-1 text-xs text-textSecondary">Guided mode requires customer approval before execution. Autonomous mode must be explicitly enabled and stays limited to the rules below.</p>

                    @if ($canEditWorkspaceName && $workspace)
                        <form method="POST" action="{{ route('app.settings.agentic-marketing-execution.update') }}" class="mt-4 space-y-4">
                            @csrf

                            <div class="grid gap-3 md:grid-cols-2">
                                <label class="rounded-md border border-border bg-surface px-3 py-3 text-sm">
                                    <span class="flex items-start gap-2">
                                        <input type="radio" name="agentic_execution_mode" value="guided" @checked($currentMode === 'guided')>
                                        <span>
                                            <span class="block font-medium text-textPrimary">Guided</span>
                                            <span class="block text-xs text-textSecondary">Customer approval is required before briefs, plans, actions, or publications execute.</span>
                                        </span>
                                    </span>
                                </label>
                                <label class="rounded-md border border-amber-200 bg-amber-50 px-3 py-3 text-sm">
                                    <span class="flex items-start gap-2">
                                        <input type="radio" name="agentic_execution_mode" value="autonomous" @checked($currentMode === 'autonomous')>
                                        <span>
                                            <span class="block font-medium text-amber-950">Autonomous</span>
                                            <span class="block text-xs text-amber-900">PublishLayer may execute only the selected action types within these limits.</span>
                                        </span>
                                    </span>
                                </label>
                            </div>
                            @error('agentic_execution_mode') <p class="text-xs text-rose-700">{{ $message }}</p> @enderror

                            <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-3 text-sm text-amber-950">
                                <p class="font-medium">Autonomous mode is never enabled by default.</p>
                                <p class="mt-1 text-xs">Only enable it after selecting a publishing site, setting limits, and choosing the exact action types PublishLayer may execute. High-priority, new-page, and external publication approvals remain controlled by the rules below.</p>
                                <label class="mt-3 flex items-start gap-2 text-xs">
                                    <input type="checkbox" name="autonomous_opt_in_confirmation" value="1">
                                    <span>I understand autonomous mode allows PublishLayer to execute selected Agentic Marketing actions automatically within these limits.</span>
                                </label>
                                @error('autonomous_opt_in_confirmation') <p class="mt-2 text-xs text-rose-700">{{ $message }}</p> @enderror
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                @foreach ([
                                    'autonomous_brief_generation_enabled' => ['Brief generation', 'Create approved-scope briefs automatically.'],
                                    'autonomous_chained_plans_enabled' => ['Chained plans', 'Prepare chained content plans automatically.'],
                                    'autonomous_refresh_enabled' => ['Refresh actions', 'Create or apply allowed refresh work.'],
                                    'autonomous_internal_linking_enabled' => ['Internal linking', 'Prepare allowed internal-link changes.'],
                                    'autonomous_publication_enabled' => ['Publication', 'Publish only when all publication rules allow it.'],
                                ] as $field => [$label, $description])
                                    <label class="flex items-start gap-3 rounded-md border border-border bg-surface px-3 py-2.5 text-sm">
                                        <input type="hidden" name="{{ $field }}" value="0">
                                        <input class="mt-0.5" type="checkbox" name="{{ $field }}" value="1" @checked(old($field, (bool) ($agenticSettings?->{$field} ?? false)))>
                                        <span>
                                            <span class="block font-medium text-textPrimary">{{ $label }}</span>
                                            <span class="block text-xs text-textSecondary">{{ $description }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>

                            <div class="grid gap-3 md:grid-cols-3">
                                <label class="space-y-1">
                                    <span class="text-xs text-textSecondary">Max actions per day</span>
                                    <input type="number" min="1" max="100" name="max_autonomous_actions_per_day" value="{{ old('max_autonomous_actions_per_day', $agenticSettings?->max_autonomous_actions_per_day ?? 3) }}" class="pl-input w-full">
                                    @error('max_autonomous_actions_per_day') <span class="text-xs text-rose-700">{{ $message }}</span> @enderror
                                </label>
                                <label class="space-y-1">
                                    <span class="text-xs text-textSecondary">Max credits per month</span>
                                    <input type="number" min="1" max="1000000" name="max_autonomous_credits_per_month" value="{{ old('max_autonomous_credits_per_month', $agenticSettings?->max_autonomous_credits_per_month ?? 100) }}" class="pl-input w-full">
                                    @error('max_autonomous_credits_per_month') <span class="text-xs text-rose-700">{{ $message }}</span> @enderror
                                </label>
                                <label class="space-y-1">
                                    <span class="text-xs text-textSecondary">Require approval above score</span>
                                    <input type="number" min="0" max="100" name="require_approval_above_priority_score" value="{{ old('require_approval_above_priority_score', $agenticSettings?->require_approval_above_priority_score ?? 80) }}" class="pl-input w-full">
                                    @error('require_approval_above_priority_score') <span class="text-xs text-rose-700">{{ $message }}</span> @enderror
                                </label>
                            </div>

                            <div class="grid gap-3 md:grid-cols-3">
                                @foreach ([
                                    'require_approval_for_new_pages' => 'Require approval for new pages',
                                    'require_approval_for_external_publication' => 'Require approval for external publication',
                                    'notification_email_enabled' => 'Email autonomous-action notifications',
                                ] as $field => $label)
                                    <label class="flex items-start gap-3 rounded-md border border-border bg-surface px-3 py-2.5 text-sm">
                                        <input type="hidden" name="{{ $field }}" value="0">
                                        <input class="mt-0.5" type="checkbox" name="{{ $field }}" value="1" @checked(old($field, (bool) ($agenticSettings?->{$field} ?? true)))>
                                        <span class="font-medium text-textPrimary">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <p class="text-xs font-medium text-textPrimary">Allowed publishing sites</p>
                                    <div class="mt-2 space-y-2">
                                        @forelse ($agenticExecutionSites as $site)
                                            <label class="flex items-start gap-3 rounded-md border border-border bg-surface px-3 py-2.5 text-sm">
                                                <input class="mt-0.5" type="checkbox" name="allowed_site_ids[]" value="{{ $site->id }}" @checked(in_array((string) $site->id, $allowedSiteIds, true))>
                                                <span>
                                                    <span class="block font-medium text-textPrimary">{{ $site->name }}</span>
                                                    <span class="block text-xs text-textSecondary">{{ $site->site_url }} · {{ $site->type }}</span>
                                                </span>
                                            </label>
                                        @empty
                                            <p class="rounded-md border border-border bg-surface px-3 py-2 text-xs text-textSecondary">Connect an active site before enabling autonomous mode.</p>
                                        @endforelse
                                    </div>
                                    @error('allowed_site_ids') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <p class="text-xs font-medium text-textPrimary">Allowed publishing destinations</p>
                                    <div class="mt-2 space-y-2">
                                        @forelse ($agenticExecutionDestinations as $destination)
                                            <label class="flex items-start gap-3 rounded-md border border-border bg-surface px-3 py-2.5 text-sm">
                                                <input class="mt-0.5" type="checkbox" name="allowed_publishing_destination_ids[]" value="{{ $destination->id }}" @checked(in_array((string) $destination->id, $allowedDestinationIds, true))>
                                                <span>
                                                    <span class="block font-medium text-textPrimary">{{ $destination->name }}</span>
                                                    <span class="block text-xs text-textSecondary">{{ $destination->typeLabel() }}</span>
                                                </span>
                                            </label>
                                        @empty
                                            <p class="rounded-md border border-border bg-surface px-3 py-2 text-xs text-textSecondary">Optional. Site selection is still required for autonomous mode.</p>
                                        @endforelse
                                    </div>
                                    @error('allowed_publishing_destination_ids') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <x-settings.form-actions>
                                <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Save Agentic Marketing execution settings</button>
                            </x-settings.form-actions>
                        </form>
                    @else
                        <x-settings.empty-state class="mt-4" title="Read-only execution settings" description="Workspace owners can update Agentic Marketing execution mode." />
                    @endif
                </div>

                <div class="rounded-lg border border-border bg-background p-4">
                    <h3 class="text-sm font-semibold text-textPrimary">Current guardrail state</h3>
                    <div class="mt-3 space-y-3 text-sm text-textSecondary">
                        <div class="rounded-md border border-border bg-surface px-3 py-2">
                            <span class="block text-xs text-textSecondary">Mode</span>
                            <span class="font-medium text-textPrimary">{{ ucfirst((string) ($agenticSettings?->agentic_execution_mode ?? 'guided')) }}</span>
                        </div>
                        <div class="rounded-md border border-border bg-surface px-3 py-2">
                            <span class="block text-xs text-textSecondary">Allowed sites</span>
                            <span class="font-medium text-textPrimary">{{ count($allowedSiteIds) }}</span>
                        </div>
                        <div class="rounded-md border border-border bg-surface px-3 py-2">
                            <span class="block text-xs text-textSecondary">Last autonomous action</span>
                            <span class="font-medium text-textPrimary">{{ optional($agenticSettings?->last_autonomous_action_at)->format('Y-m-d H:i') ?? 'Never' }}</span>
                        </div>
                        <p>Guided mode remains the fallback whenever autonomous rules are missing, invalid, over budget, above priority threshold, or outside the allowed site list.</p>
                    </div>
                </div>
            </div>
        </x-settings.section-card>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-settings.section-card title="Notifications" description="Control workspace-level email updates and weekly summaries.">
                @if ($canManageOrganization)
                    <form method="POST" action="{{ route('app.settings.notifications') }}" class="space-y-3">
                        @csrf
                        <fieldset class="space-y-2">
                            <legend class="sr-only">Notification preferences</legend>
                            <label class="flex items-start gap-3 rounded-md border border-border bg-background px-3 py-2.5 text-sm">
                                <input class="mt-0.5" type="checkbox" name="brief_updates" value="1" {{ ($notificationSettings['brief_updates'] ?? true) ? 'checked' : '' }}>
                                <span>
                                    <span class="block font-medium text-textPrimary">Brief updates</span>
                                    <span class="block text-xs text-textSecondary">Receive updates when brief progress or status changes.</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3 rounded-md border border-border bg-background px-3 py-2.5 text-sm">
                                <input class="mt-0.5" type="checkbox" name="draft_ready" value="1" {{ ($notificationSettings['draft_ready'] ?? true) ? 'checked' : '' }}>
                                <span>
                                    <span class="block font-medium text-textPrimary">Draft ready notifications</span>
                                    <span class="block text-xs text-textSecondary">Get notified when generated drafts are available for review.</span>
                                </span>
                            </label>
                            <label class="flex items-start gap-3 rounded-md border border-border bg-background px-3 py-2.5 text-sm">
                                <input class="mt-0.5" type="checkbox" name="weekly_summary" value="1" {{ ($notificationSettings['weekly_summary'] ?? true) ? 'checked' : '' }}>
                                <span>
                                    <span class="block font-medium text-textPrimary">Weekly summary</span>
                                    <span class="block text-xs text-textSecondary">Receive a weekly digest for workspace activity and outcomes.</span>
                                </span>
                            </label>
                        </fieldset>

                        <x-settings.form-actions>
                            <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Save notifications</button>
                        </x-settings.form-actions>
                    </form>
                @else
                    <x-settings.empty-state title="Read-only notifications" description="Workspace admins can update notification preferences." />
                @endif
            </x-settings.section-card>

            <x-settings.section-card title="API access moved" description="API keys, webhooks, and integration settings now live in Developer.">
                <div class="rounded-md border border-border bg-background px-4 py-3">
                    <p class="text-sm text-textPrimary">API keys, webhooks, and integration settings are now managed in the Developer section.</p>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <a href="{{ route('app.developer.api') }}" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Open Developer settings</a>
                        <a href="{{ route('app.developer.webhooks') }}" class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">Webhooks</a>
                        <a href="{{ route('app.developer.docs') }}" class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">Docs</a>
                    </div>
                </div>
            </x-settings.section-card>
        </div>

        <x-settings.section-card title="Brand" description="Manage company profile, voice guidelines, and reusable intelligence proposals.">
            <div class="grid gap-3 md:grid-cols-3">
                <a href="{{ route('app.brand.company-profile') }}" class="group rounded-lg border border-border bg-background px-4 py-3 hover:border-primary/40 hover:bg-surfaceSubtle">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-textPrimary">Company profile</p>
                            <p class="mt-1 text-xs text-textSecondary">Business identity, positioning, and compliance guidance.</p>
                        </div>
                        <i data-lucide="building-2" class="h-4 w-4 text-textSecondary group-hover:text-textPrimary"></i>
                    </div>
                </a>
                <a href="{{ route('app.brand.voices') }}" class="group rounded-lg border border-border bg-background px-4 py-3 hover:border-primary/40 hover:bg-surfaceSubtle">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-textPrimary">Brand voices</p>
                            <p class="mt-1 text-xs text-textSecondary">Tone, terminology, and formatting rules for content generation.</p>
                        </div>
                        <i data-lucide="mic-2" class="h-4 w-4 text-textSecondary group-hover:text-textPrimary"></i>
                    </div>
                </a>
                <a href="{{ route('app.workspace-intelligence.index') }}" class="group rounded-lg border border-border bg-background px-4 py-3 hover:border-primary/40 hover:bg-surfaceSubtle">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-textPrimary">Workspace Intelligence</p>
                            <p class="mt-1 text-xs text-textSecondary">Review AI brand, SEO topic, persona, and team profile proposals.</p>
                        </div>
                        <i data-lucide="sparkles" class="h-4 w-4 text-textSecondary group-hover:text-textPrimary"></i>
                    </div>
                </a>
            </div>
        </x-settings.section-card>

        <x-settings.section-card title="AI Generation" description="Configure visual styles and presets for AI-generated content.">
            <div class="grid gap-3 md:grid-cols-2">
                <a href="{{ route('app.settings.image-presets.index') }}" class="group rounded-lg border border-border bg-background px-4 py-3 hover:border-primary/40 hover:bg-surfaceSubtle">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-textPrimary">Image presets</p>
                            <p class="mt-1 text-xs text-textSecondary">Visual style instructions for AI-generated images.</p>
                        </div>
                        <i data-lucide="image" class="h-4 w-4 text-textSecondary group-hover:text-textPrimary"></i>
                    </div>
                </a>
            </div>
        </x-settings.section-card>

        <x-settings.section-card title="Team" description="Manage member access, invites, and workspace roles.">
            <div class="grid gap-6 xl:grid-cols-2">
                <div class="space-y-4">
                    <div class="rounded-lg border border-border bg-background p-4">
                        <h3 class="text-sm font-semibold text-textPrimary">Invite member</h3>
                        <p class="mt-1 text-xs text-textSecondary">Invite teammates and assign their initial role.</p>

                        @if ($canManageOrganization)
                            <form method="POST" action="{{ route('app.settings.invites') }}" class="mt-4 grid gap-3 md:grid-cols-2">
                                @csrf
                                <div class="md:col-span-2">
                                    <label class="mb-1 block text-xs text-textSecondary" for="invite_email">Email address</label>
                                    <input id="invite_email" name="email" value="{{ old('email') }}" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm" required>
                                    @error('email')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                                    @error('invite')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-textSecondary" for="invite_role">Role</label>
                                    <select id="invite_role" name="role" class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                        <option value="admin" @selected(old('role') === 'admin')>Admin</option>
                                        <option value="editor" @selected(old('role', 'editor') === 'editor')>Editor</option>
                                        <option value="viewer" @selected(old('role') === 'viewer')>Viewer</option>
                                    </select>
                                    @error('role')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                                </div>
                                <div class="flex items-end">
                                    <button class="inline-flex w-full items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Invite member</button>
                                </div>
                            </form>
                        @else
                            <x-settings.empty-state class="mt-4" title="Read-only team management" description="Workspace admins can invite new members." />
                        @endif
                    </div>

                    <div class="rounded-lg border border-border bg-background p-4">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-textPrimary">Pending invites</h3>
                            <span class="text-xs text-textSecondary">{{ $invites->count() }} pending</span>
                        </div>
                        <div class="mt-3 space-y-2">
                            @forelse ($invites as $invite)
                                <div class="rounded-md border border-border px-3 py-2 text-sm">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-medium text-textPrimary">{{ $invite->email }}</span>
                                        <span class="text-xs text-textSecondary">{{ ucfirst($invite->role) }}</span>
                                    </div>
                                    <div class="mt-1 text-xs text-textSecondary">Expires {{ $invite->expires_at?->format('Y-m-d') ?: 'n/a' }}</div>
                                    <div class="mt-1 break-all rounded bg-surfaceSubtle px-2 py-1 font-mono text-[11px] text-textSecondary">{{ route('invite.show', $invite->token) }}</div>
                                </div>
                            @empty
                                <x-settings.empty-state title="No pending invites" description="New invites will appear here until they are accepted." />
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-border bg-background p-4">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-textPrimary">Team members</h3>
                        <span class="text-xs text-textSecondary">{{ $users->count() }} active</span>
                    </div>
                    <div class="mt-3 space-y-2">
                        @forelse ($users as $member)
                            <div class="flex items-center justify-between gap-3 rounded-md border border-border px-3 py-2">
                                <div>
                                    <p class="text-sm font-medium text-textPrimary">{{ $member->name }}</p>
                                    <p class="text-xs text-textSecondary">{{ $member->email }}</p>
                                </div>
                                <div class="text-right">
                                    <span class="inline-flex items-center rounded-full border border-border bg-surfaceSubtle px-2 py-1 text-[11px] font-medium text-textPrimary">{{ $roleLabels[$member->role] ?? ucfirst((string) $member->role) }}</span>
                                </div>
                            </div>
                        @empty
                            <x-settings.empty-state title="No team members" description="Members will appear here after they join your workspace." />
                        @endforelse
                    </div>
                </div>
            </div>
        </x-settings.section-card>
    </div>
@endsection
