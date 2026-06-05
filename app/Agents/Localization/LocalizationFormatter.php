<?php

namespace App\Agents\Localization;

use App\Models\ClientSite;

class LocalizationFormatter
{
    /**
     * @param array<string,mixed> $input
     * @param array<int,array<string,mixed>> $issues
     * @param array<int,array<string,mixed>> $actions
     * @return array<string,mixed>
     */
    public function format(array $input, array $issues, array $actions): array
    {
        /** @var ClientSite|null $site */
        $site = $input['site'] ?? null;
        $siteName = trim((string) ($site?->name ?: 'this site'));
        $locale = strtoupper((string) ($input['declared_locale'] ?? 'N/A'));
        $resourceType = (string) ($input['resource_type'] ?? 'content');

        $summary = $issues === []
            ? sprintf('No material localization issues were found for this %s %s on %s.', $locale, $resourceType, $siteName)
            : sprintf(
                '%d localization recommendation%s found for this %s %s on %s.',
                count($issues),
                count($issues) === 1 ? '' : 's',
                $locale,
                $resourceType,
                $siteName
            );

        return [
            'summary' => $summary,
            'recommendations' => $issues,
            'actions' => $actions,
        ];
    }
}
