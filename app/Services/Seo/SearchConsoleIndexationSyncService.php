<?php

namespace App\Services\Seo;

use App\Models\Content;
use Illuminate\Support\Collection;

class SearchConsoleIndexationSyncService
{
    /**
     * @param  Collection<int,Content>  $contents
     * @param  array<string,array<string,mixed>>  $payloadByContentId
     * @return array{synced:int,skipped:int}
     */
    public function sync(Collection $contents, array $payloadByContentId = []): array
    {
        $synced = 0;
        $skipped = 0;
        $health = app(ContentIndexationHealthService::class);

        foreach ($contents as $content) {
            $payload = (array) ($payloadByContentId[(string) $content->id] ?? []);

            if ($payload === []) {
                $skipped++;
                continue;
            }

            $health->syncSearchConsole($content, $payload);
            $synced++;
        }

        return [
            'synced' => $synced,
            'skipped' => $skipped,
        ];
    }
}
