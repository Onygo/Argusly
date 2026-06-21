<?php

namespace App\Services\Faq;

use App\Repositories\FaqQuestionRepository;

class FaqIntelligenceRenderer
{
    public function __construct(
        private readonly FaqQuestionRepository $repository,
        private readonly FaqSchemaService $schema,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function forPage(string $pageType, string $pageSlug, string $locale): array
    {
        $items = $this->repository->publishedForPage($pageType, $pageSlug, $locale);

        return [
            'items' => $items,
            'schema' => $this->schema->forQuestions($items),
        ];
    }
}
