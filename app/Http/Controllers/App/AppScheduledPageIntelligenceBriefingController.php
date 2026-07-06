<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ClientSite;
use App\Models\MarketPackInstallation;
use App\Models\ScheduledPageIntelligenceBriefing;
use App\Models\Workspace;
use App\Services\PageIntelligence\Reports\ReportBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AppScheduledPageIntelligenceBriefingController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()?->can('viewAny', ScheduledPageIntelligenceBriefing::class), 403);

        $workspace = $this->resolveWorkspace($request);
        $briefings = ScheduledPageIntelligenceBriefing::query()
            ->where('workspace_id', $workspace->id)
            ->with(['clientSite:id,name', 'generatedReports:id,scheduled_page_intelligence_briefing_id,title,generated_at'])
            ->orderByDesc('is_active')
            ->orderBy('next_run_at')
            ->paginate(15)
            ->withQueryString();

        return view('app.page-intelligence.scheduled-briefings.index', [
            'workspace' => $workspace,
            'workspaces' => $this->availableWorkspaces($request),
            'briefings' => $briefings,
            'reportTypes' => ReportBuilder::reportTypes(),
            'marketPacks' => $this->marketPacks($workspace),
            'clientSites' => $this->clientSites($workspace),
            'timezones' => $this->timezones(),
            'daysOfWeek' => $this->daysOfWeek(),
        ]);
    }

    public function edit(Request $request, ScheduledPageIntelligenceBriefing $briefing): View
    {
        abort_unless($request->user()?->can('update', $briefing), 403);

        $workspace = $briefing->workspace()->firstOrFail();
        $briefing->load([
            'deliveries.report:id,title,generated_at,artifact_status',
            'deliveries.recipientUser:id,name,email',
        ]);

        return view('app.page-intelligence.scheduled-briefings.edit', [
            'briefing' => $briefing,
            'workspace' => $workspace,
            'workspaces' => $this->availableWorkspaces($request),
            'reportTypes' => ReportBuilder::reportTypes(),
            'marketPacks' => $this->marketPacks($workspace),
            'clientSites' => $this->clientSites($workspace),
            'timezones' => $this->timezones(),
            'daysOfWeek' => $this->daysOfWeek(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('create', ScheduledPageIntelligenceBriefing::class), 403);

        $workspace = $this->resolveWorkspace($request, $request->input('workspace'));
        $data = $this->validatedData($request, $workspace);
        $briefing = new ScheduledPageIntelligenceBriefing($data + [
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()?->id,
            'delivery_state_json' => ['status' => 'not_delivered', 'delivery_enabled' => false, 'email_sent' => false],
        ]);
        $briefing->next_run_at = $briefing->calculateNextRunAt();
        $briefing->save();

        return redirect()
            ->route('app.page-intelligence.scheduled-briefings.index', ['workspace' => $workspace->id])
            ->with('status', 'Scheduled briefing created.');
    }

    public function update(Request $request, ScheduledPageIntelligenceBriefing $briefing): RedirectResponse
    {
        abort_unless($request->user()?->can('update', $briefing), 403);

        $workspace = $briefing->workspace()->firstOrFail();
        $data = $this->validatedData($request, $workspace);
        $briefing->forceFill($data);
        $briefing->next_run_at = $briefing->calculateNextRunAt();
        $briefing->save();

        return redirect()
            ->route('app.page-intelligence.scheduled-briefings.index', ['workspace' => $workspace->id])
            ->with('status', 'Scheduled briefing updated.');
    }

    public function activate(Request $request, ScheduledPageIntelligenceBriefing $briefing): RedirectResponse
    {
        abort_unless($request->user()?->can('update', $briefing), 403);

        $briefing->forceFill([
            'is_active' => true,
            'next_run_at' => $briefing->calculateNextRunAt(),
        ])->save();

        return back()->with('status', 'Scheduled briefing activated.');
    }

    public function deactivate(Request $request, ScheduledPageIntelligenceBriefing $briefing): RedirectResponse
    {
        abort_unless($request->user()?->can('update', $briefing), 403);

        $briefing->forceFill(['is_active' => false])->save();

        return back()->with('status', 'Scheduled briefing deactivated.');
    }

    /**
     * @return array<string,mixed>
     */
    private function validatedData(Request $request, Workspace $workspace): array
    {
        $data = $request->validate([
            'workspace' => ['nullable', 'string'],
            'client_site_id' => ['nullable', 'string'],
            'report_type' => ['required', 'string', Rule::in(array_keys(ReportBuilder::reportTypes()))],
            'market_pack_key' => ['nullable', 'string', 'max:120'],
            'frequency' => ['required', 'string', Rule::in([ScheduledPageIntelligenceBriefing::FREQUENCY_WEEKLY, ScheduledPageIntelligenceBriefing::FREQUENCY_MONTHLY])],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'recipients' => ['nullable', 'string', 'max:4000'],
            'delivery_channels' => ['nullable', 'array'],
            'delivery_channels.*' => ['string', 'max:80'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $clientSiteId = trim((string) ($data['client_site_id'] ?? '')) ?: null;
        if ($clientSiteId !== null && ! ClientSite::query()->whereKey($clientSiteId)->where('workspace_id', $workspace->id)->exists()) {
            throw (new AuthorizationException)->asNotFound();
        }

        $marketPackKey = trim((string) ($data['market_pack_key'] ?? '')) ?: null;
        if ($marketPackKey !== null && ! $this->marketPackInstalled($workspace, $marketPackKey, $clientSiteId)) {
            throw ValidationException::withMessages([
                'market_pack_key' => "Market pack [{$marketPackKey}] is not installed for this workspace or site.",
            ]);
        }

        return [
            'client_site_id' => $clientSiteId,
            'report_type' => (string) $data['report_type'],
            'market_pack_key' => $marketPackKey,
            'frequency' => (string) $data['frequency'],
            'day_of_week' => $data['frequency'] === ScheduledPageIntelligenceBriefing::FREQUENCY_WEEKLY ? (int) ($data['day_of_week'] ?? 1) : null,
            'day_of_month' => $data['frequency'] === ScheduledPageIntelligenceBriefing::FREQUENCY_MONTHLY ? (int) ($data['day_of_month'] ?? 1) : null,
            'timezone' => (string) $data['timezone'],
            'recipients_json' => $this->recipients((string) ($data['recipients'] ?? '')),
            'delivery_channels_json' => $this->deliveryChannels((array) ($data['delivery_channels'] ?? [])),
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    private function resolveWorkspace(Request $request, mixed $preferredWorkspaceId = null): Workspace
    {
        $organizationId = $request->user()?->organization_id;
        $query = Workspace::query()->where('organization_id', $organizationId)->orderBy('created_at');
        $workspaceId = $preferredWorkspaceId ?: $request->query('workspace');

        if ($workspaceId) {
            $workspace = (clone $query)->whereKey($workspaceId)->first();
            if (! $workspace) {
                throw new AuthorizationException('Workspace is not available for this user.');
            }

            return $workspace;
        }

        return $query->firstOrFail();
    }

    private function availableWorkspaces(Request $request): Collection
    {
        return Workspace::query()
            ->where('organization_id', $request->user()?->organization_id)
            ->orderBy('name')
            ->get(['id', 'name', 'display_name']);
    }

    private function clientSites(Workspace $workspace): Collection
    {
        return ClientSite::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function marketPacks(Workspace $workspace): Collection
    {
        return MarketPackInstallation::query()
            ->where('workspace_id', $workspace->id)
            ->with('marketPack:id,key,name')
            ->get()
            ->pluck('marketPack')
            ->filter()
            ->unique('key')
            ->values();
    }

    private function marketPackInstalled(Workspace $workspace, string $marketPackKey, ?string $clientSiteId): bool
    {
        return MarketPackInstallation::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', MarketPackInstallation::STATUS_ACTIVE)
            ->when($clientSiteId !== null, function ($query) use ($clientSiteId): void {
                $query->where(function ($site) use ($clientSiteId): void {
                    $site->whereNull('client_site_id')
                        ->orWhere('client_site_id', $clientSiteId);
                });
            })
            ->whereHas('marketPack', fn ($query) => $query->where('key', $marketPackKey))
            ->exists();
    }

    /**
     * @return array<int,string>
     */
    private function recipients(string $value): array
    {
        return collect(preg_split('/[\r\n,]+/', $value) ?: [])
            ->map(fn (string $recipient): string => trim($recipient))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int,mixed> $channels
     * @return array<int,string>
     */
    private function deliveryChannels(array $channels): array
    {
        return collect($channels)
            ->map(fn (mixed $channel): string => trim((string) $channel))
            ->filter()
            ->map(fn (string $channel): string => $channel === 'email' ? 'email_placeholder' : $channel)
            ->filter(fn (string $channel): bool => in_array($channel, ['in_app', 'email_placeholder'], true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function timezones(): array
    {
        $preferred = ['UTC', 'Europe/Amsterdam', 'Europe/London', 'America/New_York', 'America/Los_Angeles'];

        return collect($preferred)
            ->merge(timezone_identifiers_list())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function daysOfWeek(): array
    {
        return [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ];
    }
}
