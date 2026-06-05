<?php

namespace App\Services\BrandContext;

use App\Services\WorkspaceIntelligence\AIAnalysisService;

class FieldTransformationService
{
    public const ACTIONS = [
        'improve' => 'Make this text clearer and more impactful',
        'shorten' => 'Make this text more concise while preserving meaning',
        'make_technical' => 'Add more technical depth and specificity',
        'make_commercial' => 'Make this more persuasive and benefit-focused',
    ];

    public function __construct(
        private readonly AIAnalysisService $analysisService,
    ) {
    }

    /**
     * Transform a field value using AI.
     *
     * @param  array<string, mixed>  $brandContext
     */
    public function transform(
        string $value,
        string $action,
        ?string $fieldContext = null,
        ?array $brandContext = null
    ): string {
        if (! $this->isValidAction($action)) {
            return $value;
        }

        if (trim($value) === '') {
            return $value;
        }

        return $this->analysisService->transformField(
            $value,
            $action,
            $fieldContext,
            $brandContext ?? []
        );
    }

    /**
     * Check if an action is valid.
     */
    public function isValidAction(string $action): bool
    {
        return array_key_exists($action, self::ACTIONS);
    }

    /**
     * Get all available actions.
     *
     * @return array<string, string>
     */
    public function getActions(): array
    {
        return self::ACTIONS;
    }
}
