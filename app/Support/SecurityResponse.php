<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityResponse
{
    public static function forbidden(Request $request, ?string $message = null): Response
    {
        return self::make(
            $request,
            403,
            $message ?? self::configuredMessage($request, 'forbidden_message_web', 'forbidden_message_api', 'Forbidden.')
        );
    }

    public static function tooManyRequests(
        Request $request,
        ?int $retryAfter = null,
        ?string $message = null
    ): Response {
        $response = self::make(
            $request,
            429,
            $message ?? self::configuredMessage(
                $request,
                'throttle_message_web',
                'throttle_message_api',
                'Too many requests. Please try again shortly.'
            )
        );

        if ($retryAfter !== null && $retryAfter > 0) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    public static function invalid(Request $request, string $message, int $status = 422): Response
    {
        return self::make($request, $status, $message);
    }

    private static function make(Request $request, int $status, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
            ], $status);
        }

        return response($message, $status)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    private static function configuredMessage(
        Request $request,
        string $webKey,
        string $apiKey,
        string $fallback
    ): string {
        $key = $request->expectsJson() ? $apiKey : $webKey;

        return (string) config("security.responses.{$key}", $fallback);
    }
}
