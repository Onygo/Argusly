<?php

namespace App\Services\AgenticMarketing;

use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Content;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AgenticLearningSignalService
{
    /**
     * @return array<string,mixed>
     */
    public function recordForAction(AgenticMarketingAction $action, ?AgenticActionRun $run = null): array
    {
        $action->loadMissing(['objective', 'opportunity', 'content']);
        $content = $this->resolveContent($action);
        $signal = $this->buildSignal($action, $run, $content);

        $this->storeOnAction($action, $signal);
        $this->storeOnOpportunity($action->opportunity, $signal);

        if ($run) {
            $this->storeOnRun($run, $signal);
        }

        return $signal;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSignal(AgenticMarketingAction $action, ?AgenticActionRun $run, ?Content $content): array
    {
        $result = (array) ($action->result ?? []);
        $payload = (array) ($action->payload ?? []);
        $before = $this->beforeScores($payload, $result);
        $after = $this->afterScores($content, $result);
        $deltas = $this->scoreDeltas($before, $after);
        $impactScore = $this->impactScore($deltas, $result);
        $creditsUsed = (int) ($action->credits_captured ?? $run?->actual_credits ?? $action->credits_reserved ?? $action->estimated_credits ?? 0);
        $jobDuration = $this->jobDurationSeconds($action, $run);
        $status = (string) $action->status;

        $signal = [
            'schema_version' => 1,
            'recorded_at' => now()->toIso8601String(),
            'action_id' => (string) $action->id,
            'opportunity_id' => $action->opportunity_id ? (string) $action->opportunity_id : null,
            'content_id' => $content ? (string) $content->id : ($action->content_id ? (string) $action->content_id : data_get($result, 'created_content_id')),
            'action_type' => (string) $action->action_type,
            'status' => $status,
            'success' => $status === AgenticMarketingAction::STATUS_COMPLETED,
            'failed' => $status === AgenticMarketingAction::STATUS_FAILED,
            'measurements' => [
                'content_created' => $this->contentCreated($action, $result),
                'content_published' => $this->contentPublished($content, $result),
                'content_refreshed' => $this->contentRefreshed($action, $result),
                'internal_links_added' => $this->internalLinksAdded($content, $result),
                'answer_blocks_added' => $this->answerBlocksAdded($content, $result),
                'publication_status' => $this->publicationStatus($content, $result),
                'job_duration_seconds' => $jobDuration,
                'credits_used' => $creditsUsed,
            ],
            'scores' => [
                'before' => $before,
                'after' => $after,
                'delta' => $deltas,
            ],
            'impact_score' => $impactScore,
            'cost_per_impact_point' => $impactScore > 0 && $creditsUsed > 0 ? round($creditsUsed / $impactScore, 2) : null,
            'classifiers' => [
                'successful_action_type' => $status === AgenticMarketingAction::STATUS_COMPLETED,
                'failed_action_type' => $status === AgenticMarketingAction::STATUS_FAILED,
                'high_cost_low_impact' => $creditsUsed >= 25 && $impactScore <= 0,
                'page_improved_after_refresh' => $this->pageImprovedAfterRefresh($action, $deltas),
                'topic' => $this->topicFor($action),
            ],
        ];

        $signal['summary'] = $this->summaryFor($signal);

        return $signal;
    }

    private function resolveContent(AgenticMarketingAction $action): ?Content
    {
        $contentId = $action->content_id ?: data_get($action->result, 'created_content_id') ?: data_get($action->payload, 'content_id');

        return $contentId ? Content::query()->find($contentId) : null;
    }

    /**
     * @return array<string,int|null>
     */
    private function beforeScores(array $payload, array $result): array
    {
        return [
            'ai_visibility' => $this->numeric(data_get($result, 'scores.before.ai_visibility') ?? data_get($payload, 'learning.before.ai_visibility') ?? data_get($payload, 'metrics_before.ai_visibility_score') ?? data_get($payload, 'ai_visibility_score_before')),
            'lifecycle' => $this->numeric(data_get($result, 'scores.before.lifecycle') ?? data_get($payload, 'learning.before.lifecycle') ?? data_get($payload, 'metrics_before.lifecycle_score') ?? data_get($payload, 'content_health_score_before')),
            'seo_quality' => $this->numeric(data_get($result, 'scores.before.seo_quality') ?? data_get($payload, 'learning.before.seo_quality') ?? data_get($payload, 'metrics_before.seo_quality_score') ?? data_get($payload, 'seo_quality_score_before')),
        ];
    }

    /**
     * @return array<string,int|null>
     */
    private function afterScores(?Content $content, array $result): array
    {
        return [
            'ai_visibility' => $this->numeric(data_get($result, 'scores.after.ai_visibility') ?? data_get($result, 'ai_visibility_score_after') ?? $content?->ai_visibility_score),
            'lifecycle' => $this->numeric(data_get($result, 'scores.after.lifecycle') ?? data_get($result, 'lifecycle_score_after') ?? $content?->content_health_score),
            'seo_quality' => $this->numeric(data_get($result, 'scores.after.seo_quality') ?? data_get($result, 'seo_quality_score_after') ?? $content?->aeo_score),
        ];
    }

    /**
     * @param  array<string,int|null>  $before
     * @param  array<string,int|null>  $after
     * @return array<string,int|null>
     */
    private function scoreDeltas(array $before, array $after): array
    {
        return collect($after)
            ->mapWithKeys(fn (?int $value, string $key): array => [
                $key => $value !== null && $before[$key] !== null ? $value - $before[$key] : null,
            ])
            ->all();
    }

    private function impactScore(array $deltas, array $result): int
    {
        $explicit = $this->numeric(data_get($result, 'impact_score'));
        if ($explicit !== null) {
            return max(0, $explicit);
        }

        return max(0, (int) collect($deltas)->filter(fn ($value): bool => is_numeric($value))->sum());
    }

    private function jobDurationSeconds(AgenticMarketingAction $action, ?AgenticActionRun $run): ?int
    {
        $started = $action->started_at ?: $action->execution_claimed_at ?: $run?->created_at;
        $ended = $action->completed_at ?: $action->failed_at ?: $run?->updated_at;

        return $started && $ended ? max(0, $started->diffInSeconds($ended)) : null;
    }

    private function contentCreated(AgenticMarketingAction $action, array $result): bool
    {
        return (string) $action->action_type === 'create_article' || filled(data_get($result, 'created_content_id'));
    }

    private function contentPublished(?Content $content, array $result): bool
    {
        return (bool) data_get($result, 'content_published', false)
            || in_array((string) ($content?->publish_status ?: $content?->status), ['published', 'synced'], true);
    }

    private function contentRefreshed(AgenticMarketingAction $action, array $result): bool
    {
        return (string) $action->action_type === 'refresh_article' && (filled(data_get($result, 'created_draft_id')) || filled(data_get($result, 'applied_changes')));
    }

    private function internalLinksAdded(?Content $content, array $result): int
    {
        return max(
            (int) data_get($result, 'internal_links_added', 0),
            (int) data_get($result, 'applied_count', 0),
            (int) data_get($content?->internal_links_meta, 'applied_count', 0)
        );
    }

    private function answerBlocksAdded(?Content $content, array $result): int
    {
        return max(
            (int) data_get($result, 'answer_blocks_added', 0),
            (int) data_get($result, 'persisted_answer_blocks', 0),
            (int) ($content?->answer_block_generation_persisted_count ?? 0)
        );
    }

    private function publicationStatus(?Content $content, array $result): ?string
    {
        $status = data_get($result, 'publication_status') ?: $content?->publish_status ?: $content?->delivery_status ?: $content?->status;

        return is_scalar($status) && trim((string) $status) !== '' ? trim((string) $status) : null;
    }

    private function pageImprovedAfterRefresh(AgenticMarketingAction $action, array $deltas): bool
    {
        return (string) $action->action_type === 'refresh_article'
            && collect($deltas)->filter(fn ($value): bool => is_numeric($value) && $value > 0)->isNotEmpty();
    }

    private function topicFor(AgenticMarketingAction $action): string
    {
        return Str::of((string) (
            data_get($action->payload, 'topic')
            ?: data_get($action->payload, 'primary_keyword')
            ?: $action->opportunity?->title
            ?: $action->objective?->goal
            ?: $action->action_type
        ))->lower()->squish()->limit(120, '')->toString();
    }

    private function numeric(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    /**
     * @param  array<string,mixed>  $signal
     */
    private function summaryFor(array $signal): string
    {
        if ((bool) data_get($signal, 'failed')) {
            return 'Action failed; type and cost were recorded for future recommendations.';
        }

        $parts = [];
        if (data_get($signal, 'measurements.content_created')) {
            $parts[] = 'content created';
        }
        if (data_get($signal, 'measurements.content_published')) {
            $parts[] = 'content published';
        }
        if ((int) data_get($signal, 'measurements.internal_links_added', 0) > 0) {
            $parts[] = data_get($signal, 'measurements.internal_links_added').' internal links added';
        }
        if ((int) data_get($signal, 'measurements.answer_blocks_added', 0) > 0) {
            $parts[] = data_get($signal, 'measurements.answer_blocks_added').' answer blocks added';
        }
        if ((int) data_get($signal, 'impact_score', 0) > 0) {
            $parts[] = 'impact +'.data_get($signal, 'impact_score');
        }

        return $parts !== []
            ? ucfirst(implode(', ', $parts)).'.'
            : 'Action completed and measurable outcomes were recorded.';
    }

    /**
     * @param  array<string,mixed>  $signal
     */
    private function storeOnAction(AgenticMarketingAction $action, array $signal): void
    {
        $result = (array) ($action->result ?? []);
        $history = collect((array) data_get($result, 'learning_signals.history', []))
            ->prepend($this->compactSignal($signal))
            ->take(20)
            ->values()
            ->all();

        data_set($result, 'learning_signal', $signal);
        data_set($result, 'learning_signals.latest', $this->compactSignal($signal));
        data_set($result, 'learning_signals.history', $history);

        $action->forceFill(['result' => $result])->saveQuietly();
    }

    /**
     * @param  array<string,mixed>  $signal
     */
    private function storeOnOpportunity(?AgenticMarketingOpportunity $opportunity, array $signal): void
    {
        if (! $opportunity) {
            return;
        }

        $payload = (array) ($opportunity->payload ?? []);
        $history = collect((array) data_get($payload, 'learning_signals.history', []))
            ->prepend($this->compactSignal($signal))
            ->take(30)
            ->values()
            ->all();
        $aggregates = (array) data_get($payload, 'learning_signals.aggregates', []);
        $actionType = (string) data_get($signal, 'action_type');
        $topic = (string) data_get($signal, 'classifiers.topic');
        $topicKey = Str::slug($topic) ?: hash('xxh3', $topic);

        Arr::set($aggregates, 'action_types.'.$actionType.'.completed', (int) data_get($aggregates, 'action_types.'.$actionType.'.completed', 0) + ((bool) data_get($signal, 'success') ? 1 : 0));
        Arr::set($aggregates, 'action_types.'.$actionType.'.failed', (int) data_get($aggregates, 'action_types.'.$actionType.'.failed', 0) + ((bool) data_get($signal, 'failed') ? 1 : 0));
        Arr::set($aggregates, 'high_cost_low_impact', (int) data_get($aggregates, 'high_cost_low_impact', 0) + ((bool) data_get($signal, 'classifiers.high_cost_low_impact') ? 1 : 0));
        Arr::set($aggregates, 'refresh_improved_pages', (int) data_get($aggregates, 'refresh_improved_pages', 0) + ((bool) data_get($signal, 'classifiers.page_improved_after_refresh') ? 1 : 0));
        Arr::set($aggregates, 'topics.'.$topicKey, [
            'topic' => $topic,
            'repeat_count' => $this->topicRepeatCount($opportunity, $topic),
        ]);

        data_set($payload, 'learning_signals.latest', $this->compactSignal($signal));
        data_set($payload, 'learning_signals.history', $history);
        data_set($payload, 'learning_signals.aggregates', $aggregates);
        data_set($payload, 'learning_signals.topic_normalized', $topic);

        $opportunity->forceFill(['payload' => $payload])->saveQuietly();
    }

    /**
     * @param  array<string,mixed>  $signal
     */
    private function storeOnRun(AgenticActionRun $run, array $signal): void
    {
        $output = (array) ($run->output_snapshot ?? []);
        data_set($output, 'learning_signal', $signal);
        $run->forceFill(['output_snapshot' => $output])->saveQuietly();
    }

    private function topicRepeatCount(AgenticMarketingOpportunity $opportunity, string $topic): int
    {
        if ($topic === '') {
            return 0;
        }

        return AgenticMarketingOpportunity::query()
            ->where('objective_id', $opportunity->objective_id)
            ->get(['id', 'title', 'payload'])
            ->filter(function (AgenticMarketingOpportunity $row) use ($topic): bool {
                $candidate = Str::of((string) (data_get($row->payload, 'learning_signals.topic_normalized') ?: data_get($row->payload, 'topic') ?: $row->title))
                    ->lower()
                    ->squish()
                    ->limit(120, '')
                    ->toString();

                return $candidate === $topic;
            })
            ->count();
    }

    /**
     * @param  array<string,mixed>  $signal
     * @return array<string,mixed>
     */
    private function compactSignal(array $signal): array
    {
        return Arr::only($signal, [
            'recorded_at',
            'action_id',
            'action_type',
            'status',
            'success',
            'failed',
            'measurements',
            'scores',
            'impact_score',
            'cost_per_impact_point',
            'classifiers',
            'summary',
        ]);
    }
}
