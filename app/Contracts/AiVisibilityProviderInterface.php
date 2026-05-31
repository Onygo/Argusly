<?php

namespace App\Contracts;

interface AiVisibilityProviderInterface
{
    public function key(): string;

    public function name(): string;

    public function model(): string;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function runPrompt(string $prompt, array $context = []): array;

    /**
     * @param  array<string, mixed>  $response
     */
    public function normalizeAnswer(array $response): string;

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array{url: string, domain?: string|null, title?: string|null, snippet?: string|null, rank?: int|null, trust_score?: int|null, metadata?: array<string, mixed>}>
     */
    public function extractCitations(array $response): array;

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array{entity_name: string, entity_type?: string|null, sentiment?: string|null, position?: int|null, metadata?: array<string, mixed>}>
     */
    public function extractEntities(array $response): array;

    /**
     * @param  array<int, array<string, mixed>>  $citations
     * @param  array<int, array<string, mixed>>  $entities
     * @param  array<string, mixed>  $context
     */
    public function calculateVisibilityScore(string $normalizedAnswer, array $citations = [], array $entities = [], array $context = []): int;
}
