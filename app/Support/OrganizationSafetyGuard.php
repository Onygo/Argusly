<?php

namespace App\Support;

use RuntimeException;

class OrganizationSafetyGuard
{
    public static function assertAllowed(?string $name, ?string $slug, ?string $environment = null): void
    {
        if (($environment ?? app()->environment()) === 'testing') {
            return;
        }

        if (! self::looksLikeTestArtifact($name, $slug)) {
            return;
        }

        throw new RuntimeException('Refusing to create an obvious test/debug organization outside the testing environment.');
    }

    public static function looksLikeTestArtifact(?string $name, ?string $slug): bool
    {
        $normalizedName = trim((string) $name);
        $normalizedSlug = strtolower(trim((string) $slug));

        if ($normalizedSlug !== '' && (str_starts_with($normalizedSlug, 'tmp-') || str_starts_with($normalizedSlug, 'dbg-'))) {
            return true;
        }

        return $normalizedName !== ''
            && preg_match('/^Org [A-Za-z0-9]{3,8}$/', $normalizedName) === 1;
    }
}
