<?php

namespace App\Console\Commands;

use App\Models\ContentImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ArguslyDiagnosticsCommand extends Command
{
    protected $signature = 'argusly:diagnostics';

    protected $description = 'Show effective Argusly server and connector configuration (safe fields only).';

    public function handle(): int
    {
        $webhookSecret = trim((string) config('argusly.webhooks.secret', ''));
        $connectorApiKey = trim((string) config('argusly_connector.api.api_key', config('argusly_connector.api_key', '')));
        $imageDisk = (string) config('argusly.images.disk', 'content_images');
        $imageDirectory = ContentImage::storageDirectory();
        $imageStorageDirectory = storage_path('app/public/'.$imageDirectory);
        $imagePublicLink = public_path($imageDirectory);

        $this->table(['setting', 'value'], [
            ['webhooks.secret', $webhookSecret !== '' ? 'set' : 'missing'],
            ['webhooks.connector_public_url', (string) config('argusly.webhooks.connector_public_url', '')],
            ['webhooks.queue', (string) config('argusly.webhooks.queue', 'deliveries')],
            ['images.enabled', (bool) config('argusly.images.enabled', true) ? 'true' : 'false'],
            ['images.disk', $imageDisk],
            ['images.path', $imageDirectory],
            ['images.disk.root', (string) config("filesystems.disks.{$imageDisk}.root", '')],
            ['images.disk.url', (string) config("filesystems.disks.{$imageDisk}.url", '')],
            ['images.storage_dir', File::isDirectory($imageStorageDirectory) ? 'exists' : "missing; create {$imageStorageDirectory}"],
            ['images.public_link', $this->publicLinkStatus($imagePublicLink, $imageStorageDirectory)],
            ['connector.api.base_url', (string) config('argusly_connector.api.base_url', config('argusly_connector.base_url', ''))],
            ['connector.api.workspace_id', (string) config('argusly_connector.api.workspace_id', config('argusly_connector.workspace_id', ''))],
            ['connector.api.api_key', $connectorApiKey !== '' ? 'set' : 'missing'],
        ]);

        return self::SUCCESS;
    }

    private function publicLinkStatus(string $link, string $expectedTarget): string
    {
        if (is_link($link)) {
            $target = (string) readlink($link);

            return $target === $expectedTarget
                ? 'linked'
                : "linked to {$target} (expected {$expectedTarget})";
        }

        if (File::exists($link)) {
            return 'exists but is not a symlink';
        }

        return "missing; run php artisan storage:link --force";
    }
}
