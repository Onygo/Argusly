<?php

namespace App\Http\Controllers\App;

use App\Enums\ContentAutomationMode;
use App\Enums\ContentAutomationPublicationMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\UpsertContentAutomationRequest;
use App\Jobs\ContentAutomation\RunContentAutomationJob;
use App\Models\BrandVoice;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\ContentDestination;
use App\Models\Persona;
use App\Models\TeamMember;
use App\Models\Workspace;
use App\Services\Credits\CreditWarningService;
use App\Support\Errors\AdminAccessChecker;
use App\Support\Errors\AutomationErrorPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AppContentAutomationsController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('manage-organization');
        $this->authorize('viewAny', ContentAutomation::class);

        $organizationId = $this->organizationId($request);
        $workspaces = $this->workspacesForOrganization($organizationId);
        $sites = $this->sitesForWorkspaces($workspaces);

        $workspaceId = trim((string) $request->query('workspace', ''));
        $siteId = trim((string) $request->query('site', ''));

        $automations = ContentAutomation::query()
            ->with([
                'workspace',
                'clientSite',
                'brandVoice',
                'teamPersona',
                'buyerPersona',
                'latestRun.items',
                'runs' => fn ($query) => $query->with('items')->latest()->take(10),
            ])
            ->withCount(['contents' => fn ($query) => $query->where('automation_id', '!=', null)])
            ->where('organization_id', $organizationId)
            ->when($workspaceId !== '', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->when($siteId !== '', fn ($query) => $query->where('client_site_id', $siteId))
            ->latest('created_at')
            ->paginate(15)
            ->withQueryString();

        return view('app.content.automations.index', [
            'automations' => $automations,
            'workspaces' => $workspaces,
            'sites' => $sites,
            'selectedWorkspaceId' => $workspaceId,
            'selectedSiteId' => $siteId,
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('manage-organization');
        $this->authorize('create', ContentAutomation::class);

        $organizationId = $this->organizationId($request);
        $workspaceId = trim((string) old('workspace_id', $request->query('workspace', '')));
        $siteId = trim((string) old('client_site_id', $request->query('site', '')));

        if ($siteId !== '') {
            $site = ClientSite::query()
                ->forOrganization($organizationId)
                ->whereKey($siteId)
                ->first();

            if ($site) {
                $workspaceId = (string) $site->workspace_id;
            }
        }

        $defaults = new ContentAutomation([
            'mode' => ContentAutomationMode::CHAIN->value,
            'publication_mode' => ContentAutomationPublicationMode::DRAFT_ONLY->value,
            'generation_frequency_value' => 3,
            'generation_frequency_unit' => 'days',
            'chain_size' => 5,
            'run_count' => 0,
            'locale' => $this->defaultAutomationLocale($organizationId, $workspaceId),
            'locales' => [$this->defaultAutomationLocale($organizationId, $workspaceId)],
            'workspace_id' => $workspaceId !== '' ? $workspaceId : null,
            'client_site_id' => $siteId !== '' ? $siteId : null,
            'include_internal_linking' => true,
            'include_translation' => true,
            'avoid_topic_overlap' => true,
            'is_active' => true,
            'settings' => [
                'generate_structured_answers' => true,
                'optimize_for_aeo' => true,
                'auto_publish_translations' => true,
                'publish_mode' => 'synced',
            ],
        ]);

        return view('app.content.automations.create', array_merge(
            $this->formLookups($organizationId, $workspaceId),
            ['automation' => $defaults]
        ));
    }

    public function store(UpsertContentAutomationRequest $request): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->authorize('create', ContentAutomation::class);

        $organizationId = $this->organizationId($request);
        $payload = $this->payloadFromRequest($request, $organizationId);
        $automation = ContentAutomation::query()->create($payload);

        return redirect()
            ->route('app.content.automations.show', $automation)
            ->with('status', 'Content automation created.');
    }

    public function show(Request $request, ContentAutomation $automation, CreditWarningService $creditWarnings): View
    {
        Gate::authorize('manage-organization');
        $this->ensureAutomationOwnership($request, $automation);
        $this->authorize('view', $automation);

        $automation->load([
            'workspace',
            'clientSite',
            'contentDestination',
            'brandVoice',
            'teamPersona',
            'buyerPersona',
            'creator',
            'latestRun.items.content.publications',
        ]);

        $runs = $automation->runs()
            ->with('items.content.publications')
            ->limit(12)
            ->get();

        $recentRunIds = $runs->pluck('id')->map(fn ($id): string => (string) $id)->all();

        $recentContents = Content::query()
            ->with('publications')
            ->where('automation_id', (string) $automation->id)
            ->when(
                $recentRunIds !== [],
                fn ($query) => $query->whereIn('automation_run_id', $recentRunIds)
            )
            ->latest('created_at')
            ->limit(12)
            ->get();

        // Build error presenter for the latest failure
        $latestErrorPresenter = null;
        if ($automation->latestRun) {
            $latestFailureItem = collect($automation->latestRun->items ?? [])
                ->filter(fn ($item) => filled($item->last_error_message))
                ->sortByDesc('updated_at')
                ->first();

            if ($latestFailureItem) {
                $latestErrorPresenter = AutomationErrorPresenter::fromRunItem($latestFailureItem);
            } elseif ($automation->latestRun->error_message) {
                $latestErrorPresenter = AutomationErrorPresenter::fromRun($automation->latestRun);
            }
        }

        return view('app.content.automations.show', [
            'automation' => $automation,
            'runs' => $runs,
            'recentContents' => $recentContents,
            'automationCreditEvaluation' => $creditWarnings->evaluateAutomation($automation),
            'latestErrorPresenter' => $latestErrorPresenter,
            'canViewTechnicalDetails' => AdminAccessChecker::canViewTechnicalDetails($request),
        ]);
    }

    public function edit(Request $request, ContentAutomation $automation): View
    {
        Gate::authorize('manage-organization');
        $this->ensureAutomationOwnership($request, $automation);
        $this->authorize('update', $automation);

        return view('app.content.automations.edit', array_merge(
            $this->formLookups((int) $request->user()->organization_id, (string) $automation->workspace_id),
            ['automation' => $automation->loadMissing(['workspace', 'clientSite', 'contentDestination'])]
        ));
    }

    public function update(UpsertContentAutomationRequest $request, ContentAutomation $automation): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->ensureAutomationOwnership($request, $automation);
        $this->authorize('update', $automation);

        $payload = $this->payloadFromRequest($request, $this->organizationId($request), $automation);
        $automation->fill($payload)->save();

        return redirect()
            ->route('app.content.automations.show', $automation)
            ->with('status', 'Content automation updated.');
    }

    public function runNow(Request $request, ContentAutomation $automation): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->ensureAutomationOwnership($request, $automation);
        $this->authorize('run', $automation);

        if (! $automation->isActive()) {
            return back()->withErrors([
                'automation' => 'Automation cannot run because it is paused or completed.',
            ]);
        }

        $creditEvaluation = app(CreditWarningService::class)->evaluateAutomation($automation);
        if (! (bool) ($creditEvaluation['can_run'] ?? false)) {
            throw ValidationException::withMessages([
                'automation' => [
                    (string) ($creditEvaluation['message'] ?? 'This automation does not have enough credits to run.'),
                ],
            ]);
        }

        RunContentAutomationJob::dispatch(
            automationId: (string) $automation->id,
            triggerType: 'manual',
            requestedByUserId: $request->user()->id,
        );

        return back()->with('status', 'Automation run queued.');
    }

    public function pause(Request $request, ContentAutomation $automation): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->ensureAutomationOwnership($request, $automation);
        $this->authorize('update', $automation);

        $automation->updated_by = $request->user()->id;
        $automation->pause();

        return back()->with('status', 'Automation paused.');
    }

    public function resume(Request $request, ContentAutomation $automation): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->ensureAutomationOwnership($request, $automation);
        $this->authorize('update', $automation);

        if ($automation->isCompleted()) {
            return back()->withErrors([
                'automation' => 'Completed automations cannot be resumed.',
            ]);
        }

        $automation->forceFill([
            'is_active' => true,
            'updated_by' => $request->user()->id,
        ]);
        $automation->resume();

        return back()->with('status', 'Automation resumed.');
    }

    public function duplicate(Request $request, ContentAutomation $automation): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->ensureAutomationOwnership($request, $automation);
        $this->authorize('create', ContentAutomation::class);

        $copy = $automation->replicate([
            'created_at',
            'updated_at',
            'last_run_at',
        ]);

        $copy->forceFill([
            'name' => Str::limit((string) $automation->name, 160, '') . ' (copy)',
            'next_run_at' => now(),
            'last_run_at' => null,
            'run_count' => 0,
            'is_paused' => true,
            'paused_at' => now(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ])->save();

        return redirect()
            ->route('app.content.automations.edit', $copy)
            ->with('status', 'Automation duplicated as a paused copy.');
    }

    public function destroy(Request $request, ContentAutomation $automation): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->ensureAutomationOwnership($request, $automation);
        $this->authorize('delete', $automation);

        $automation->delete();

        return redirect()
            ->route('app.content.automations.index', [
                'workspace' => $automation->workspace_id,
                'site' => $automation->client_site_id,
            ])
            ->with('status', 'Automation deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFromRequest(
        UpsertContentAutomationRequest $request,
        int $organizationId,
        ?ContentAutomation $automation = null,
    ): array {
        $validated = $request->validated();
        $workspace = $this->resolveWorkspace((string) $validated['workspace_id'], $organizationId);
        $site = $this->resolveSite($validated['client_site_id'] ?? null, $workspace?->id);
        $destination = $this->resolveDestination($validated['content_destination_id'] ?? null, $workspace?->id);
        $brandVoice = $this->resolveBrandVoice($validated['use_brand_voice_id'] ?? null, $workspace?->id);
        $teamPersona = $this->resolveTeamPersona($validated['use_team_persona_id'] ?? null, $organizationId);
        $buyerPersona = $this->resolveBuyerPersona($validated['use_buyer_persona_id'] ?? null, $organizationId);

        if (($validated['publication_mode'] ?? null) === ContentAutomationPublicationMode::AUTO_PUBLISH->value
            && ! $site
            && ! $destination) {
            throw ValidationException::withMessages([
                'publication_mode' => 'Auto publish requires a client site or content destination.',
            ]);
        }

        $settings = is_array($automation?->settings) ? $automation->settings : [];

        foreach ([
            'preferred_length',
            'content_pillars',
            'generate_structured_answers',
            'optimize_for_aeo',
            'publish_mode',
            'auto_publish_translations',
        ] as $key) {
            $value = $validated[$key] ?? null;

            if ($value === null || $value === '') {
                unset($settings[$key]);
                continue;
            }

            $settings[$key] = $value;
        }

        $runCount = (int) ($automation?->run_count ?? 0);
        $maxRuns = $validated['max_runs'] ?? null;
        $endAt = $validated['end_at'] ?? null;
        $isCompleted = ($maxRuns !== null && $runCount >= (int) $maxRuns)
            || ($endAt !== null && now()->gt($endAt));

        return [
            'organization_id' => $organizationId,
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => $site?->id,
            'content_destination_id' => $destination?->id,
            'name' => (string) $validated['name'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'mode' => (string) $validated['mode'],
            'publication_mode' => (string) $validated['publication_mode'],
            'generation_frequency_value' => (int) $validated['generation_frequency_value'],
            'generation_frequency_unit' => (string) $validated['generation_frequency_unit'],
            'next_run_at' => $automation?->next_run_at ?? now(),
            'chain_size' => (int) $validated['chain_size'],
            'end_at' => $endAt,
            'max_runs' => $maxRuns,
            'run_count' => $runCount,
            'locale' => (string) $validated['locale'],
            'locales' => array_values((array) ($validated['locales'] ?? [$validated['locale']])),
            'topic_scope' => (string) $validated['topic_scope'],
            'content_goal' => $validated['content_goal'] ?? null,
            'company_context_override' => $validated['company_context_override'] ?? null,
            'use_brand_voice_id' => $brandVoice?->id,
            'use_team_persona_id' => $teamPersona?->id,
            'use_buyer_persona_id' => $buyerPersona?->id,
            'include_internal_linking' => (bool) ($validated['include_internal_linking'] ?? false),
            'include_translation' => (bool) ($validated['include_translation'] ?? false),
            'avoid_topic_overlap' => (bool) ($validated['avoid_topic_overlap'] ?? true),
            'funnel_stage' => $validated['funnel_stage'] ?? null,
            'campaign_context' => $validated['campaign_context'] ?? null,
            'settings' => $settings === [] ? null : $settings,
            'is_paused' => $isCompleted ? true : (bool) ($automation?->is_paused ?? false),
            'paused_at' => $isCompleted
                ? ($automation?->paused_at ?? now())
                : ($automation?->paused_at ?? null),
            'created_by' => $automation?->created_by ?: $request->user()->id,
            'updated_by' => $request->user()->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formLookups(int $organizationId, ?string $workspaceId = null): array
    {
        $workspaces = $this->workspacesForOrganization($organizationId);
        $selectedWorkspaceId = $workspaceId;

        if (($selectedWorkspaceId === null || $selectedWorkspaceId === '') && $workspaces->isNotEmpty()) {
            $selectedWorkspaceId = (string) $workspaces->first()->id;
        }

        $sites = ClientSite::query()
            ->whereIn('workspace_id', $workspaces->pluck('id'))
            ->orderBy('name')
            ->get();

        return [
            'workspaces' => $workspaces,
            'sites' => $sites,
            'destinations' => $selectedWorkspaceId
                ? ContentDestination::query()->where('workspace_id', $selectedWorkspaceId)->orderBy('name')->get()
                : collect(),
            'brandVoices' => $selectedWorkspaceId
                ? BrandVoice::query()->where('workspace_id', $selectedWorkspaceId)->orderBy('name')->get()
                : collect(),
            'teamPersonas' => TeamMember::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'buyerPersonas' => Persona::query()
                ->where('organization_id', $organizationId)
                ->orderBy('name')
                ->get(),
            'selectedWorkspaceId' => $selectedWorkspaceId,
        ];
    }

    /**
     * @return Collection<int, Workspace>
     */
    private function workspacesForOrganization(int $organizationId): Collection
    {
        return Workspace::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get();
    }

    private function defaultAutomationLocale(int $organizationId, ?string $workspaceId = null): string
    {
        if ($workspaceId) {
            $workspace = Workspace::query()
                ->where('organization_id', $organizationId)
                ->whereKey($workspaceId)
                ->first();

            if ($workspace) {
                return $workspace->defaultContentLanguageCode();
            }
        }

        $workspace = Workspace::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->first();

        return $workspace?->defaultContentLanguageCode() ?? 'en';
    }

    /**
     * @param  Collection<int, Workspace>  $workspaces
     * @return Collection<int, ClientSite>
     */
    private function sitesForWorkspaces(Collection $workspaces): Collection
    {
        if ($workspaces->isEmpty()) {
            return collect();
        }

        return ClientSite::query()
            ->whereIn('workspace_id', $workspaces->pluck('id'))
            ->orderBy('name')
            ->get();
    }

    private function organizationId(Request $request): int
    {
        $organizationId = (int) $request->user()->organization_id;

        if (! $organizationId) {
            abort(403);
        }

        return $organizationId;
    }

    private function ensureAutomationOwnership(Request $request, ContentAutomation $automation): void
    {
        if ((int) $automation->organization_id !== $this->organizationId($request)) {
            abort(404);
        }
    }

    private function resolveWorkspace(string $workspaceId, int $organizationId): Workspace
    {
        return Workspace::query()
            ->where('organization_id', $organizationId)
            ->whereKey($workspaceId)
            ->firstOrFail();
    }

    private function resolveSite(?string $siteId, ?string $workspaceId): ?ClientSite
    {
        $siteId = trim((string) $siteId);

        if ($siteId === '') {
            return null;
        }

        $site = ClientSite::query()
            ->whereKey($siteId)
            ->firstOrFail();

        if ($workspaceId !== null && (string) $site->workspace_id !== (string) $workspaceId) {
            throw ValidationException::withMessages([
                'client_site_id' => 'Selected site does not belong to the selected workspace.',
            ]);
        }

        return $site;
    }

    private function resolveDestination(?string $destinationId, ?string $workspaceId): ?ContentDestination
    {
        $destinationId = trim((string) $destinationId);

        if ($destinationId === '') {
            return null;
        }

        $destination = ContentDestination::query()
            ->whereKey($destinationId)
            ->firstOrFail();

        if ($workspaceId !== null && (string) $destination->workspace_id !== (string) $workspaceId) {
            throw ValidationException::withMessages([
                'content_destination_id' => 'Selected destination does not belong to the selected workspace.',
            ]);
        }

        return $destination;
    }

    private function resolveBrandVoice(?string $brandVoiceId, ?string $workspaceId): ?BrandVoice
    {
        $brandVoiceId = trim((string) $brandVoiceId);

        if ($brandVoiceId === '') {
            return null;
        }

        $brandVoice = BrandVoice::query()
            ->whereKey($brandVoiceId)
            ->firstOrFail();

        if ($workspaceId !== null && (string) $brandVoice->workspace_id !== (string) $workspaceId) {
            throw ValidationException::withMessages([
                'use_brand_voice_id' => 'Selected brand voice does not belong to the selected workspace.',
            ]);
        }

        return $brandVoice;
    }

    private function resolveTeamPersona(?int $teamPersonaId, int $organizationId): ?TeamMember
    {
        if (! $teamPersonaId) {
            return null;
        }

        return TeamMember::query()
            ->where('organization_id', $organizationId)
            ->whereKey($teamPersonaId)
            ->firstOrFail();
    }

    private function resolveBuyerPersona(?int $buyerPersonaId, int $organizationId): ?Persona
    {
        if (! $buyerPersonaId) {
            return null;
        }

        return Persona::query()
            ->where('organization_id', $organizationId)
            ->whereKey($buyerPersonaId)
            ->firstOrFail();
    }
}
