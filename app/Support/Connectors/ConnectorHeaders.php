<?php

namespace App\Support\Connectors;

use Illuminate\Http\Request;

final class ConnectorHeaders
{
    public const API_KEY = 'X-Argusly-API-Key';
    public const SITE = 'X-Argusly-Site';
    public const DESTINATION_ID = 'X-Argusly-Destination-Id';
    public const IDEMPOTENCY_KEY = 'X-Argusly-Idempotency-Key';
    public const TIMESTAMP = 'X-Argusly-Timestamp';
    public const NONCE = 'X-Argusly-Nonce';
    public const SIGNATURE = 'X-Argusly-Signature';
    public const EVENT = 'X-Argusly-Event';
    public const EVENT_VERSION = 'X-Argusly-Event-Version';
    public const EVENT_ID = 'X-Argusly-Event-ID';
    public const DELIVERY_ATTEMPT = 'X-Argusly-Delivery-Attempt';
    public const CORRELATION_ID = 'X-Argusly-Correlation-Id';
    public const DEPRECATION = 'X-Argusly-Deprecation';

    public static function value(Request $request, string $header): string
    {
        return trim((string) $request->header($header, ''));
    }

    public static function site(Request $request): string
    {
        return self::value($request, self::SITE);
    }

    public static function apiKey(Request $request): string
    {
        return self::value($request, self::API_KEY);
    }

    public static function destinationId(Request $request): string
    {
        return self::value($request, self::DESTINATION_ID);
    }

    public static function timestamp(Request $request): string
    {
        return self::value($request, self::TIMESTAMP);
    }

    public static function nonce(Request $request): string
    {
        return self::value($request, self::NONCE);
    }
}
