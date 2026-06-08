<?php

namespace App\Actions\Content;

use App\Agents\InternalLinking\InternalLinkingAgent;
use App\Data\InternalLinkSuggestion;
use App\Jobs\RebuildContentMarkdownArtifactJob;
use App\Models\AgentRun;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Models\User;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\InternalLinking\InternalLinkInjector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ApplyInternalLinkSuggestion
{
    public function __construct(
        private readonly InternalLinkInjector $injector,
        private readonly ContentCacheInvalidationService $cacheInvalidation,
    ) {
    }

    public function toDraft(Draft $draft, string $runId, int $suggestionIndex, User $user): AgentRun
    {
        $run = $this->resolveDraftRun($draft, $runId);
        $suggestion = $this->resolveSuggestion($run, $suggestionIndex);
        $result = $this->injector->injectIntoHtml(
            (string) ($draft->content_html ?? ''),
            [InternalLinkSuggestion::fromArray($suggestion)]
        );

        if ((int) $result['applied_count'] < 1) {
            throw new RuntimeException('The suggested anchor could not be inserted into the current draft body.');
        }

        $draft->update([
            'content_html' => (string) $result['updated_html'],
            'meta' => $this->withInternalLinkMeta((array) ($draft->meta ?? []), $result),
        ]);

        $this->markApplied($run, $suggestionIndex, [
            'applied_at' => now()->toIso8601String(),
            'applied_resource_type' => 'draft',
            'applied_resource_id' => (string) $draft->id,
            'applied_by_user_id' => $user->id,
        ]);

        return $run->fresh();
    }

    public function toContent(Content $content, string $runId, int $suggestionIndex, User $user): AgentRun
    {
        $content->loadMissing(['currentRevision', 'currentVersion']);

        $run = $this->resolveContentRun($content, $runId);
        $suggestion = $this->resolveSuggestion($run, $suggestionIndex);
        $currentHtml = trim((string) (
            $content->currentRevision?->content_html
            ?: $content->currentVersion?->body
            ?: ''
        ));
        $result = $this->injector->injectIntoHtml(
            $currentHtml,
            [InternalLinkSuggestion::fromArray($suggestion)]
        );

        if ((int) $result['applied_count'] < 1) {
            throw new RuntimeException('The suggested anchor could not be inserted into the current editable content body.');
        }

        DB::transaction(function () use ($content, $suggestion, $result, $user): void {
            $content->refresh();
            $content->loadMissing(['currentRevision', 'currentVersion']);
            $meta = $this->withInternalLinkMeta([
                'source' => 'internal_linking_agent',
                'applied_suggestion' => $suggestion,
            ], $result);

            $latestDraft = Draft::query()
                ->where('content_id', (string) $content->id)
                ->latest('created_at')
                ->first();

            if ($latestDraft instanceof Draft) {
                $latestDraft->update([
                    'content_html' => (string) $result['updated_html'],
                    'meta' => $this->withInternalLinkMeta((array) ($latestDraft->meta ?? []), $result),
                ]);
            }

            $nextRevisionNumber = (int) ContentRevision::query()
                ->where('content_id', (string) $content->id)
                ->max('revision_number') + 1;

            $revision = ContentRevision::query()->create([
                'id' => (string) Str::uuid(),
                'content_id' => (string) $content->id,
                'draft_id' => null,
                'revision_number' => $nextRevisionNumber,
                'label' => 'R' . $nextRevisionNumber,
                'content_html' => (string) $result['updated_html'],
                'meta' => $meta,
                'is_active' => true,
                'created_by_user_id' => $user->id,
            ]);

            ContentRevision::query()
                ->where('content_id', (string) $content->id)
                ->where('id', '!=', (string) $revision->id)
                ->update(['is_active' => false]);

            $version = ContentVersion::query()->create([
                'id' => (string) Str::uuid(),
                'content_id' => (string) $content->id,
                'type' => ContentVersion::TYPE_REVISION,
                'parent_version_id' => $content->current_version_id,
                'body' => (string) $result['updated_html'],
                'meta' => array_merge($meta, [
                    'label' => 'Internal link refresh - ' . now()->format('Y-m-d H:i'),
                ]),
                'source' => ContentVersion::SOURCE_ARGUSLY,
                'created_by' => $user->id,
            ]);

            $content->update([
                'current_revision_id' => (string) $revision->id,
                'current_version_id' => (string) $version->id,
                'status' => 'draft',
                'updated_by' => $user->id,
                'internal_links_meta' => $this->withInternalLinkMeta((array) ($content->internal_links_meta ?? []), $result),
            ]);
        });

        RebuildContentMarkdownArtifactJob::dispatch((string) $content->id, force: true)->afterCommit();
        $this->cacheInvalidation->invalidateContent($content->fresh(), 'content.internal_link_suggestion_applied');

        $this->markApplied($run, $suggestionIndex, [
            'applied_at' => now()->toIso8601String(),
            'applied_resource_type' => 'content_revision',
            'applied_resource_id' => (string) $content->fresh()->current_version_id,
            'applied_by_user_id' => $user->id,
        ]);

        return $run->fresh();
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function withInternalLinkMeta(array $meta, array $result): array
    {
        $inlineLinks = array_values((array) ($result['inline_links'] ?? []));
        $meta['inline_links_applied'] = $inlineLinks !== [];
        $meta['internal_links_applied_at'] = now()->toIso8601String();
        $meta['inserted_inline_links'] = collect(array_merge(
            (array) ($meta['inserted_inline_links'] ?? []),
            $inlineLinks,
        ))
            ->filter(fn ($link): bool => is_array($link))
            ->unique(fn (array $link): string => trim((string) ($link['target_url'] ?? '')))
            ->values()
            ->all();

        return $meta;
    }

    private function resolveDraftRun(Draft $draft, string $runId): AgentRun
    {
        return AgentRun::query()
            ->whereKey($runId)
            ->where('agent_key', InternalLinkingAgent::KEY)
            ->where('draft_id', (string) $draft->id)
            ->where('trigger_type', 'manual')
            ->firstOrFail();
    }

    private function resolveContentRun(Content $content, string $runId): AgentRun
    {
        return AgentRun::query()
            ->whereKey($runId)
            ->where('agent_key', InternalLinkingAgent::KEY)
            ->where('content_id', (string) $content->id)
            ->where('trigger_type', 'manual')
            ->firstOrFail();
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveSuggestion(AgentRun $run, int $suggestionIndex): array
    {
        $suggestions = collect((array) data_get($run->output_payload, 'suggestions', []))
            ->values();
        $suggestion = $suggestions->get($suggestionIndex);

        if (! is_array($suggestion)) {
            throw new RuntimeException('The selected internal link suggestion is no longer available.');
        }

        if (filled(data_get($suggestion, 'applied_at'))) {
            throw new RuntimeException('This internal link suggestion has already been applied.');
        }

        return $suggestion;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    private function markApplied(AgentRun $run, int $suggestionIndex, array $attributes): void
    {
        $payload = is_array($run->output_payload) ? $run->output_payload : [];
        $suggestions = collect((array) data_get($payload, 'suggestions', []))
            ->values()
            ->all();

        if (! isset($suggestions[$suggestionIndex]) || ! is_array($suggestions[$suggestionIndex])) {
            return;
        }

        $suggestions[$suggestionIndex] = array_merge($suggestions[$suggestionIndex], $attributes);
        data_set($payload, 'suggestions', array_values($suggestions));

        $run->forceFill([
            'output_payload' => $payload,
        ])->save();
    }
}
