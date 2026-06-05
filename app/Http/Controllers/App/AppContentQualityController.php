<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\Seo\SeoQualityAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppContentQualityController extends Controller
{
    public function index(Request $request, Workspace $workspace): View
    {
        $this->authorize('viewContentIntelligence', $workspace);
        $this->assertWorkspaceVisible($request, $workspace);

        return view('app.content-quality.index', [
            'workspace' => $workspace,
            'result' => null,
            'filters' => $this->defaultFilters(),
            'canRun' => $request->user()?->can('runContentIntelligenceAudit', $workspace) ?? false,
        ]);
    }

    public function run(Request $request, Workspace $workspace, SeoQualityAuditService $audit): View|RedirectResponse
    {
        $this->authorize('runContentIntelligenceAudit', $workspace);
        $this->assertWorkspaceVisible($request, $workspace);

        $data = $request->validate([
            'published_only' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'content_type' => ['nullable', 'string', 'in:article,page,post'],
            'locale' => ['nullable', 'string', 'max:12'],
            'issue_type' => ['nullable', 'string', 'in:duplicate_titles,headings,ai_readiness,sources,links,depth,freshness,structure'],
            'severity' => ['nullable', 'string', 'in:low,medium,high'],
        ]);

        $filters = array_merge($this->defaultFilters(), [
            'published_only' => (bool) ($data['published_only'] ?? false),
            'limit' => (int) ($data['limit'] ?? 500),
            'content_type' => trim((string) ($data['content_type'] ?? 'article')),
            'locale' => trim((string) ($data['locale'] ?? '')),
            'issue_type' => trim((string) ($data['issue_type'] ?? '')),
            'severity' => trim((string) ($data['severity'] ?? '')),
        ]);

        return view('app.content-quality.index', [
            'workspace' => $workspace,
            'result' => $audit->auditWorkspace(
                workspace: $workspace,
                publishedOnly: $filters['published_only'],
                limit: $filters['limit'],
                contentType: $filters['content_type'],
                locale: $filters['locale'],
                issueType: $filters['issue_type'],
                severity: $filters['severity'],
            ),
            'filters' => $filters,
            'canRun' => true,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultFilters(): array
    {
        return [
            'published_only' => true,
            'limit' => 500,
            'content_type' => 'article',
            'locale' => '',
            'issue_type' => '',
            'severity' => '',
        ];
    }

    private function assertWorkspaceVisible(Request $request, Workspace $workspace): void
    {
        $user = $request->user();

        if ($user?->is_admin) {
            return;
        }

        if ((int) $workspace->organization_id !== (int) $user?->organization_id) {
            abort(404);
        }
    }
}
