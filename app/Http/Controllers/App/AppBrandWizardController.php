<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\BrandWizardInputRequest;
use App\Jobs\GenerateBrandContextJob;
use App\Models\EnrichmentRun;
use App\Services\BrandContext\BrandContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use RuntimeException;

class AppBrandWizardController extends Controller
{
    /**
     * Show the brand wizard input selection step.
     */
    public function index(Request $request): View
    {
        $this->authorizeWizardAccess($request);

        $organization = $request->user()->organization;
        abort_unless($organization, 403);

        $preselectedSection = $request->query('section');
        $preselectedMode = $request->query('mode');

        return view('app.brand.wizard.index', [
            'organization' => $organization,
            'preselectedSection' => $preselectedSection,
            'preselectedMode' => $preselectedMode,
            'sections' => EnrichmentRun::BRAND_SECTIONS,
        ]);
    }

    /**
     * Submit input and start processing.
     */
    public function store(BrandWizardInputRequest $request, BrandContextService $brandContextService): RedirectResponse
    {
        $this->authorizeWizardAccess($request);

        $organization = $request->user()->organization;
        abort_unless($organization, 403);

        $run = $brandContextService->createBrandContextRun(
            $organization,
            $request->validated(),
            $request->user()
        );

        if ($run->wasRecentlyCreated) {
            GenerateBrandContextJob::dispatch($run->id)->onQueue('default');
        }

        return redirect()
            ->route('app.brand.wizard.review', $run)
            ->with('status', 'Processing started. Please wait while we generate your brand content.');
    }

    /**
     * Get processing status (polled via JS).
     */
    public function status(Request $request, EnrichmentRun $run): JsonResponse
    {
        $this->ensureOwnership($request, $run);

        $run->refresh();

        return response()->json([
            'id' => (string) $run->id,
            'status' => (string) $run->status,
            'progress' => (float) $run->progress,
            'message' => $this->statusMessage($run),
            'sections_count' => $this->sectionsCount($run),
            'updated_at' => optional($run->updated_at)->toIso8601String(),
            'failure_reason' => $this->exposedFailureReason($request, $run),
            'error_message' => (string) ($run->error_message ?? ''),
            'is_complete' => $run->isTerminal(),
            'is_failed' => in_array((string) $run->status, [EnrichmentRun::STATUS_FAILED, EnrichmentRun::STATUS_COMPLETED_EMPTY], true),
        ]);
    }

    /**
     * Show section selection with previews.
     */
    public function review(Request $request, EnrichmentRun $run): View
    {
        $this->authorizeWizardAccess($request);
        $this->ensureOwnership($request, $run);

        $organization = $request->user()->organization?->load([
            'organizationProfile',
            'workspaces.companyProfile',
            'workspaces.brandVoices',
            'personas',
            'teamMembers',
        ]);

        return view('app.brand.wizard.review', [
            'run' => $run,
            'organization' => $organization,
            'brandContextId' => data_get($run->extracted_payload, 'brand_context_id'),
            'aiPayload' => $run->ai_payload ?? [],
            'requestedSections' => $run->requested_sections ?? EnrichmentRun::BRAND_SECTIONS,
            'isProcessing' => $run->isInProgress(),
            'isFailed' => $run->status === EnrichmentRun::STATUS_FAILED,
            'isCompletedEmpty' => $run->status === EnrichmentRun::STATUS_COMPLETED_EMPTY,
            'isReady' => $run->isCompletedSuccessfully(),
            'generatedSections' => $this->generatedSections($run),
            'statusMessage' => $this->statusMessage($run),
            'canViewDiagnostics' => $this->canViewDiagnostics($request),
            'diagnostics' => $this->compactDiagnostics($run),
        ]);
    }

    /**
     * Apply selected sections.
     */
    public function apply(Request $request, EnrichmentRun $run, BrandContextService $brandContextService): RedirectResponse
    {
        $this->authorizeWizardAccess($request);
        $this->ensureOwnership($request, $run);

        if (! $run->isCompletedSuccessfully()) {
            return back()->withErrors(['wizard' => 'This run is not ready to be applied.']);
        }

        $data = $request->validate([
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['string', 'in:company_profile,brand_voices,buyer_personas,team_personas'],
        ]);

        try {
            $results = $brandContextService->approveSections($run, $data, $request->user());

            $appliedCount = count(array_filter($results, fn ($r) => $r !== null));
            $redirectRoute = $this->resolveRedirectRouteForSections($data['sections']);

            return redirect()
                ->route($redirectRoute)
                ->with('status', "Successfully applied {$appliedCount} section(s) to your brand profile.");
        } catch (RuntimeException $e) {
            return back()->withErrors(['wizard' => $e->getMessage()]);
        }
    }

