<?php

namespace App\Exceptions;

use RuntimeException;

class LlmBudgetExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $scope,
        public readonly int $budget,
        public readonly int $used,
        public readonly int $requested,
    ) {
        parent::__construct("LLM {$scope} budget exceeded. {$used} used, {$requested} requested, {$budget} available.");
    }
}
