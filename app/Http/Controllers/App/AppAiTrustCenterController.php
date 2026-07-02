<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AiTransparencyRecord;
use App\Models\Content;
use App\Services\AiTransparency\AiTransparencyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AppAiTrustCenterController extends Controller
{
    public function __construct(private readonly AiTransparencyService $transparency) {}

    public function show(Content $content): View
    {
        $this->authorize('view', $content);

        $record = $this->transparency->ensureForContent($content);
        $payload = $this->transparency->provenancePayload($record);

        return view('app.ai-trust-center.show', [
            'content' => $content,
            'record' => $record,
            'payload' => $payload,
        ]);
    }

    public function downloadAuditReport(Request $request, Content $content): Response
    {
        $this->authorize('view', $content);

        $record = $this->transparency->ensureForContent($content);
        $report = $this->transparency->generateAuditReport($record, $request->user());
        $bytes = $this->transparency->renderAuditReportPdf($record, $request->user());
        $filename = 'argusly-ai-audit-' . $content->id . '.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Argusly-Audit-Report-Id' => $report->id,
            'X-Argusly-Audit-Checksum' => (string) $report->checksum,
        ]);
    }

    public function review(Request $request, Content $content): RedirectResponse
    {
        $this->authorize('update', $content);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:reviewed,approved,needs_changes,rejected'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'checklist.provenance_checked' => ['nullable', 'boolean'],
            'checklist.sources_checked' => ['nullable', 'boolean'],
            'checklist.disclosure_checked' => ['nullable', 'boolean'],
        ]);

        $record = $this->transparency->ensureForContent($content);
        $this->transparency->recordHumanReview($record, [
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
            'checklist' => $validated['checklist'] ?? [],
        ], $request->user());

        return back()->with('status', 'Human review opgeslagen.');
    }

    public function factCheck(Request $request, Content $content): RedirectResponse
    {
        $this->authorize('update', $content);

        $validated = $request->validate([
            'claim' => ['required', 'string', 'max:2000'],
            'status' => ['required', 'string', 'in:unchecked,supported,partial,conflicting,needs_human_review'],
            'confidence' => ['nullable', 'integer', 'min:0', 'max:100'],
            'evidence_url' => ['nullable', 'url', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! empty($validated['evidence_url'])) {
            $validated['evidence'] = [
                ['type' => 'url', 'url' => $validated['evidence_url']],
            ];
        }

        $record = $this->transparency->ensureForContent($content);
        $this->transparency->recordFactCheck($record, $validated, $request->user());

        return back()->with('status', 'Fact-check opgeslagen.');
    }

    public function sourceTrace(Request $request, Content $content): RedirectResponse
    {
        $this->authorize('update', $content);

        $validated = $request->validate([
            'source_type' => ['required', 'string', 'in:url,document,dataset,internal,interview,other'],
            'url' => ['nullable', 'url', 'max:2000'],
            'title' => ['nullable', 'string', 'max:500'],
            'retrieval_status' => ['required', 'string', 'in:available,archived,unavailable,needs_review'],
            'reliability_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'used_for_sections' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $sections = collect(explode(',', (string) ($validated['used_for_sections'] ?? '')))
            ->map(fn (string $section): string => trim($section))
            ->filter()
            ->values()
            ->all();

        $record = $this->transparency->ensureForContent($content);
        $this->transparency->recordSourceTrace($record, [
            'source_type' => $validated['source_type'],
            'url' => $validated['url'] ?? null,
            'title' => $validated['title'] ?? null,
            'retrieval_status' => $validated['retrieval_status'],
            'reliability_score' => $validated['reliability_score'] ?? null,
            'used_for_sections' => $sections,
            'metadata' => array_filter([
                'notes' => $validated['notes'] ?? null,
                'recorded_by' => $request->user()?->id,
            ]),
        ]);

        return back()->with('status', 'Source trace opgeslagen.');
    }

    public function updateDisclosure(Request $request, Content $content): RedirectResponse
    {
        $this->authorize('update', $content);

        $validated = $request->validate([
            'origin' => ['required', 'string', 'in:unknown,human,ai_assisted,ai_generated,ai_edited'],
        ]);

        $record = $this->transparency->ensureForContent($content);
        $record->origin = $validated['origin'];
        $record->ai_badge = match ($record->origin) {
            AiTransparencyRecord::ORIGIN_HUMAN => 'Human',
            AiTransparencyRecord::ORIGIN_AI_ASSISTED => 'AI-assisted',
            AiTransparencyRecord::ORIGIN_AI_GENERATED => 'AI-generated',
            AiTransparencyRecord::ORIGIN_AI_EDITED => 'AI-edited',
            default => 'AI status unknown',
        };
        $record->disclosure_label = match ($record->origin) {
            AiTransparencyRecord::ORIGIN_HUMAN => 'Created without recorded AI generation.',
            AiTransparencyRecord::ORIGIN_AI_ASSISTED => 'Created with AI assistance and human editorial input.',
            AiTransparencyRecord::ORIGIN_AI_GENERATED => 'Generated or substantially transformed by an AI system.',
            AiTransparencyRecord::ORIGIN_AI_EDITED => 'Human-created content was edited or transformed by AI.',
            default => 'No AI provenance decision has been recorded yet.',
        };
        $this->transparency->recalculateTrustScore($record);
        $record->save();
        $this->transparency->recordEvent($record, 'disclosure_updated', 'human', $request->user(), 'AI disclosure status updated.', [
            'origin' => $record->origin,
            'badge' => $record->ai_badge,
        ]);
        $this->transparency->ensureForContent($content->fresh(['workspace', 'clientSite.workspace', 'drafts']));

        return back()->with('status', 'AI disclosure bijgewerkt.');
    }
}
