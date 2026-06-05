<?php

use App\Support\QueueWorkerHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

it('marks worker alive only when heartbeat is recent', function () {
    config()->set('cache.default', 'array');

    $now = Carbon::create(2026, 3, 3, 12, 0, 0, 'UTC');
    Carbon::setTestNow($now);

    try {
        QueueWorkerHeartbeat::touch($now->copy()->subSeconds(90)->timestamp);
        expect(QueueWorkerHeartbeat::isAlive())->toBeTrue();

        QueueWorkerHeartbeat::touch($now->copy()->subSeconds(121)->timestamp);
        expect(QueueWorkerHeartbeat::isAlive())->toBeFalse();

        $lastHeartbeat = QueueWorkerHeartbeat::lastHeartbeatAt();
        expect($lastHeartbeat?->timestamp)->toBe($now->copy()->subSeconds(121)->timestamp);
    } finally {
        Cache::store(QueueWorkerHeartbeat::storeName())->forget(QueueWorkerHeartbeat::key());
        Carbon::setTestNow();
    }
});
