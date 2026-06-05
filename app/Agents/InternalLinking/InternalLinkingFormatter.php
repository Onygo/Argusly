<?php

namespace App\Agents\InternalLinking;

class InternalLinkingFormatter
{
    /**
     * @param array<string,mixed> $input
     * @param array<int,array<string,mixed>> $suggestions
     * @return array{summary:string,actions:array<int,array<string,string>>,warnings:array<int,array<string,string>>}
     */
    public function format(array $input, array $suggestions): array
    {
        $locale = strtoupper((string) ($input['source_locale'] ?? 'N/A'));
        $siteName = (string) ($input['site']?->name ?? 'the selected site');
        $resourceType = (string) ($input['resource_type'] ?? 'content');
        $sourceHtml = trim((string) ($input['source_html'] ?? ''));

        $warnings = [];
        if ($sourceHtml === '') {
            $warnings[] = [
                'title' => 'No source body available',
                'description' => 'This run could not inspect the article body, so no internal link opportunities were generated.',
            ];
        }

        $actions = [
            $resourceType === 'draft'
                ? [
                    'title' => 'Apply to draft only',
                    'description' => 'Applying a suggestion updates the draft body without changing live content automatically.',
                ]
                : [
                    'title' => 'Apply through a refresh revision',
                    'description' => 'Applying a suggestion creates a new editable revision instead of overwriting the live article in place.',
                ],
        ];

        $summary = $suggestions === []
            ? sprintf('No same-locale internal link suggestions were found for %s on %s.', $locale, $siteName)
            : sprintf(
                'Found %d suggested internal %s for %s on %s.',
                count($suggestions),
                count($suggestions) === 1 ? 'link' : 'links',
                $locale,
                $siteName
            );

        return [
            'summary' => $summary,
            'actions' => $actions,
            'warnings' => $warnings,
        ];
    }
}
