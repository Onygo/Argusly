<?php

declare(strict_types=1);

namespace Onygo\ArguslyConnector;

final class InstalledVersions
{
    public const VERSION = '0.1.6';

    public static function version(): string
    {
        return self::VERSION;
    }
}
