<?php

namespace App\Agents\ContentRefresh;

use App\Models\Content;
use App\Models\Draft;

class ContentRefreshPlanner
{
    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $scorecard
     * @return array{
     *   reasons:array<int,array<string,mixed>>,
     *   suggested_actions:array<int,array<string,mixed>>
     * }
     */
    public function plan(array $input, array $scorecard): array
    {
        /** @var Content $content */
        $content = $input['content'];
        /** @var Draft|null $latestDraft */
        $latestDraft = $input['latest_draft'] instanceof Draft ? $input['latest_draft'] : null;

        $reasons = collect((array) ($scorecard['signals'] ?? []))
            ->sortByDesc(fn (array $signal): int => (int) ($signal['score'] ?? 0))
            ->map(fn (array $signal): array => [
                'title' => (string) ($signal['title'] ?? 'Refresh signal'),
                'description' => (string) ($signal['description'] ?? ''),
                'score' => (int) ($signal['score'] ?? 0),
                'signal_key' => (string) ($signal['key'] ?? ''),
            ])
            ->values()
            ->all();

        $actions = collect((array) ($scorecard['signals'] ?? []))
            ->pluck('action')
            ->filter(fn (?string $action): bool => is_string($action) && trim($action) !== '')
            ->unique()
            ->map(function (string $action) use ($content): array {
                return match ($action) {
                    'improve headings' => [
                        'title' => 'Improve headings',
                        'description' => 'Tighten the title/H1 structure and make the section hierarchy clearer for refresh work.',
                        'action_key' => 'improve_headings',
                    ],
                    'expand FAQs' => [
                        'title' => 'Expand FAQs',
                        'description' => 'Add supporting questions or richer explanations where the article is still too thin.',
                        'action_key' => 'expand_faqs',
                    ],
                    'update examples' => [
                        'title' => 'Update examples',
                        'description' => 'Replace stale references, years, or weak proof points with fresher examples.',
                        'action_key' => 'update_examples',
                    ],
                    'improve internal linking' => [
                        'title' => 'Improve internal linking',
                        'description' => 'Strengthen related-article coverage so this piece supports the broader content graph.',
                        'action_key' => 'improve_internal_linking',
                        'href' => route('app.content.show', ['content' => $content, 'tab' => 'overview']),
                    ],
                    default => [
                        'title' => 'Generate refresh draft',
                        'description' => 'Create an editorial refresh draft so updates can be reviewed before publishing.',
                        'action_key' => 'generate_refresh_draft',
                    ],
                };
            })
            ->values();

        if ($actions->doesntContain(fn (array $action): bool => (string) ($action['action_key'] ?? '') === 'generate_refresh_draft')) {
            $actions->prepend([
                'title' => 'Generate refresh draft',
                'description' => 'Create an editorial refresh draft so updates can be reviewed before publishing.',
                'action_key' => 'generate_refresh_draft',
            ]);
        }

        if ($latestDraft) {
            $actions->push([
                'title' => 'Open latest draft',
                'description' => 'Review the current editable draft before deciding whether a dedicated refresh draft is needed.',
                'action_key' => 'open_latest_draft',
                'href' => route('app.drafts.show', $latestDraft),
            ]);
        }

        return [
            'reasons' => $reasons,
            'suggested_actions' => $actions->unique(fn (array $action): string => (string) ($action['action_key'] ?? $action['title']))->values()->all(),
        ];
    }
}
