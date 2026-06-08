<?php

namespace App\Services\PluginUpdates;

use App\Models\PluginRelease;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class PluginArchiveUploadService
{
    /**
     * Maximum allowed file size in kilobytes.
     */
    public const MAX_FILE_SIZE_KB = 51200; // 50MB

    /**
     * Analyze the upload request and return diagnostic information.
     *
     * @return array{
     *     server_limits: array{upload_max_filesize: string, post_max_size: string, memory_limit: string, max_input_time: string},
     *     request_info: array{content_length: int|null, has_file: bool, file_error: int|null, file_error_message: string|null},
     *     diagnosis: string|null,
     *     can_proceed: bool
     * }
     */
    public function analyzeUploadRequest(Request $request): array
    {
        $serverLimits = $this->getServerLimits();
        $requestInfo = $this->getRequestInfo($request);
        $diagnosis = $this->diagnoseUploadIssue($request, $serverLimits, $requestInfo);

        return [
            'server_limits' => $serverLimits,
            'request_info' => $requestInfo,
            'diagnosis' => $diagnosis,
            'can_proceed' => $diagnosis === null,
        ];
    }

    /**
     * Get current server upload limits.
     *
     * @return array{upload_max_filesize: string, post_max_size: string, memory_limit: string, max_input_time: string}
     */
    public function getServerLimits(): array
    {
        return [
            'upload_max_filesize' => ini_get('upload_max_filesize') ?: 'unknown',
            'post_max_size' => ini_get('post_max_size') ?: 'unknown',
            'memory_limit' => ini_get('memory_limit') ?: 'unknown',
            'max_input_time' => ini_get('max_input_time') ?: 'unknown',
        ];
    }

    /**
     * Get request-level information about the upload.
     *
     * @return array{content_length: int|null, has_file: bool, file_error: int|null, file_error_message: string|null}
     */
    public function getRequestInfo(Request $request): array
    {
        $file = $request->file('archive');
        $hasFile = $file instanceof UploadedFile;

        return [
            'content_length' => $request->header('Content-Length') ? (int) $request->header('Content-Length') : null,
            'has_file' => $hasFile,
            'file_error' => $hasFile ? $file->getError() : null,
            'file_error_message' => $hasFile ? $this->translatePhpUploadError($file->getError()) : null,
        ];
    }

    /**
     * Diagnose upload issues before validation.
     */
    public function diagnoseUploadIssue(Request $request, array $serverLimits, array $requestInfo): ?string
    {
        // Check if request was truncated by server (nginx/PHP)
        $contentLength = $requestInfo['content_length'];
        $postMaxBytes = $this->parsePhpSize($serverLimits['post_max_size']);

        if ($contentLength !== null && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
            return sprintf(
                'Request size (%s) exceeds PHP post_max_size (%s). Increase post_max_size in php.ini.',
                $this->formatBytes($contentLength),
                $serverLimits['post_max_size']
            );
        }

        // Check if request body is empty (often indicates nginx blocked it)
        if (! $requestInfo['has_file'] && $request->isMethod('POST')) {
            $expectedSize = $contentLength ?? 0;

            if ($expectedSize > 0) {
                return 'Upload blocked by server. The file did not reach Laravel. Check nginx client_max_body_size (recommended: 64M) and PHP post_max_size settings.';
            }

            return 'No file was included in the upload request.';
        }

        // Check PHP upload error codes
        $fileError = $requestInfo['file_error'];
        if ($fileError !== null && $fileError !== UPLOAD_ERR_OK) {
            return $this->translatePhpUploadError($fileError);
        }

        return null;
    }

    /**
     * Validate the uploaded archive file.
     *
     * @return array{valid: bool, error: string|null, file_info: array<string, mixed>}
     */
    public function validateArchive(UploadedFile $file): array
    {
        $fileInfo = [
            'original_name' => $file->getClientOriginalName(),
            'size_bytes' => $file->getSize(),
            'size_human' => $this->formatBytes($file->getSize()),
            'mime_type' => $file->getMimeType(),
            'extension' => $file->getClientOriginalExtension(),
        ];

        // Check file size
        $maxBytes = self::MAX_FILE_SIZE_KB * 1024;
        if ($file->getSize() > $maxBytes) {
            return [
                'valid' => false,
                'error' => sprintf(
                    'File too large: %s. Maximum allowed size is %s.',
                    $fileInfo['size_human'],
                    $this->formatBytes($maxBytes)
                ),
                'file_info' => $fileInfo,
            ];
        }

        // Check extension
        if (strtolower($file->getClientOriginalExtension()) !== 'zip') {
            return [
                'valid' => false,
                'error' => sprintf(
                    'Invalid file type: .%s. Only .zip files are allowed.',
                    $file->getClientOriginalExtension()
                ),
                'file_info' => $fileInfo,
            ];
        }

        // Verify it's a valid ZIP archive
        $zipValidation = $this->validateZipArchive($file);
        if (! $zipValidation['valid']) {
            return [
                'valid' => false,
                'error' => $zipValidation['error'],
                'file_info' => $fileInfo,
            ];
        }

        return [
            'valid' => true,
            'error' => null,
            'file_info' => array_merge($fileInfo, ['zip_entries' => $zipValidation['entries'] ?? 0]),
        ];
    }

    /**
     * Validate that the file is a proper ZIP archive.
     *
     * @return array{valid: bool, error: string|null, entries: int|null}
     */
    public function validateZipArchive(UploadedFile $file): array
    {
        $zip = new ZipArchive();
        $result = $zip->open($file->getRealPath(), ZipArchive::RDONLY);

        if ($result !== true) {
            return [
                'valid' => false,
                'error' => 'Invalid ZIP archive: ' . $this->translateZipError($result),
                'entries' => null,
            ];
        }

        $entries = $zip->numFiles;

        if ($entries === 0) {
            $zip->close();

            return [
                'valid' => false,
                'error' => 'ZIP archive is empty.',
                'entries' => 0,
            ];
        }

        // Security: Check for zip slip vulnerability (paths with ../)
        for ($i = 0; $i < $entries; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false) {
                continue;
            }

            if (str_contains($entryName, '..') || str_starts_with($entryName, '/')) {
                $zip->close();

                return [
                    'valid' => false,
                    'error' => 'ZIP archive contains potentially unsafe paths.',
                    'entries' => $entries,
                ];
            }
        }

        $zip->close();

        return [
            'valid' => true,
            'error' => null,
            'entries' => $entries,
        ];
    }

    /**
     * Store the uploaded archive and create a plugin release.
     *
     * @return array{success: bool, release: PluginRelease|null, error: string|null, storage_path: string|null}
     */
    public function storeArchive(UploadedFile $file, array $releaseData): array
    {
        $version = trim((string) ($releaseData['version'] ?? ''));
        $disk = (string) config('argusly.plugin_updates.disk', 'local');

        $filename = sprintf(
            'argusly-wordpress-plugin-%s-%s.zip',
            preg_replace('/[^0-9A-Za-z.\-_]/', '-', $version) ?: 'release',
            now()->format('YmdHis')
        );

        Log::info('PluginArchiveUpload: Storing archive', [
            'version' => $version,
            'filename' => $filename,
            'disk' => $disk,
            'file_size' => $file->getSize(),
        ]);

        try {
            $path = Storage::disk($disk)->putFileAs('plugin-releases', $file, $filename);

            if (! is_string($path) || trim($path) === '') {
                Log::error('PluginArchiveUpload: Storage returned empty path', [
                    'disk' => $disk,
                    'filename' => $filename,
                ]);

                return [
                    'success' => false,
                    'release' => null,
                    'error' => 'Failed to store archive. Storage returned empty path.',
                    'storage_path' => null,
                ];
            }

            Log::info('PluginArchiveUpload: Archive stored successfully', [
                'path' => $path,
            ]);

            $release = PluginRelease::query()->create([
                'version' => $version,
                'min_wp_version' => $this->nullableTrim($releaseData['min_wp_version'] ?? null),
                'tested_wp_version' => $this->nullableTrim($releaseData['tested_wp_version'] ?? null),
                'zip_storage_path' => $path,
                'is_security_release' => (bool) ($releaseData['is_security_release'] ?? false),
            ]);

            Log::info('PluginArchiveUpload: Release created', [
                'release_id' => $release->id,
                'version' => $release->version,
            ]);

            return [
                'success' => true,
                'release' => $release,
                'error' => null,
                'storage_path' => $path,
            ];
        } catch (\Throwable $e) {
            Log::error('PluginArchiveUpload: Failed to store archive', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'release' => null,
                'error' => 'Failed to store archive: ' . $e->getMessage(),
                'storage_path' => null,
            ];
        }
    }

    /**
     * Translate PHP upload error codes to human-readable messages.
     */
    private function translatePhpUploadError(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_OK => 'Upload successful.',
            UPLOAD_ERR_INI_SIZE => 'File exceeds PHP upload_max_filesize limit. Contact server administrator.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form MAX_FILE_SIZE limit.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder. Contact server administrator.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk. Contact server administrator.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload. Contact server administrator.',
            default => 'Unknown upload error (code: ' . $errorCode . ').',
        };
    }

    /**
     * Translate ZipArchive error codes to human-readable messages.
     */
    private function translateZipError(int $errorCode): string
    {
        return match ($errorCode) {
            ZipArchive::ER_NOZIP => 'File is not a valid ZIP archive.',
            ZipArchive::ER_OPEN => 'Could not open the file.',
            ZipArchive::ER_READ => 'Could not read the file.',
            ZipArchive::ER_SEEK => 'Seek error in archive.',
            ZipArchive::ER_INCONS => 'ZIP archive is inconsistent.',
            ZipArchive::ER_MEMORY => 'Memory allocation failed.',
            ZipArchive::ER_CRC => 'CRC error in archive.',
            ZipArchive::ER_ZLIB => 'Zlib error.',
            default => 'Unknown ZIP error (code: ' . $errorCode . ').',
        };
    }

    /**
     * Parse PHP size string (e.g., "16M", "2G") to bytes.
     */
    private function parsePhpSize(string $size): int
    {
        $size = trim($size);
        if ($size === '' || $size === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtoupper(substr($size, -1));
        $value = (int) substr($size, 0, -1);

        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => (int) $size,
        };
    }

    /**
     * Format bytes as human-readable string.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' bytes';
    }

    private function nullableTrim(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
