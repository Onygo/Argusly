<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\ContentPublishTarget;
use App\Observers\ContentPublicationObserver;
use App\Services\Integrations\LaravelConnectorDestinationResolver;
use App\Services\Publication\LaravelPublicationBridge;
use Illuminate\Console\Command;

class DebugRepublishPublicationCommand extends Command
{
    protected $signature = 'publication:debug-republish {--content-id= : Content UUID to inspect}';

    protected $description = 'Inspect the Laravel republish publication sync state for a content record.';

    public function handle(
        LaravelConnectorDestinationResolver $destinationResolver,
        LaravelPublicationBridge $bridge,
    ): int {
        $contentId = trim((string) $this->option('content-id'));

        if ($contentId === '') {
            $this->error('Provide --content-id=<uuid>.');

            return self::FAILURE;
        }

        $content = Content::query()
            ->with(['clientSite', 'contentDestination', 'currentVersion', 'publications', 'translationSourceContent'])
            ->find($contentId);

        if (! $content) {
            $this->error("Content not found: {$contentId}");

            return self::FAILURE;
        }

        $destination = $destinationResolver->resolveForContent($content);
        $target = ContentPublishTarget::query()
            ->where('content_id', (string) $content->id)
            ->when($destination?->id, fn ($query) => $query->where('content_destination_id', (string) $destination->id))
            ->when(! $destination?->id && $content->client_site_id, fn ($query) => $query->where('client_site_id', (string) $content->client_site_id))
            ->latest('updated_at')
            ->first();

        $publication = ContentPublication::query()
            ->where('content_id', (string) $content->id)
            ->where('provider', ContentPublication::PROVIDER_LARAVEL)
            ->when($destination?->id, fn ($query) => $query->where('destination_id', (string) $destination->id))
            ->when(! $destination?->id && $content->client_site_id, fn ($query) => $query->whereNull('destination_id')->where('client_site_id', (string) $content->client_site_id))
            ->latest('updated_at')
            ->first();

        $publication ??= new ContentPublication([
            'content_id' => (string) $content->id,
            'destination_id' => $destination?->id,
            'client_site_id' => $content->client_site_id,
            'locale' => $content->localeCode(),
            'provider' => ContentPublication::PROVIDER_LARAVEL,
            'delivery_status' => ContentPublication::STATUS_PENDING,
        ]);

        $this->info('Target content');
        $this->table(['Field', 'Value'], [
            ['content_id', (string) $content->id],
            ['title', (string) $content->title],
            ['locale', $content->localeCode()],
            ['source_locale', (string) ($content->translation_source_locale ?? '')],
            ['source_content_id', (string) ($content->translation_source_content_id ?? '')],
            ['site_id', (string) ($content->client_site_id ?? '')],
            ['destination_id', (string) ($destination?->id ?? $content->content_destination_id ?? '')],
            ['slug', (string) ($content->publish_url_key ?? '')],
            ['canonical_path', (string) parse_url((string) ($content->seo_canonical ?? ''), PHP_URL_PATH)],
            ['canonical_url', (string) ($content->seo_canonical ?? '')],
            ['external_key', (string) ($content->external_key ?? '')],
        ]);

        $this->info('Related publication records');
        $this->table(
            ['ID', 'Locale', 'Destination', 'Site', 'Remote ID', 'Remote Status', 'Delivery'],
            $content->publications->map(fn (ContentPublication $row): array => [
                (string) $row->id,
                (string) ($row->locale?->value ?? $row->getRawOriginal('locale') ?? ''),
                (string) ($row->destination_id ?? ''),
                (string) ($row->client_site_id ?? ''),
                (string) ($row->remote_id ?? ''),
                (string) ($row->remote_status ?? ''),
                (string) ($row->delivery_status ?? ''),
            ])->all()
        );

        $this->info('Observer classes attached');
        $this->line('- '.ContentPublicationObserver::class);

        if (! $target) {
            $this->warn('No ContentPublishTarget exists yet for this content/destination. Run a publish or republish attempt first to inspect sync dirty attributes.');

            return self::SUCCESS;
        }

        $preview = $bridge->previewSyncFromTarget($publication, $target, $content);

        $this->info('Dirty attributes that would be saved');
        $this->table(
            ['Attribute', 'Value'],
            collect($preview['dirty_attributes'] ?? [])
                ->map(fn ($value, string $key): array => [$key, is_scalar($value) || $value === null ? (string) $value : json_encode($value)])
                ->values()
                ->all()
        );

        $warnings = (array) ($preview['validation_warnings'] ?? []);
        if ($warnings === []) {
            $this->info('Validation warnings before save: none');
        } else {
            $this->warn('Validation warnings before save:');
            foreach ($warnings as $warning) {
                $this->line('- '.$warning);
            }
        }

        return self::SUCCESS;
    }
}
