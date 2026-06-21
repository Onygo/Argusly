<?php

namespace App\Repositories;

use App\Enums\FaqStatus;
use App\Models\FaqQuestion;
use Illuminate\Support\Collection;

class FaqQuestionRepository
{
    public function publishedForPage(string $pageType, string $pageSlug, string $locale): Collection
    {
        return FaqQuestion::query()
            ->with('assignments')
            ->published()
            ->forLocale($locale)
            ->where(function ($query) use ($pageType, $pageSlug, $locale): void {
                $query->where('is_global', true)
                    ->orWhereHas('assignments', function ($assignmentQuery) use ($pageType, $pageSlug, $locale): void {
                        $assignmentQuery
                            ->where('page_type', $pageType)
                            ->where('page_slug', $pageSlug)
                            ->where('locale', strtolower($locale));
                    });
            })
            ->get()
            ->sortByDesc(function (FaqQuestion $faq) use ($pageType, $pageSlug, $locale): int {
                $assignment = $faq->assignments
                    ->first(fn ($item): bool => $item->page_type === $pageType && $item->page_slug === $pageSlug && $item->locale === strtolower($locale));

                return ((int) ($assignment->weight ?? $faq->priority) * 1000) + (int) $faq->priority;
            })
            ->values();
    }

    public function questionExists(string $question, string $locale): bool
    {
        $normalized = $this->normalizeQuestion($question);

        return FaqQuestion::query()
            ->where('language', strtolower($locale))
            ->whereIn('status', [FaqStatus::DRAFT->value, FaqStatus::REVIEW->value, FaqStatus::PUBLISHED->value])
            ->get(['question'])
            ->contains(fn (FaqQuestion $faq): bool => $this->normalizeQuestion($faq->question) === $normalized);
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function create(array $attributes): FaqQuestion
    {
        return FaqQuestion::query()->create($attributes);
    }

    private function normalizeQuestion(string $question): string
    {
        $question = mb_strtolower(strip_tags($question));
        $question = preg_replace('/[^\pL\pN\s]+/u', '', $question) ?? $question;
        $question = preg_replace('/\s+/u', ' ', $question) ?? $question;

        return trim($question);
    }
}