    public function retry(Request $request, EnrichmentRun $run, BrandContextService $brandContextService): RedirectResponse
    {
        $this->authorizeWizardAccess($request);
        $this->ensureOwnership($request, $run);

        $nextRun = $brandContextService->retryBrandContextRun($run, $request->user());

        if ($nextRun->isInProgress() && ! $nextRun->wasRecentlyCreated) {
            return redirect()
                ->route('app.brand.wizard.review', $nextRun)
                ->with('status', 'Generation is already in progress.');
        }

        GenerateBrandContextJob::dispatch($nextRun->id)->onQueue('default');

        return redirect()
            ->route('app.brand.wizard.review', $nextRun)
            ->with('status', 'Generation restarted. We are preparing a fresh brand setup run.');
    }

    /**
     * Ensure the run belongs to the user's organization.
     */
    private function ensureOwnership(Request $request, EnrichmentRun $run): void
    {
        abort_unless((int) $run->organization_id === (int) $request->user()->organization_id, 404);
        abort_unless($run->enrichment_type === EnrichmentRun::TYPE_BRAND_CONTEXT, 404);
    }

    /**
     * @param  array<int, string>  $sections
     */
    private function resolveRedirectRouteForSections(array $sections): string
    {
        return match (true) {
            in_array('brand_voices', $sections, true) => 'app.brand.voices',
            in_array('buyer_personas', $sections, true) => 'app.brand.personas',
            in_array('team_personas', $sections, true) => 'app.brand.team-members',
            default => 'app.brand.company-profile',
        };
    }

    private function authorizeWizardAccess(Request $request): void
    {
        abort_unless(
            Gate::forUser($request->user())->allows('manage-organization')
            || ($request->user()?->isAdminAreaUser() ?? false),
            403
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function generatedSections(EnrichmentRun $run): array
    {
        $sections = [];

        foreach ((array) ($run->requested_sections ?? EnrichmentRun::BRAND_SECTIONS) as $section) {
            $value = data_get($run->ai_payload, (string) $section);

            if ($this->hasUsableSectionValue($value)) {
                $sections[(string) $section] = $value;
            }
        }

        return $sections;
    }

    private function sectionsCount(EnrichmentRun $run): int
    {
        return count($this->generatedSections($run));
    }

    private function hasUsableSectionValue(mixed $value): bool
    {
        if (! is_array($value)) {
            return ! blank($value);
        }

        foreach ($value as $item) {
            if (is_array($item) && collect($item)->filter(fn ($field) => ! blank($field))->isNotEmpty()) {
                return true;
            }

            if (! is_array($item) && ! blank($item)) {
                return true;
            }
        }

        return false;
    }

    private function statusMessage(EnrichmentRun $run): string
    {
        return match ((string) $run->status) {
            EnrichmentRun::STATUS_QUEUED => 'AI is preparing your brand setup.',
            EnrichmentRun::STATUS_PROCESSING, EnrichmentRun::STATUS_RUNNING => 'We are reading the source, extracting brand context and preparing editable sections.',
            EnrichmentRun::STATUS_COMPLETED => 'Your generated sections are ready for review.',
            EnrichmentRun::STATUS_COMPLETED_EMPTY => 'The AI run finished, but did not return usable brand context.',
            EnrichmentRun::STATUS_FAILED => 'Generation failed before usable brand sections were stored.',
            default => 'Brand setup run updated.',
        };
    }

    private function exposedFailureReason(Request $request, EnrichmentRun $run): ?string
    {
        if ($this->canViewDiagnostics($request) || in_array((string) $run->status, [EnrichmentRun::STATUS_FAILED, EnrichmentRun::STATUS_COMPLETED_EMPTY], true)) {
            return $run->failure_reason;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function compactDiagnostics(EnrichmentRun $run): array
    {
        return collect((array) ($run->diagnostic_payload ?? []))
            ->only([
                'run_id',
                'workspace_id',
                'brand_setup_id',
                'source_type',
                'source_url',
                'provider',
                'model',
                'request_id',
                'raw_response_length',
                'parser_error',
                'sections_count',
            ])
            ->filter(fn ($value) => ! blank($value) || $value === 0)
            ->all();
    }

    private function canViewDiagnostics(Request $request): bool
    {
        $user = $request->user();

        return ($user?->isAdminAreaUser() ?? false)
            || $request->attributes->has('support_mode_enabled')
            || $request->session()->has('support_started_by_admin_id');
    }
}
