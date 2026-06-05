<?php

namespace App\Support\AgenticMarketing;

class AgenticMarketingDedupe
{
    public static function payloadHash(?array $payload): string
    {
        return hash('sha256', self::canonicalJson(self::identityPayload($payload ?? [])));
    }

    public static function opportunityHash(?string $contentId, ?string $type, string $payloadHash): string
    {
        return hash('sha256', implode('|', [
            'opportunity',
            trim((string) $contentId) !== '' ? trim((string) $contentId) : 'none',
            trim((string) $type) !== '' ? trim((string) $type) : 'unknown',
            $payloadHash,
        ]));
    }

    public static function actionHash(?string $actionType, string $payloadHash): string
    {
        return hash('sha256', implode('|', [
            'action',
            trim((string) $actionType) !== '' ? trim((string) $actionType) : 'unknown',
            $payloadHash,
        ]));
    }

    public static function canonicalJson(array $payload): string
    {
        return (string) json_encode(self::normalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => self::normalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }

    private static function identityPayload(array $payload): array
    {
        if (isset($payload['dedupe_key'])) {
            return array_filter([
                'detector' => $payload['detector'] ?? null,
                'signal_type' => $payload['signal_type'] ?? null,
                'dedupe_key' => $payload['dedupe_key'],
                'content_id' => $payload['content_id'] ?? null,
            ], fn (mixed $value): bool => $value !== null && $value !== '');
        }

        unset($payload['score_explanation'], $payload['scoring'], $payload['planning']);

        return $payload;
    }
}
