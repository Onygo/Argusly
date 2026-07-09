<?php

namespace App\Console\Commands;

use App\Models\ContentImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class RepairMissingContentImageFilesCommand extends Command
{
    protected $signature = 'content-images:repair-missing-files
        {--id=* : Limit to one or more content image ids}
        {--search=* : Directory to search for backed-up files; repeatable}
        {--limit=0 : Maximum images to inspect; 0 means no limit}
        {--output-limit=50 : Maximum missing/restored file rows to print}
        {--restore : Copy found files to the configured image disk}
        {--with-trashed : Include soft-deleted image records}
        {--status=ready : Content image status filter; leave empty for all statuses}';

    protected $description = 'Audit and restore missing generated/uploaded content image files from old releases or backups.';

    public function handle(): int
    {
        $diskName = $this->imageDiskName();
        $disk = Storage::disk($diskName);
        $restore = (bool) $this->option('restore');
        $searchRoots = $this->searchRoots();
        $outputLimit = max(0, (int) $this->option('output-limit'));

        $stats = [
            'images_inspected' => 0,
            'images_without_local_paths' => 0,
            'images_with_existing_file' => 0,
            'images_missing_all_files' => 0,
            'files_checked' => 0,
            'files_existing' => 0,
            'files_missing' => 0,
            'files_found_in_search' => 0,
            'files_restored' => 0,
            'files_still_missing' => 0,
        ];

        $details = [];
        $limit = max(0, (int) $this->option('limit'));
        $processed = 0;

        foreach ($this->contentImageQuery()->cursor() as $image) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            $processed++;
            $stats['images_inspected']++;

            $paths = $this->candidatePaths($image);
            if ($paths === []) {
                $stats['images_without_local_paths']++;

                continue;
            }

            $imageHasExistingFile = false;
            $imageHasRestoredFile = false;

            foreach ($paths as $path) {
                $stats['files_checked']++;

                if ($disk->exists($path)) {
                    $stats['files_existing']++;
                    $imageHasExistingFile = true;

                    continue;
                }

                $stats['files_missing']++;
                $found = $this->findBackupFile($path, $searchRoots);

                if ($found === null) {
                    $stats['files_still_missing']++;
                    $this->appendDetail($details, $outputLimit, $image, $path, 'missing', '');

                    continue;
                }

                $stats['files_found_in_search']++;

                if (! $restore) {
                    $stats['files_still_missing']++;
                    $this->appendDetail($details, $outputLimit, $image, $path, 'found', $found);

                    continue;
                }

                if ($this->restoreFile($diskName, $path, $found)) {
                    $stats['files_restored']++;
                    $imageHasExistingFile = true;
                    $imageHasRestoredFile = true;
                    $this->appendDetail($details, $outputLimit, $image, $path, 'restored', $found);

                    continue;
                }

                $stats['files_still_missing']++;
                $this->appendDetail($details, $outputLimit, $image, $path, 'restore_failed', $found);
            }

            if ($imageHasExistingFile || $imageHasRestoredFile) {
                $stats['images_with_existing_file']++;
            } else {
                $stats['images_missing_all_files']++;
            }
        }

        $this->line('Image disk: '.$diskName);
        $this->line('Search roots: '.($searchRoots === [] ? 'none' : implode(', ', $searchRoots)));
        $this->table(['metric', 'count'], collect($stats)->map(fn (int $count, string $metric): array => [$metric, $count])->values()->all());

        if ($details !== []) {
            $this->table(['image_id', 'content_id', 'path', 'status', 'source'], $details);
        }

        if (! $restore && $stats['files_found_in_search'] > 0) {
            $this->warn('Restorable files found. Re-run with --restore to copy them into persistent image storage.');
        }

        return $stats['files_still_missing'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function imageDiskName(): string
    {
        return (string) config('argusly.images.disk', config('argusly.ai.images.storage_disk', 'content_images'));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<ContentImage>
     */
    private function contentImageQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = (bool) $this->option('with-trashed')
            ? ContentImage::withTrashed()
            : ContentImage::query();

        $status = trim((string) $this->option('status'));
        if ($status !== '') {
            $query->where('status', $status);
        }

        $ids = collect((array) $this->option('id'))
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->values();

        if ($ids->isNotEmpty()) {
            $query->whereIn('id', $ids->all());
        }

        return $query->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return array<int,string>
     */
    private function searchRoots(): array
    {
        $roots = [];

        foreach ((array) $this->option('search') as $root) {
            $root = trim((string) $root);
            if ($root === '') {
                continue;
            }

            $realPath = realpath($root);
            if ($realPath === false || ! File::isDirectory($realPath)) {
                $this->warn("Search root does not exist or is not a directory: {$root}");

                continue;
            }

            $roots[] = $realPath;
        }

        return array_values(array_unique($roots));
    }

    /**
     * @return array<int,string>
     */
    private function candidatePaths(ContentImage $image): array
    {
        $metadata = is_array($image->metadata) ? $image->metadata : [];
        $values = [
            $image->medium_webp_path,
            $image->medium_path,
            $image->original_webp_path,
            $image->original_path,
            $image->thumbnail_webp_path,
            $image->thumbnail_path,
            $image->image_path,
            $image->image_url,
            data_get($metadata, 'asset_url'),
            data_get($metadata, 'remote_url'),
            data_get($metadata, 'wp.featured_image_url'),
            data_get($metadata, 'wp.attachment_url'),
        ];

        return collect($values)
            ->map(fn (mixed $value): ?string => $this->normalizeContentImagePath((string) ($value ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeContentImagePath(string $value): ?string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '//')) {
            $value = 'https:'.$value;
        }

        $path = (string) (parse_url($value, PHP_URL_PATH) ?: $value);
        $relative = ltrim($path, '/');
        $directory = ContentImage::storageDirectory();

        foreach ([
            'public/'.$directory.'/',
            'storage/'.$directory.'/',
            $directory.'/',
        ] as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return $directory.'/'.substr($relative, strlen($prefix));
            }
        }

        return null;
    }

    /**
     * @param array<int,string> $searchRoots
     */
    private function findBackupFile(string $path, array $searchRoots): ?string
    {
        foreach ($searchRoots as $root) {
            foreach ($this->backupCandidates($root, $path) as $candidate) {
                if (File::isFile($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function backupCandidates(string $root, string $path): array
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $directory = ContentImage::storageDirectory();
        $pathInsideDirectory = str_starts_with($path, $directory.'/')
            ? substr($path, strlen($directory) + 1)
            : $path;

        $candidates = [
            $root.'/'.$path,
            $root.'/public/'.$path,
            $root.'/public/storage/'.$path,
            $root.'/storage/app/public/'.$path,
            $root.'/storage/app/'.$path,
        ];

        if (basename($root) === $directory) {
            $candidates[] = $root.'/'.$pathInsideDirectory;
        }

        return array_values(array_unique($candidates));
    }

    private function restoreFile(string $diskName, string $path, string $source): bool
    {
        $stream = fopen($source, 'rb');
        if ($stream === false) {
            return false;
        }

        try {
            return Storage::disk($diskName)->put($path, $stream) === true;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param array<int,array<int,string>> $details
     */
    private function appendDetail(array &$details, int $limit, ContentImage $image, string $path, string $status, string $source): void
    {
        if ($limit > 0 && count($details) >= $limit) {
            return;
        }

        $details[] = [
            (string) $image->id,
            (string) ($image->content_id ?? ''),
            $path,
            $status,
            $source,
        ];
    }
}
