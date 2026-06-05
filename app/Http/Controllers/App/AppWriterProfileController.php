<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\Workspace;
use App\Models\WriterProfile;
use App\Services\WriterProfiles\WriterProfileAnalysisService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AppWriterProfileController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 403);

        return view('app.brand.writer-profiles', [
            'workspace' => $workspace,
            'writerProfiles' => $workspace->writerProfiles()->withCount('sources')->orderByRaw("status = 'active' desc")->orderBy('name')->get(),
            'brandVoices' => $workspace->brandVoices()->orderByDesc('is_default')->orderBy('name')->get(),
            'contents' => Content::query()
                ->where('workspace_id', $workspace->id)
                ->latest()
                ->limit(25)
                ->get(['id', 'title', 'language', 'created_at']),
        ]);
    }

    public function store(Request $request, WriterProfileAnalysisService $analysis): RedirectResponse
    {
        Gate::authorize('manage-organization');

        $workspace = $this->resolveWorkspace($request);
        abort_unless($workspace, 403);

        $data = $this->validated($request);
        $profile = WriterProfile::query()->create($this->profilePayload($data, (string) $workspace->id, (int) $request->user()->id));

        $texts = $this->sourceTexts($data);
        $contentIds = array_values(array_filter(array_map('strval', (array) ($data['content_ids'] ?? []))));
        if ($contentIds !== []) {
            $analysis->analyzeFromContent($profile, $contentIds);
        } elseif ($texts !== []) {
            $analysis->analyze($profile, $texts);
        }

        return back()->with('status', 'Writer profile created. PublishLayer uses it as style guidance without copying source text.');
    }

    public function update(Request $request, WriterProfile $writerProfile): RedirectResponse
    {
        $this->authorize('update', $writerProfile);
        $data = $this->validated($request, updating: true);

        $writerProfile->update($this->profilePayload($data, (string) $writerProfile->workspace_id, (int) ($writerProfile->user_id ?? $request->user()->id)));

        return back()->with('status', 'Writer profile updated.');
    }

    public function analyze(Request $request, WriterProfile $writerProfile, WriterProfileAnalysisService $analysis): RedirectResponse
    {
        $this->authorize('update', $writerProfile);

        $data = $request->validate([
            'source_texts' => ['nullable', 'string', 'max:60000'],
            'content_ids' => ['nullable', 'array'],
            'content_ids.*' => ['uuid'],
        ]);

        $texts = $this->sourceTexts($data);
        $contentIds = array_values(array_filter(array_map('strval', (array) ($data['content_ids'] ?? []))));

        if ($contentIds !== []) {
            $analysis->analyzeFromContent($writerProfile, $contentIds);
        } elseif ($texts !== []) {
            $analysis->analyze($writerProfile, $texts);
        } else {
            return back()->withErrors(['source_texts' => 'Add pasted text or select existing content before running analysis.']);
        }

        return back()->with('status', 'Writer profile analysis refreshed.');
    }

    public function activate(WriterProfile $writerProfile): RedirectResponse
    {
        $this->authorize('update', $writerProfile);
        $writerProfile->update(['status' => WriterProfile::STATUS_ACTIVE]);

        return back()->with('status', 'Writer profile activated.');
    }

    public function archive(WriterProfile $writerProfile): RedirectResponse
    {
        $this->authorize('update', $writerProfile);
        $writerProfile->update(['status' => WriterProfile::STATUS_ARCHIVED]);

        return back()->with('status', 'Writer profile archived.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'brand_id' => ['nullable', 'uuid'],
            'source_type' => ['required', 'in:manual,uploaded_texts,content_history,mixed'],
            'profile_scope' => ['required', 'in:author,brand,company,campaign'],
            'tone_summary' => ['nullable', 'string', 'max:3000'],
            'writing_style_summary' => ['nullable', 'string', 'max:3000'],
            'structure_summary' => ['nullable', 'string', 'max:3000'],
            'vocabulary_notes' => ['nullable', 'string', 'max:3000'],
            'formatting_preferences' => ['nullable', 'string', 'max:3000'],
            'do_rules_text' => ['nullable', 'string', 'max:5000'],
            'dont_rules_text' => ['nullable', 'string', 'max:5000'],
            'example_patterns_text' => ['nullable', 'string', 'max:5000'],
            'retain_source_text' => ['nullable', 'boolean'],
            'default_blog' => ['nullable', 'boolean'],
            'default_linkedin' => ['nullable', 'boolean'],
            'default_newsletter' => ['nullable', 'boolean'],
            'default_landing_page' => ['nullable', 'boolean'],
            'source_texts' => ['nullable', 'string', 'max:60000'],
            'content_ids' => ['nullable', 'array'],
            'content_ids.*' => ['uuid'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function profilePayload(array $data, string $workspaceId, int $userId): array
    {
        return [
            'workspace_id' => $workspaceId,
            'brand_id' => $data['brand_id'] ?? null,
            'user_id' => $userId,
            'name' => (string) ($data['name'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'source_type' => (string) ($data['source_type'] ?? WriterProfile::SOURCE_MANUAL),
            'profile_scope' => (string) ($data['profile_scope'] ?? WriterProfile::SCOPE_AUTHOR),
            'tone_summary' => (string) ($data['tone_summary'] ?? ''),
            'writing_style_summary' => (string) ($data['writing_style_summary'] ?? ''),
            'structure_summary' => (string) ($data['structure_summary'] ?? ''),
            'vocabulary_notes' => (string) ($data['vocabulary_notes'] ?? ''),
            'formatting_preferences' => (string) ($data['formatting_preferences'] ?? ''),
            'do_rules' => $this->splitLines((string) ($data['do_rules_text'] ?? '')),
            'dont_rules' => array_values(array_unique(array_merge(
                $this->splitLines((string) ($data['dont_rules_text'] ?? '')),
                ['Do not reuse unique sentences, claims, examples, anecdotes, or recognizable formulations from source material.']
            ))),
            'example_patterns' => $this->splitLines((string) ($data['example_patterns_text'] ?? '')),
            'retain_source_text' => (bool) ($data['retain_source_text'] ?? false),
            'channel_defaults' => [
                'blog' => (bool) ($data['default_blog'] ?? false),
                'linkedin' => (bool) ($data['default_linkedin'] ?? false),
                'newsletter' => (bool) ($data['default_newsletter'] ?? false),
                'landing_page' => (bool) ($data['default_landing_page'] ?? false),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, string>>
     */
    private function sourceTexts(array $data): array
    {
        $raw = trim((string) ($data['source_texts'] ?? ''));
        if ($raw === '') {
            return [];
        }

        return collect(preg_split('/\n-{3,}\n/', $raw) ?: [$raw])
            ->map(fn ($text, int $index): array => [
                'title' => 'Pasted text '.($index + 1),
                'text' => trim((string) $text),
                'language' => '',
            ])
            ->filter(fn (array $source): bool => $source['text'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function splitLines(string $value): array
    {
        return collect(preg_split('/\R+/', $value) ?: [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }

    private function resolveWorkspace(Request $request): ?Workspace
    {
        $organizationId = (int) $request->user()->organization_id;

        if (! $organizationId) {
            return null;
        }

        $impersonatedWorkspaceId = (string) $request->session()->get('impersonated_workspace_id', '');
        if ($impersonatedWorkspaceId !== '') {
            $workspace = Workspace::query()
                ->where('organization_id', $organizationId)
                ->whereKey($impersonatedWorkspaceId)
                ->first();

            if ($workspace) {
                return $workspace;
            }
        }

        return Workspace::query()
            ->where('organization_id', $organizationId)
            ->orderBy('created_at')
            ->first();
    }
}
