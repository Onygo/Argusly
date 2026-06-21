<?php

namespace App\Http\Controllers\Admin;

use App\Data\Faq\FaqPageInput;
use App\Enums\FaqFunnelStage;
use App\Enums\FaqPageType;
use App\Enums\FaqSearchIntent;
use App\Enums\FaqStatus;
use App\Enums\FaqType;
use App\Http\Controllers\Controller;
use App\Models\FaqOpportunityAudit;
use App\Models\FaqPageAssignment;
use App\Models\FaqQuestion;
use App\Services\Faq\FaqDuplicateDetectionService;
use App\Services\Faq\FaqOpportunityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminFaqIntelligenceController extends Controller
{
    public function index(Request $request, FaqDuplicateDetectionService $duplicates): View
    {
        $auditQuery = FaqOpportunityAudit::query()
            ->when($request->filled('locale'), fn ($query) => $query->where('locale', strtolower((string) $request->input('locale'))))
            ->when($request->filled('page_type'), fn ($query) => $query->where('page_type', (string) $request->input('page_type')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->input('status')))
            ->when($request->filled('market'), fn ($query) => $query->where('sector', 'like', '%'.$request->input('market').'%'))
            ->when($request->filled('solution'), fn ($query) => $query->where('solution_type', 'like', '%'.$request->input('solution').'%'))
            ->when($request->filled('score_min'), fn ($query) => $query->where('faq_opportunity_score', '>=', (float) $request->input('score_min')))
            ->when($request->filled('score_max'), fn ($query) => $query->where('faq_opportunity_score', '<=', (float) $request->input('score_max')));

        $auditSnapshot = (clone $auditQuery)->latest()->limit(200)->get();

        $latestAudits = (clone $auditQuery)
            ->latest()
            ->limit(8)
            ->get();

        $topMissingPages = (clone $auditQuery)
            ->latest()
            ->limit(50)
            ->get()
            ->sortByDesc(fn (FaqOpportunityAudit $audit): float => (float) $audit->faq_opportunity_score)
            ->take(8)
            ->values();

        $faqQuery = FaqQuestion::query()
            ->when($request->filled('locale'), fn ($query) => $query->where('language', strtolower((string) $request->input('locale'))))
            ->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->input('status')));

        return view('admin.faq-intelligence.index', [
            'filters' => $request->only(['locale', 'page_type', 'status', 'score_min', 'score_max', 'market', 'solution']),
            'totalFaqs' => (clone $faqQuery)->count(),
            'publishedFaqs' => (clone $faqQuery)->where('status', FaqStatus::PUBLISHED->value)->count(),
            'averageAiVisibility' => round((float) (clone $faqQuery)->avg('ai_visibility_score'), 1),
            'averageSeo' => round((float) (clone $faqQuery)->avg('seo_score'), 1),
            'averageConversion' => round((float) (clone $faqQuery)->avg('conversion_score'), 1),
            'latestAudits' => $latestAudits,
            'topMissingPages' => $topMissingPages,
            'coverageByPageType' => $this->breakdown($auditSnapshot, 'page_type'),
            'coverageByMarket' => $this->breakdown($auditSnapshot, 'sector'),
            'coverageBySolution' => $this->breakdown($auditSnapshot, 'solution_type'),
            'topFaqOpportunities' => $auditSnapshot->sortByDesc('faq_opportunity_score')->take(8)->values(),
            'topDuplicateRisks' => $duplicates->risks($request->input('locale'))->take(8),
            'faqTypes' => FaqType::cases(),
            'pageTypes' => FaqPageType::cases(),
            'searchIntents' => FaqSearchIntent::cases(),
            'funnelStages' => FaqFunnelStage::cases(),
            'workflowStatuses' => \App\Enums\FaqWorkflowStatus::cases(),
        ]);
    }

    public function analyze(Request $request, FaqOpportunityService $service): View
    {
        $payload = $this->validatedPagePayload($request);
        $input = FaqPageInput::fromArray($payload);
        $result = $service->analyze($input, $request->user()?->id, persist: true);

        return view('admin.faq-intelligence.analysis', [
            'input' => $payload,
            'result' => $result,
            'pageTypes' => FaqPageType::cases(),
        ]);
    }

    public function publish(Request $request, FaqOpportunityService $service): RedirectResponse
    {
        $payload = $this->validatedPagePayload($request);
        $input = FaqPageInput::fromArray($payload);
        $generatedFaqs = json_decode((string) $request->input('generated_faqs', '[]'), true);

        if (! is_array($generatedFaqs)) {
            $generatedFaqs = [];
        }

        $created = $service->publishGeneratedFaqs($input, $generatedFaqs, $request->user()?->id);

        return redirect()
            ->route('admin.faq-intelligence.index')
            ->with('status', sprintf('%d FAQ(s) published and assigned to [%s/%s].', $created->count(), $input->pageType, $input->pageSlug));
    }

    public function accept(Request $request, FaqOpportunityService $service): RedirectResponse
    {
        $payload = $this->validatedPagePayload($request);
        $input = FaqPageInput::fromArray($payload);
        $generatedFaq = json_decode((string) $request->input('generated_faq', '{}'), true);

        if (! is_array($generatedFaq)) {
            $generatedFaq = [];
        }

        $created = $service->publishGeneratedFaqs($input, [$generatedFaq], $request->user()?->id);

        return back()->with('status', $created->isEmpty() ? 'No FAQ accepted. It may already exist.' : 'FAQ accepted and published.');
    }

    public function update(Request $request, FaqQuestion $faqQuestion): RedirectResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'status' => ['required', 'string', 'in:'.implode(',', FaqStatus::values())],
        ]);

        $faqQuestion->update($validated + ['updated_by' => $request->user()?->id]);

        return back()->with('status', 'FAQ updated.');
    }

    public function publishFaq(Request $request, FaqQuestion $faqQuestion): RedirectResponse
    {
        $faqQuestion->update([
            'status' => FaqStatus::PUBLISHED->value,
            'updated_by' => $request->user()?->id,
        ]);

        return back()->with('status', 'FAQ published.');
    }

    public function unlink(FaqPageAssignment $assignment): RedirectResponse
    {
        $assignment->delete();

        return back()->with('status', 'FAQ unlinked from page.');
    }

    /**
     * @return array<string,mixed>
     */
    private function validatedPagePayload(Request $request): array
    {
        $validated = $request->validate([
            'page_title' => ['nullable', 'string', 'max:255'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string'],
            'h1' => ['nullable', 'string', 'max:255'],
            'h2s' => ['nullable', 'string'],
            'content' => ['nullable', 'string'],
            'internal_links' => ['nullable', 'string'],
            'sector' => ['nullable', 'string', 'max:120'],
            'solution_type' => ['nullable', 'string', 'max:120'],
            'page_type' => ['required', 'string', 'max:40'],
            'page_slug' => ['required', 'string', 'max:160'],
            'locale' => ['required', 'string', 'max:5'],
        ]);

        $validated['h2s'] = collect(preg_split('/\r\n|\r|\n/', (string) ($validated['h2s'] ?? '')) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values()
            ->all();

        $validated['internal_links'] = collect(preg_split('/\r\n|\r|\n/', (string) ($validated['internal_links'] ?? '')) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values()
            ->all();

        return $validated;
    }

    /**
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function breakdown(\Illuminate\Support\Collection $audits, string $field): \Illuminate\Support\Collection
    {
        return $audits
            ->filter(fn (FaqOpportunityAudit $audit): bool => trim((string) $audit->{$field}) !== '')
            ->groupBy(fn (FaqOpportunityAudit $audit): string => (string) $audit->{$field})
            ->map(fn (\Illuminate\Support\Collection $group, string $label): array => [
                'label' => $label,
                'pages' => $group->count(),
                'coverage' => round((float) $group->avg('faq_coverage_score'), 1),
                'opportunity' => round((float) $group->avg('faq_opportunity_score'), 1),
            ])
            ->sortBy('coverage')
            ->values();
    }
}
