<?php

it('boots tests with an isolated in-memory database and sync queue', function () {
    expect(app()->environment())->toBe('testing')
        ->and(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:')
        ->and(config('queue.default'))->toBe('sync')
        ->and(config('session.driver'))->toBe('array');
});
