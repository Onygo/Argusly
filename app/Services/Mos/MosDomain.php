<?php

namespace App\Services\Mos;

final class MosDomain
{
    public const IDENTITY = 'identity';

    public const KNOWLEDGE = 'knowledge';

    public const SIGNAL = 'signal';

    public const OPPORTUNITY = 'opportunity';

    public const DECISION = 'decision';

    public const RECOMMENDATION = 'recommendation';

    public const ACTION = 'action';

    public const WORKFLOW = 'workflow';

    public const APPROVAL = 'approval';

    public const ASSET = 'asset';

    public const EXECUTION = 'execution';

    public const MEASUREMENT = 'measurement';

    public const LEARNING = 'learning';

    public const RELATIONSHIP = 'relationship';

    public const NOTIFICATION = 'notification';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::IDENTITY,
            self::KNOWLEDGE,
            self::SIGNAL,
            self::OPPORTUNITY,
            self::DECISION,
            self::RECOMMENDATION,
            self::ACTION,
            self::WORKFLOW,
            self::APPROVAL,
            self::ASSET,
            self::EXECUTION,
            self::MEASUREMENT,
            self::LEARNING,
            self::RELATIONSHIP,
            self::NOTIFICATION,
        ];
    }
}
