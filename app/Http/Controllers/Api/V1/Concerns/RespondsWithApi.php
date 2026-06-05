<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use Illuminate\Http\JsonResponse;

trait RespondsWithApi
{
    protected function success(mixed $data, array $meta = [], array $links = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => (object) $meta,
            'links' => (object) $links,
        ], $status);
    }

    protected function error(string $message, array $errors = [], string $code = 'VALIDATION_ERROR', int $status = 422): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => (object) $errors,
            'code' => $code,
        ], $status);
    }
}
