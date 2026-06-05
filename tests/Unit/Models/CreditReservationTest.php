<?php

use App\Models\CreditReservation;
use App\Models\CreditWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('has correct status constants', function () {
    expect(CreditReservation::STATUS_RESERVED)->toBe('reserved');
    expect(CreditReservation::STATUS_CAPTURED)->toBe('captured');
    expect(CreditReservation::STATUS_RELEASED)->toBe('released');
    expect(CreditReservation::STATUS_EXPIRED)->toBe('expired');
});

it('returns default ttl minutes from config', function () {
    config(['credits.reservation_ttl_minutes' => 45]);
    expect(CreditReservation::defaultTtlMinutes())->toBe(45);
});

it('correctly identifies reserved status', function () {
    $reservation = new CreditReservation(['status' => 'reserved']);
    expect($reservation->isReserved())->toBeTrue();
    expect($reservation->isCaptured())->toBeFalse();
    expect($reservation->isReleased())->toBeFalse();
    expect($reservation->isExpired())->toBeFalse();
    expect($reservation->isFinalized())->toBeFalse();
});

it('correctly identifies captured status', function () {
    $reservation = new CreditReservation(['status' => 'captured']);
    expect($reservation->isReserved())->toBeFalse();
    expect($reservation->isCaptured())->toBeTrue();
    expect($reservation->isReleased())->toBeFalse();
    expect($reservation->isExpired())->toBeFalse();
    expect($reservation->isFinalized())->toBeTrue();
});

it('correctly identifies released status', function () {
    $reservation = new CreditReservation(['status' => 'released']);
    expect($reservation->isReserved())->toBeFalse();
    expect($reservation->isCaptured())->toBeFalse();
    expect($reservation->isReleased())->toBeTrue();
    expect($reservation->isExpired())->toBeFalse();
    expect($reservation->isFinalized())->toBeTrue();
});

it('correctly identifies expired status', function () {
    $reservation = new CreditReservation(['status' => 'expired']);
    expect($reservation->isReserved())->toBeFalse();
    expect($reservation->isCaptured())->toBeFalse();
    expect($reservation->isReleased())->toBeFalse();
    expect($reservation->isExpired())->toBeTrue();
    expect($reservation->isFinalized())->toBeTrue();
});

it('correctly identifies past expiry', function () {
    $pastExpiry = new CreditReservation(['expires_at' => now()->subMinute()]);
    expect($pastExpiry->isPastExpiry())->toBeTrue();

    $futureExpiry = new CreditReservation(['expires_at' => now()->addMinute()]);
    expect($futureExpiry->isPastExpiry())->toBeFalse();

    $noExpiry = new CreditReservation(['expires_at' => null]);
    expect($noExpiry->isPastExpiry())->toBeFalse();
});

it('casts metadata to array', function () {
    $reservation = new CreditReservation(['metadata' => ['key' => 'value']]);
    expect($reservation->metadata)->toBeArray();
    expect($reservation->metadata['key'])->toBe('value');
});

it('casts timestamps correctly', function () {
    $reservation = new CreditReservation([
        'reserved_at' => '2026-01-01 12:00:00',
        'captured_at' => '2026-01-01 12:01:00',
        'released_at' => '2026-01-01 12:02:00',
        'expires_at' => '2026-01-01 12:30:00',
    ]);

    expect($reservation->reserved_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    expect($reservation->captured_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    expect($reservation->released_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    expect($reservation->expires_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

it('has uuid primary key', function () {
    $reservation = new CreditReservation();
    expect($reservation->getKeyType())->toBe('string');
    expect($reservation->getIncrementing())->toBeFalse();
});
