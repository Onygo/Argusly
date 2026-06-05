<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateBatchItemBriefJob;
use App\Models\BrandVoice;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentBatch;
use App\Models\ContentBatchItem;
use App\Models\Persona;
use App\Models\TeamMember;
use App\Models\Workspace;
use App\Services\BatchGenerationService;
use App\Services\BrandContext\BrandGenerationDefaultsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppContentBatchesController extends Controller
{
    public function create(
        Request $request,
        BatchGenerationService $batchGenerationService,
        BrandGenerationDefaultsService $brandGenerationDefaults,
    ): View
    {
        $this->authorize('create', Content::class);

        $organizationId = (int) $request->user()->organization_id;
        $workspaces = Workspace::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $workspace = $this->resolveWorkspace($request, $workspaces);
        $sites = ClientSite::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $brandVoices = BrandVoice::query()
            ->where(function ($query) use ($workspace, $organizationId): void {
                $query->where('workspace_id', $workspace->id)
                    ->orWhere('organization_id', $organizationId);
            })
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'is_default']);

        $teamMembers = TeamMember::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role']);
        $buyerPersonas = Persona::query()
            ->where('organization_id', $organizationId)
            ->where('status', Persona::STATUS_APPROVED)
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'profile_data']);
        $generationDefaults = $brandGenerationDefaults->forWorkspace($workspace);

        return view('app.content.batches.create', [
            'workspaces' => $workspaces,
            'workspace' => $workspace,
            'sites' => $sites,
            'brandVoices' => $brandVoices,
            'buyerPersonas' => $buyerPersonas,
            'teamMembers' => $teamMembers,
            'generationDefaults' => $generationDefaults,
            'estimatedCredits' => 0,
            'subkeywordExamples' => [
                'crm migratie checklist|B2B implementatiefocus|commercial',
                'crm data mapping template|Technische aanpak|technical',
                'crm adoptie bij sales teams|Change management|transactional',
            ],
        ]);
    }

    public function store(
        Request $request,
        BatchGenerationService $batchGenerationService,
        BrandGenerationDefaultsService $brandGenerationDefaults,
    ): RedirectResponse
    {
        $this->authorize('create', Content::class);

        $organizationId = (int) $request->user()->organization_id;
        $workspaceIds = Workspace::query()
            ->where('organization_id', $organizationId)
            ->pluck('id')
            ->all();

        $data = $request->validate([
            'workspace_id' => ['required', 'uuid', 'in:' . implode(',', $workspaceIds ?: ['__none__'])],
            'client_site_id' => ['nullable', 'uuid'],
            'main_keyword' => ['required', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:10'],
            'tone' => ['nullable', 'string', 'max:255'],
            'preferred_length' => ['nullable', 'in:short,medium,long,pillar'],
            'audience' => ['nullable', 'string', 'max:255'],
            'output_type' => ['nullable', 'string', 'max:50'],
            'brand_voice_id' => ['nullable', 'uuid'],
            'buyer_persona_id' => ['nullable', 'integer'],
            'team_member_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'subkeywords_text' => ['required', 'string', 'max:5000'],
        ]);

        $workspace = Workspace::query()->whereIn('id', $workspaceIds)->findOrFail($data['workspace_id']);
        $workspace->loadMissing('organization.personas', 'organization.teamMembers', 'brandVoices');
        $generationDefaults = $brandGenerationDefaults->forWorkspace($workspace);
        $site = null;
        if (! empty($data['client_site_id'])) {
            $site = ClientSite::query()
                ->where('workspace_id', $workspace->id)
                ->find($data['client_site_id']);
            if (! $site) {
                return back()->withErrors(['client_site_id' => 'Selected site is not available for this workspace.'])->withInput();
            }
        }

        $selectedBrandVoiceId = $data['brand_voice_id'] ?? $generationDefaults['brand_voice_id'];
        $selectedBuyerPersonaId = $data['buyer_persona_id'] ?? $generationDefaults['buyer_persona_id'];
        $selectedTeamMemberId = $data['team_member_id'] ?? $generationDefaults['team_member_id'];

        // Global organization scopes automatically filter to user's organization
        if (! empty($selectedBrandVoiceId)) {
            $voice = BrandVoice::query()->find($selectedBrandVoiceId);
            if (! $voice) {
                return back()->withErrors(['brand_voice_id' => 'Selected brand voice is not available for this organization.'])->withInput();
            }
        }

        $buyerPersona = null;
        if (! empty($selectedBuyerPersonaId)) {
            $buyerPersona = Persona::query()
                ->where('status', Persona::STATUS_APPROVED)
                ->find($selectedBuyerPersonaId);
            if (! $buyerPersona) {
                return back()->withErrors(['buyer_persona_id' => 'Selected buyer persona is not available for this organization.'])->withInput();
            }
        }

        if (! empty($selectedTeamMemberId)) {
            $member = TeamMember::query()->where('is_active', true)->find($selectedTeamMemberId);
            if (! $member) {
                return back()->withErrors(['team_member_id' => 'Selected team member is not available for this organization.'])->withInput();
            }
        }

        $subkeywords = $batchGenerationService->parseSubkeywordLines((string) $data['subkeywords_text']);
        if (count($subkeywords) < 1) {
            return back()->withErrors(['subkeywords_text' => 'Provide at least one valid subkeyword.'])->withInput();
        }
        if (count($subkeywords) > 10) {
            return back()->withErrors(['subkeywords_text' => 'Maximum 10 subkeywords allowed per batch.'])->withInput();
        }

        $settings = array_filter([
            'language' => $data['language'] ?? null,
            'tone' => $data['tone'] ?? null,
            'preferred_length' => $data['preferred_length'] ?? 'medium',
            'audience' => ($data['audience'] ?? null) ?: ($buyerPersona ? $brandGenerationDefaults->audienceLabel($buyerPersona) : null),
            'output_type' => $data['output_type'] ?? 'kb_article',
            'brand_voice_id' => $selectedBrandVoiceId,
            'buyer_persona_id' => $selectedBuyerPersonaId,
            'team_member_id' => $selectedTeamMemberId,
            'notes' => $data['notes'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $batch = $batchGenerationService->createBatch(
            workspace: $workspace,
            user: $request->user(),
            clientSite: $site,
            mainKeyword: (string) $data['main_keyword'],
            subkeywords: $subkeywords,
            settings: $settings
        );

        return redirect()
            ->route('app.content.batches.show', $batch)
            ->with('status', 'Content batch created.');
    }

    public function suggest(Request $request, BatchGenerationService $batchGenerationService): JsonResponse
    {
        $this->authorize('create', Content::class);

        $data = $request->validate([
            'main_keyword' => ['required', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:10'],
            'subkeywords_text' => ['nullable', 'string', 'max:5000'],
        ]);

        $existing = $batchGenerationService->parseSubkeywordLines((string) ($data['subkeywords_text'] ?? ''));
        $existingKeywords = collect($existing)->pluck('subkeyword')->all();

        $items = $batchGenerationService->suggestSubkeywords(
            mainKeyword: (string) $data['main_keyword'],
            language: (string) ($data['language'] ?? 'nl'),
            existingSubkeywords: $existingKeywords
        );

        $lines = collect($items)
            ->map(fn (array $item) => implode('|', array_filter([
                (string) ($item['subkeyword'] ?? ''),
                (string) ($item['angle'] ?? ''),
                (string) ($item['intent'] ?? ''),
            ], fn ($value) => trim($value) !== '')))
            ->values()
            ->all();

        return response()->json([
            'ok' => true,
            'items' => $items,
            'lines' => $lines,
        ]);
    }

    public function start(ContentBatch $batch, Request $request, BatchGenerationService $batchGenerationService): RedirectResponse
    {
        $this->authorizeBatch($request, $batch);
        $this->authorize('create', Content::class);

        try {
            $batchGenerationService->start($batch);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['batch' => $exception->getMessage()]);
        }

        return back()->with('status', 'Batch started. Items are queued for generation.');
    }

    public function show(ContentBatch $batch, Request $request): View
    {
        $this->authorizeBatch($request, $batch);
        $this->authorize('viewAny', Content::class);

        $batch->load([
            'workspace',
            'clientSite',
            'items' => fn ($query) => $query->orderBy('sort_order'),
            'items.brief',
            'items.draft',
            'items.draft.content',
        ]);

        return view('app.content.batches.show', [
            'batch' => $batch,
            'items' => $batch->items,
        ]);
    }

    public function retryItem(
        ContentBatch $batch,
        ContentBatchItem $item,
        Request $request,
        BatchGenerationService $batchGenerationService
    ): RedirectResponse {
        $this->authorizeBatch($request, $batch);
        $this->authorize('create', Content::class);

        if ((string) $item->batch_id !== (string) $batch->id) {
            abort(404);
        }

        if ($batch->status === 'canceled') {
            return back()->withErrors(['batch' => 'Cannot retry items in a canceled batch.']);
        }

        $item->update([
            'status' => 'pending',
            'error_message' => null,
        ]);

        $batch->update(['status' => 'running']);
        GenerateBatchItemBriefJob::dispatch((string) $item->id)->onQueue('generation');
        $batchGenerationService->syncBatchProgress($batch->fresh());

        return back()->with('status', 'Batch item retry queued.');
    }

    public function cancel(ContentBatch $batch, Request $request, BatchGenerationService $batchGenerationService): RedirectResponse
    {
        $this->authorizeBatch($request, $batch);
        $this->authorize('create', Content::class);

        if (in_array((string) $batch->status, ['completed', 'failed', 'canceled'], true)) {
            return back()->withErrors(['batch' => 'Batch is already finalized.']);
        }

        $batch->update(['status' => 'canceled']);

        ContentBatchItem::query()
            ->where('batch_id', $batch->id)
            ->whereIn('status', ['pending', 'briefing', 'drafting'])
            ->update([
                'status' => 'failed',
                'error_message' => 'Batch canceled by user.',
            ]);

        $batchGenerationService->syncBatchProgress($batch->fresh());

        return back()->with('status', 'Batch canceled.');
    }

    private function authorizeBatch(Request $request, ContentBatch $batch): void
    {
        $batch->loadMissing('workspace');
        $organizationId = (int) $request->user()->organization_id;

        if ((int) ($batch->workspace?->organization_id ?? 0) !== $organizationId) {
            abort(404);
        }
    }

    private function resolveWorkspace(Request $request, $workspaces): Workspace
    {
        $workspaceId = trim((string) $request->query('workspace_id', ''));
        if ($workspaceId !== '') {
            $selected = $workspaces->firstWhere('id', $workspaceId);
            if ($selected) {
                return $selected;
            }
        }

        $fallback = $workspaces->first();
        if ($fallback) {
            return $fallback;
        }

        abort(404);
    }
}
