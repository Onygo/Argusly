<?php

namespace App\Exceptions;

use App\Enums\ContentLifecycleStatus;
use App\Models\Content;
use Exception;

class InvalidLifecycleTransitionException extends Exception
{
    public function __construct(
        public readonly Content $content,
        public readonly ContentLifecycleStatus $fromStage,
        public readonly ContentLifecycleStatus $targetStage,
        ?string $message = null
    ) {
        $message ??= sprintf(
            'Cannot transition content from "%s" to "%s". Allowed transitions: %s',
            $fromStage->label(),
            $targetStage->label(),
            implode(', ', array_map(fn ($s) => $s->label(), $fromStage->allowedTransitions()))
        );

        parent::__construct($message);
    }

    /**
     * Create exception for unauthorized transition.
     */
    public static function unauthorized(
        Content $content,
        ContentLifecycleStatus $fromStage,
        ContentLifecycleStatus $targetStage
    ): self {
        return new self(
            $content,
            $fromStage,
            $targetStage,
            sprintf(
                'You are not authorized to transition this content from "%s" to "%s".',
                $fromStage->label(),
                $targetStage->label()
            )
        );
    }

    /**
     * Create exception for invalid stage transition.
     */
    public static function invalidTransition(
        Content $content,
        ContentLifecycleStatus $fromStage,
        ContentLifecycleStatus $targetStage
    ): self {
        return new self($content, $fromStage, $targetStage);
    }
}
