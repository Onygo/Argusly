<?php

use App\Enums\ContentDestinationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $siteTypes = DB::table('client_sites')
            ->pluck('type', 'id');

        $destinations = DB::table('content_destinations')
            ->select(['id', 'type', 'config', 'webhook_url'])
            ->get();

        foreach ($destinations as $destination) {
            $config = json_decode((string) ($destination->config ?? '[]'), true);
            $billingSiteId = trim((string) data_get($config, 'billing_client_site_id', ''));
            $normalizedType = ContentDestinationType::normalize($destination->type)
                ?? ContentDestinationType::normalize($siteTypes[$billingSiteId] ?? null)
                ?? (is_array(data_get($config, 'laravel_connector')) ? ContentDestinationType::LARAVEL->value : null)
                ?? (trim((string) $destination->webhook_url) !== '' ? ContentDestinationType::API->value : null)
                ?? ContentDestinationType::API->value;

            DB::table('content_destinations')
                ->where('id', $destination->id)
                ->update(['type' => $normalizedType]);
        }

        $destinationTypes = DB::table('content_destinations')
            ->pluck('type', 'id');

        $publications = DB::table('content_publications')
            ->select(['id', 'provider', 'destination_id', 'client_site_id'])
            ->get();

        foreach ($publications as $publication) {
            $normalizedType = ContentDestinationType::normalize($publication->provider)
                ?? ContentDestinationType::normalize($destinationTypes[$publication->destination_id] ?? null)
                ?? ContentDestinationType::normalize($siteTypes[$publication->client_site_id] ?? null)
                ?? ContentDestinationType::API->value;

            DB::table('content_publications')
                ->where('id', $publication->id)
                ->update(['provider' => $normalizedType]);
        }
    }

    public function down(): void
    {
        DB::table('content_destinations')
            ->where('type', ContentDestinationType::API->value)
            ->update(['type' => 'api_only']);
    }
};
