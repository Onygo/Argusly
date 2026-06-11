<?php

namespace App\Services\Growth;

use App\Models\Draft;
use App\Models\GrowthProgram;
use App\Models\ProgrammaticDraftRequest;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ProgrammaticDraftGenerator
{
    public function generate(ProgrammaticDraftRequest $request): Draft
    {
        $request->loadMissing(['brief', 'blueprint', 'item', 'cluster']);

        if ($existing = $this->existingDraftFor($request)) {
            $request->forceFill(['status' => ProgrammaticDraftRequest::STATUS_GENERATED])->save();

            return $existing;
        }

        if ($request->status !== ProgrammaticDraftRequest::STATUS_APPROVED) {
            throw new InvalidArgumentException('Only approved programmatic draft requests can generate drafts.');
        }

        try {
            if ($request->generation_mode === ProgrammaticDraftRequest::MODE_BATCH && ! (bool) config('argusly_programmatic.allow_batch_generation', false)) {
                throw new InvalidArgumentException('Batch programmatic draft generation is disabled.');
            }

            $request->forceFill(['status' => ProgrammaticDraftRequest::STATUS_QUEUED])->save();

            $brief = $request->brief ?: throw new RuntimeException('Programmatic draft request has no linked Brief.');
            $draft = Draft::query()->create([
                'brief_id' => (string) $brief->id,
                'content_id' => $brief->content_id,
                'client_site_id' => (string) $brief->client_site_id,
                'content_destination_id' => $brief->content_destination_id,
                'status' => Draft::STATUS_DRAFT,
                'attempts' => 0,
                'title' => $brief->title,
                'seo_title' => $brief->title,
                'seo_h1' => $brief->title,
                'schema_type' => collect((array) data_get($brief->client_refs, 'schema_recommendations', []))->first(),
                'output_type' => $brief->output_type ?: $brief->content_type,
                'language' => $brief->language ?: 'nl',
                'content_html' => '',
                'meta' => $this->draftMeta($request),
                'links' => null,
            ]);

            $refs = is_array($brief->client_refs) ? $brief->client_refs : [];
            $refs['draft_id'] = (string) $draft->id;
            $refs['programmatic_draft_request_id'] = (string) $request->id;
            $brief->forceFill(['client_refs' => $refs])->save();

            $request->forceFill([
                'status' => ProgrammaticDraftRequest::STATUS_GENERATED,
                'metadata' => array_replace_recursive((array) $request->metadata, [
                    'generated_draft_id' => (string) $draft->id,
                    'generated_at' => now()->toIso8601String(),
                    'actual_generation_tokens' => 0,
                    'actual_generation_cost' => 0,
                ]),
            ])->save();

            return $draft->refresh();
        } catch (Throwable $exception) {
            $request->forceFill([
                'status' => ProgrammaticDraftRequest::STATUS_FAILED,
                'metadata' => array_replace_recursive((array) $request->metadata, [
                    'failure_reason' => $exception->getMessage(),
                    'failed_at' => now()->toIso8601String(),
                ]),
            ])->save();

            throw $exception;
        }
    }

    public function generateForCluster(string $clusterId): int
    {
        $this->assertBatchAllowed();

        return ProgrammaticDraftRequest::query()
            ->where('programmatic_cluster_id', $clusterId)
            ->where('status', ProgrammaticDraftRequest::STATUS_APPROVED)
            ->limit((int) config('argusly_programmatic.max_requests_per_cluster', 25))
            ->get()
            ->reduce(function (int $count, ProgrammaticDraftRequest $request): int {
                $this->generate($request);

                return $count + 1;
            }, 0);
    }

    public function generateForProgram(GrowthProgram $program): int
    {
        $this->assertBatchAllowed();

        return ProgrammaticDraftRequest::query()
            ->where('growth_program_id', $program->id)
            ->where('status', ProgrammaticDraftRequest::STATUS_APPROVED)
            ->limit((int) config('argusly_programmatic.max_requests_per_growth_program', 100))
            ->get()
            ->reduce(function (int $count, ProgrammaticDraftRequest $request): int {
                $this->generate($request);

                return $count + 1;
            }, 0);
    }

    public function existingDraftFor(ProgrammaticDraftRequest $request): ?Draft
    {
        $draftId = (string) data_get($request->metadata, 'generated_draft_id', '');
        if ($draftId !== '') {
            $draft = Draft::query()->whereKey($draftId)->first();
            if ($draft) {
                return $draft;
            }
        }

        return Draft::query()
            ->where('brief_id', $request->brief_id)
            ->get()
            ->first(fn (Draft $draft): bool => (string) data_get($draft->meta, 'programmatic_draft_request_id') === (string) $request->id);
    }

    /**
     * @return array<string,mixed>
     */
    private function draftMeta(ProgrammaticDraftRequest $request): array
    {
        $brief = $request->brief;

        return [
            'source' => 'programmatic_draft_request',
            'programmatic_draft_request_id' => (string) $request->id,
            'programmatic_brief_blueprint_id' => $request->programmatic_brief_blueprint_id ? (string) $request->programmatic_brief_blueprint_id : null,
            'programmatic_cluster_id' => $request->programmatic_cluster_id ? (string) $request->programmatic_cluster_id : null,
            'programmatic_cluster_item_id' => $request->programmatic_cluster_item_id ? (string) $request->programmatic_cluster_item_id : null,
            'growth_program_id' => $request->growth_program_id ? (string) $request->growth_program_id : null,
            'growth_asset_type' => $request->growth_asset_type?->value ?? (string) $request->growth_asset_type,
            'primary_keyword' => $brief?->primary_keyword,
            'secondary_keywords' => $brief?->secondary_keywords ?? [],
            'audience' => $brief?->audience,
            'intent' => $brief?->intent,
            'call_to_action' => $brief?->call_to_action,
            'outline' => data_get($brief?->client_refs, 'outline', []),
            'required_sections' => data_get($brief?->client_refs, 'required_sections', []),
            'faq_questions' => data_get($brief?->client_refs, 'faq_questions', []),
            'schema_recommendations' => data_get($brief?->client_refs, 'schema_recommendations', []),
            'internal_linking_plan' => data_get($brief?->client_refs, 'internal_linking_plan', []),
            'seo_requirements' => data_get($brief?->client_refs, 'seo_requirements', []),
            'ai_visibility_requirements' => data_get($brief?->client_refs, 'ai_visibility_requirements', []),
            'quality_requirements' => data_get($brief?->client_refs, 'quality_requirements', []),
            'generation_mode' => $request->generation_mode,
            'estimated_tokens' => $request->estimated_tokens,
            'estimated_cost' => $request->estimated_cost,
            'client_refs' => $brief?->client_refs ?? [],
            'prepared_at' => now()->toIso8601String(),
        ];
    }

    private function assertBatchAllowed(): void
    {
        if (! (bool) config('argusly_programmatic.allow_batch_generation', false)) {
            throw new InvalidArgumentException('Batch programmatic draft generation is disabled.');
        }
    }
}
