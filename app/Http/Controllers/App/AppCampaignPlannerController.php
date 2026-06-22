<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Enums\SupportedLanguage;
use App\Models\Campaign;
use App\Models\ClientSite;
use App\Models\Opportunity;
use App\Models\Workspace;
use App\Services\CampaignPlanning\CampaignAssetGenerationService;
use App\Services\CampaignPlanning\CampaignPlannerService;
use App\Services\CreditWalletService;
use App\Support\ContentAssets\ContentAssetTaxonomy;
use App\View\Presenters\CampaignContentAssetPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AppCampaignPlannerController extends Controller
{
    public function index(
        Request $request,
        CampaignAssetGenerationService $generator,
        CreditWalletService $creditWalletService,
    ): View
    {
        $workspace = $this->resolveWorkspace($request);

        $campaigns = Campaign::query()
            ->where('workspace_id', $workspace->id)
            ->withCount(['contents', 'distributionPlans'])
            ->latest('last_planned_at')
            ->limit(12)
            ->get();

        $baseCampaigns = Campaign::query()
            ->where('workspace_id', $workspace->id)
            ->withCount('contents')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        $selectedCampaign = $request->query('campaign')
            ? Campaign::query()
                ->where('workspace_id', $workspace->id)
                ->with(['contents.content.publications', 'contents.distributionPlans.distributionChannel', 'opportunities', 'workspace.clientSites'])
                ->find($request->query('campaign'))
            : $campaigns->first()?->load(['contents.content.publications', 'contents.distributionPlans.distributionChannel', 'opportunities', 'workspace.clientSites']);

        $assetPresenters = collect();
        $campaignAssetCards = collect();
        $campaignAssetSummary = collect();
        if ($selectedCampaign) {
            $assetPresenters = $selectedCampaign->contents
                ->mapWithKeys(fn ($asset): array => [(string) $asset->id => CampaignContentAssetPresenter::for($asset)->toArray()]);

            $campaignAssetCards = $selectedCampaign->contents
                ->sortBy('sequence_order')
                ->filter(function ($asset) use ($assetPresenters, $request): bool {
                    $ux = (array) $assetPresenters->get((string) $asset->id, []);

                    return $this->matchesAssetFilters($ux, $request);
                })
                ->values();

            $campaignAssetSummary = $assetPresenters
                ->groupBy('type')
                ->map(fn ($items, string $type): array => [
                    'type' => $type,
                    'label' => (string) data_get($items->first(), 'type_label', ucfirst($type)),
                    'badge' => (string) data_get($items->first(), 'type_badge', strtoupper($type)),
                    'icon' => (string) data_get($items->first(), 'type_icon', 'box'),
                    'classes' => (string) data_get($items->first(), 'type_badge_classes', 'border-border bg-surfaceSubtle text-textSecondary'),
                    'count' => $items->count(),
                ])
                ->sortBy('label')
                ->values();
        }

        $generationEstimate = null;
        $generationAvailableCredits = null;
        if ($selectedCampaign) {
            $generationEstimate = $generator->estimate($selectedCampaign);

            $generationSite = $this->generationSite($selectedCampaign);
            if ($generationSite) {
                $generationAvailableCredits = $creditWalletService->getAvailableForClientSiteIncludingWorkspacePool((string) $generationSite->id);
            }
        }

        $opportunities = Opportunity::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', ['open', 'reviewing', 'planned'])
            ->orderByDesc('priority_score')
            ->limit(8)
            ->get();

        $enabledCampaignLanguages = $this->enabledCampaignLanguages($workspace);

        return view('app.campaign-planner.index', [
            'workspace' => $workspace,
            'campaigns' => $campaigns,
            'baseCampaigns' => $baseCampaigns,
            'selectedCampaign' => $selectedCampaign,
            'opportunities' => $opportunities,
            'generationEstimate' => $generationEstimate,
            'generationAvailableCredits' => $generationAvailableCredits,
            'enabledCampaignLanguages' => $enabledCampaignLanguages,
            'assetPresenters' => $assetPresenters,
            'campaignAssetCards' => $campaignAssetCards,
            'campaignAssetSummary' => $campaignAssetSummary,
            'assetFilterOptions' => [
                'types' => ContentAssetTaxonomy::typeOptions(),
                'purposes' => ContentAssetTaxonomy::purposeLabels(),
                'workflow_states' => ContentAssetTaxonomy::workflowStateLabels(),
                'publication_states' => ContentAssetTaxonomy::publicationStateLabels(),
                'distribution_states' => ContentAssetTaxonomy::distributionStateLabels(),
            ],
            'activeAssetFilters' => $request->only([
                'asset_type',
                'purpose',
                'workflow_state',
                'publication_state',
                'distribution_state',
            ]),
        ]);
    }

    public function store(Request $request, CampaignPlannerService $planner): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);

        $validated = $request->validate([
            'base_campaign_id' => ['nullable', 'string', Rule::exists('campaigns', 'id')->where('workspace_id', $workspace->id)],
            'topic' => ['nullable', 'string', 'max:180', 'required_without:base_campaign_id'],
            'goals' => ['nullable', 'string', 'max:2000'],
            'audience' => ['nullable', 'string', 'max:500'],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['string', Rule::in($workspace->enabled_content_languages)],
            'start_date' => ['nullable', 'date'],
            'utm_source' => ['nullable', 'string', 'max:120'],
            'utm_medium' => ['nullable', 'string', 'max:120'],
            'utm_campaign' => ['nullable', 'string', 'max:180'],
            'utm_content' => ['nullable', 'string', 'max:180'],
            'utm_term' => ['nullable', 'string', 'max:180'],
        ]);

        $options = [
            'goals' => $validated['goals'] ?? '',
            'audience' => $validated['audience'] ?? '',
            'languages' => $this->normalizeSelectedLanguages($workspace, (array) ($validated['languages'] ?? [])),
            'start_date' => $validated['start_date'] ?? null,
            'owner_user_id' => $request->user()->id,
            'tracking_parameters' => $this->trackingParameters($validated),
        ];

        if (! empty($validated['base_campaign_id'])) {
            $baseCampaign = Campaign::query()
                ->where('workspace_id', $workspace->id)
                ->findOrFail($validated['base_campaign_id']);

            if (filled($validated['topic'] ?? null)) {
                $options['topic'] = $validated['topic'];
            }

            $campaign = $planner->planExistingCampaign($baseCampaign, $options);
        } else {
            $campaign = $planner->plan($workspace, $validated['topic'], $options);
        }

        return redirect()
            ->route('app.agentic-marketing.campaign-planner.index', ['campaign' => $campaign->id])
            ->with('status', ! empty($validated['base_campaign_id'])
                ? 'Campaign plan generated from the selected campaign with approval checkpoints.'
                : 'Campaign plan generated as a draft with approval checkpoints.');
    }

    public function generate(Request $request, Campaign $campaign, CampaignAssetGenerationService $generator): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);
        abort_unless((string) $campaign->workspace_id === (string) $workspace->id, 404);

        $summary = $generator->generate($campaign, $request->user());

        return redirect()
            ->route('app.agentic-marketing.campaign-planner.index', ['campaign' => $campaign->id])
            ->with('status', sprintf(
                'Suggested content queued: %d draft generation job(s), %d social draft(s), %d structured answer block(s), %d skipped.',
                $summary['generated_content'],
                $summary['generated_social'],
                $summary['generated_answer_blocks'],
                $summary['skipped'],
            ));
    }

    private function resolveWorkspace(Request $request): Workspace
    {
        return Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when($request->query('workspace_id'), fn ($query, $id) => $query->where('id', $id))
            ->orderBy('created_at')
            ->firstOrFail();
    }

    /**
     * @param  array<string,mixed>  $ux
     */
    private function matchesAssetFilters(array $ux, Request $request): bool
    {
        foreach (['asset_type' => 'type', 'purpose' => 'purpose', 'workflow_state' => 'workflow_state', 'publication_state' => 'publication_state', 'distribution_state' => 'distribution_state'] as $queryKey => $uxKey) {
            $value = trim((string) $request->query($queryKey, ''));

            if ($value !== '' && (string) data_get($ux, $uxKey) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function generationSite(Campaign $campaign): ?ClientSite
    {
        if ($campaign->client_site_id) {
            return ClientSite::query()->find($campaign->client_site_id);
        }

        return $campaign->workspace?->clientSites
            ->sortByDesc(fn (ClientSite $site): int => $site->is_active ? 1 : 0)
            ->first();
    }

    /**
     * @return list<array{value:string,label:string,is_default:bool}>
     */
    private function enabledCampaignLanguages(Workspace $workspace): array
    {
        $default = $workspace->defaultContentLanguageCode();

        return collect($workspace->getEnabledLanguagesAsEnums())
            ->sortBy(fn (SupportedLanguage $language): int => $language->value === $default ? 0 : 1)
            ->map(fn (SupportedLanguage $language): array => [
                'value' => $language->value,
                'label' => $language->label(),
                'is_default' => $language->value === $default,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $selected
     * @return list<string>
     */
    private function normalizeSelectedLanguages(Workspace $workspace, array $selected): array
    {
        $enabled = $workspace->enabled_content_languages;
        $default = $workspace->defaultContentLanguageCode();
        $languages = collect($selected)
            ->map(fn (mixed $language): string => SupportedLanguage::fromStringOrDefault((string) $language)->value)
            ->filter(fn (string $language): bool => in_array($language, $enabled, true))
            ->prepend($default)
            ->unique()
            ->values()
            ->all();

        return $languages !== [] ? $languages : [$default];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,string>
     */
    private function trackingParameters(array $data): array
    {
        return collect(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'])
            ->mapWithKeys(fn (string $key): array => [$key => trim((string) ($data[$key] ?? ''))])
            ->filter()
            ->all();
    }
}
