<?php

use App\Support\OrganizationSafetyGuard;

it('detects the configured junk organization patterns', function () {
    expect(OrganizationSafetyGuard::looksLikeTestArtifact('Org 03Py', 'acme'))->toBeTrue()
        ->and(OrganizationSafetyGuard::looksLikeTestArtifact('Customer', 'tmp-org-1234'))->toBeTrue()
        ->and(OrganizationSafetyGuard::looksLikeTestArtifact('Customer', 'dbg-org-1234'))->toBeTrue()
        ->and(OrganizationSafetyGuard::looksLikeTestArtifact('Acme BV', 'acme-bv'))->toBeFalse();
});

it('blocks junk organization patterns outside the testing environment', function () {
    expect(fn () => OrganizationSafetyGuard::assertAllowed('Org 03Py', 'tmp-org-1234', 'local'))
        ->toThrow(RuntimeException::class);

    expect(fn () => OrganizationSafetyGuard::assertAllowed('Acme BV', 'acme-bv', 'local'))
        ->not->toThrow(RuntimeException::class);

    expect(fn () => OrganizationSafetyGuard::assertAllowed('Org 03Py', 'tmp-org-1234', 'testing'))
        ->not->toThrow(RuntimeException::class);
});
