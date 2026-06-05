<?php

namespace App\Services\WordPress\Data;

use App\Services\WordPress\Exceptions\MalformedResponseException;

final class WordPressPost
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $publishedUrl,
        public readonly ?string $status,
        public readonly int $modifiedTs,
        public readonly ?int $httpStatus,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws MalformedResponseException
     */
    public static function fromPayload(array $payload, ?string $fallbackId = null, ?int $httpStatus = null): self
    {
        $id = self::extractString($payload, [
            'wp_post_id',
            'post_id',
            'id',
            'data.wp_post_id',
            'data.post_id',
            'data.id',
        ]);

        if ($id === '' && $fallbackId !== null) {
            $id = trim($fallbackId);
        }

        if ($id === '') {
            throw new MalformedResponseException(
                'WordPress response did not include a post identifier.',
                $httpStatus,
                null,
                $payload,
            );
        }

        $modified = self::extractString($payload, [
            'modified_gmt',
            'modified',
            'date_gmt',
            'date',
            'data.modified',
            'data.modified_gmt',
            'data.date_gmt',
            'data.date',
        ]);

        return new self(
            id: $id,
            publishedUrl: self::extractNullableString($payload, [
                'published_url',
                'url',
                'permalink',
                'link',
                'data.url',
                'data.link',
                'data.permalink',
            ]),
            status: self::extractNullableString($payload, [
                'status',
                'post_status',
                'data.status',
                'data.post_status',
            ]),
            modifiedTs: self::parseModifiedTimestamp($modified),
            httpStatus: $httpStatus,
            raw: $payload,
        );
    }

    /**
     * @return array{wp_post_id:string,published_url:?string,status:?string,modified_ts:int,http_status:?int,raw:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'wp_post_id' => $this->id,
            'published_url' => $this->publishedUrl,
            'status' => $this->status,
            'modified_ts' => $this->modifiedTs,
            'http_status' => $this->httpStatus,
            'raw' => $this->raw,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $paths
     */
    private static function extractString(array $payload, array $paths): string
    {
        foreach ($paths as $path) {
            $value = trim((string) data_get($payload, $path, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $paths
     */
    private static function extractNullableString(array $payload, array $paths): ?string
    {
        $value = self::extractString($payload, $paths);

        return $value !== '' ? $value : null;
    }

    private static function parseModifiedTimestamp(string $value): int
    {
        if ($value === '') {
            return 0;
        }

        try {
            return strtotime($value) ?: 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
