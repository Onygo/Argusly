<?php

use App\Contracts\PdfRenderer;
use App\Services\FakeInvoicePdfRenderer;

it('runs tests with elevated php memory limit', function () {
    $limit = ini_get('memory_limit');

    expect(memoryLimitToBytes($limit))->toBeGreaterThanOrEqual(1024 * 1024 * 1024);
});

it('binds fake invoice pdf renderer in testing environment', function () {
    expect(app(PdfRenderer::class))->toBeInstanceOf(FakeInvoicePdfRenderer::class);
});

function memoryLimitToBytes(string|false $limit): int
{
    if ($limit === false || $limit === '') {
        return 0;
    }

    if ($limit === '-1') {
        return PHP_INT_MAX;
    }

    $unit = strtolower(substr($limit, -1));
    $value = (int) $limit;

    return match ($unit) {
        'g' => $value * 1024 * 1024 * 1024,
        'm' => $value * 1024 * 1024,
        'k' => $value * 1024,
        default => $value,
    };
}
