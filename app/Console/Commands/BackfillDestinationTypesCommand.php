<?php

namespace App\Console\Commands;

use App\Enums\ContentDestinationType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillDestinationTypesCommand extends Command
{
    protected $signature = 'content:backfill-destination-types {--dry-run : Report changes without writing them}';

    protected $description = 'Normalize content destination and publication types to canonical destination-native values.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $siteTypes = DB::table('client_sites')->pluck('type', 'id');

        $destinationUpdates = [];
        foreach (DB::table('content_destinations')->select(['id', 'type', 'config', 'webhook_url'])->get() as $destination) {
            $config = json_decode((string) ($destination->config ?? '[]'), true);
            $billingSiteId = trim((string) data_get($config, 'billing_client_site_id', ''));
            $normalized = ContentDestinationType::normalize($destination->type)
                ?? ContentDestinationType::normalize($siteTypes[$billingSiteId] ?? null)
                ?? (is_array(data_get($config, 'laravel_connector')) ? ContentDestinationType::LARAVEL->value : null)
                ?? (trim((string) $destination->webhook_url) !== '' ? ContentDestinationType::API->value : null)
                ?? ContentDestinationType::API->value;

            if ($normalized !== (string) $destination->type) {
                $destinationUpdates[] = [
                    'id' => (string) $destination->id,
                    'from' => (string) $destination->type,
                    'to' => $normalized,
                ];

                if (! $dryRun) {
                    DB::table('content_destinations')->where('id', $destination->id)->update(['type' => $normalized]);
                }
            }
        }

        $destinationTypes = DB::table('content_destinations')->pluck('type', 'id');
        $publicationUpdates = [];

        foreach (DB::table('content_publications')->select(['id', 'provider', 'destination_id', 'client_site_id'])->get() as $publication) {
            $normalized = ContentDestinationType::normalize($publication->provider)
                ?? ContentDestinationType::normalize($destinationTypes[$publication->destination_id] ?? null)
                ?? ContentDestinationType::normalize($siteTypes[$publication->client_site_id] ?? null)
                ?? ContentDestinationType::API->value;

            if ($normalized !== (string) $publication->provider) {
                $publicationUpdates[] = [
                    'id' => (string) $publication->id,
                    'from' => (string) $publication->provider,
                    'to' => $normalized,
                ];

                if (! $dryRun) {
                    DB::table('content_publications')->where('id', $publication->id)->update(['provider' => $normalized]);
                }
            }
        }

        $this->table(['scope', 'id', 'from', 'to'], [
            ...array_map(fn (array $row): array => ['destination', $row['id'], $row['from'], $row['to']], $destinationUpdates),
            ...array_map(fn (array $row): array => ['publication', $row['id'], $row['from'], $row['to']], $publicationUpdates),
        ]);

        $this->info(sprintf(
            '%s %d destination updates and %d publication updates.',
            $dryRun ? 'Would apply' : 'Applied',
            count($destinationUpdates),
            count($publicationUpdates),
        ));

        return self::SUCCESS;
    }
}
