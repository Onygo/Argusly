<?php

namespace App\Services\Faq;

use App\Models\FaqQuestion;
use Illuminate\Support\Collection;

class FaqSchemaService
{
    public function forQuestions(Collection $faqs): ?array
    {
        $mainEntity = $faqs
            ->map(function (FaqQuestion $faq): ?array {
                $question = $this->cleanText($faq->question);
                $answer = $this->cleanText($faq->answer);

                if ($question === '' || $answer === '') {
                    return null;
                }

                return [
                    '@type' => 'Question',
                    'name' => $question,
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $answer,
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();

        if ($mainEntity === []) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $mainEntity,
        ];
    }

    /**
     * @return array<int,string>
     */
    public function validate(?array $schema): array
    {
        if ($schema === null) {
            return ['FAQPage schema is empty.'];
        }

        $errors = [];

        if (($schema['@type'] ?? null) !== 'FAQPage') {
            $errors[] = 'Schema @type must be FAQPage.';
        }

        $entities = $schema['mainEntity'] ?? [];
        if (! is_array($entities) || $entities === []) {
            $errors[] = 'FAQPage schema must contain mainEntity questions.';
        }

        foreach ((array) $entities as $index => $entity) {
            if (($entity['@type'] ?? null) !== 'Question') {
                $errors[] = "mainEntity {$index} must be a Question.";
            }

            if (trim((string) ($entity['name'] ?? '')) === '') {
                $errors[] = "mainEntity {$index} is missing a question name.";
            }

            if (trim((string) data_get($entity, 'acceptedAnswer.text', '')) === '') {
                $errors[] = "mainEntity {$index} is missing acceptedAnswer text.";
            }
        }

        return $errors;
    }

    private function cleanText(?string $value): string
    {
        $plain = strip_tags((string) $value);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? '';

        return trim($plain);
    }
}
