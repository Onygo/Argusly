<?php

namespace App\Services\Faq;

use App\Models\FaqQuestion;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FaqDuplicateDetectionService
{
    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function risks(?string $locale = null): Collection
    {
        $query = FaqQuestion::query()->with('assignments');

        if ($locale !== null && $locale !== '') {
            $query->where('language', strtolower($locale));
        }

        $faqs = $query->get();

        return collect()
            ->merge($this->exactDuplicateRisks($faqs))
            ->merge($this->semanticDuplicateRisks($faqs))
            ->merge($this->intentOverlapRisks($faqs))
            ->merge($this->typeAssignmentRisks($faqs))
            ->unique(fn (array $risk): string => $risk['risk_type'].'|'.$risk['question'].'|'.implode(',', $risk['faq_ids']))
            ->values();
    }

    /**
     * @param  Collection<int,FaqQuestion>  $faqs
     * @return Collection<int,array<string,mixed>>
     */
    private function exactDuplicateRisks(Collection $faqs): Collection
    {
        return $faqs
            ->groupBy(fn (FaqQuestion $faq): string => $this->normalize($faq->question))
            ->filter(fn (Collection $group, string $key): bool => $key !== '' && $group->count() > 1)
            ->map(fn (Collection $group): array => $this->risk('exact_duplicate_question', $group, 'reuse'))
            ->values();
    }

    /**
     * @param  Collection<int,FaqQuestion>  $faqs
     * @return Collection<int,array<string,mixed>>
     */
    private function semanticDuplicateRisks(Collection $faqs): Collection
    {
        return $faqs
            ->groupBy(fn (FaqQuestion $faq): string => $this->semanticKey($faq->question))
            ->filter(fn (Collection $group, string $key): bool => $key !== '' && $group->count() > 1)
            ->map(fn (Collection $group): array => $this->risk('semantically_similar_question', $group, 'rewrite'))
            ->values();
    }

    /**
     * @param  Collection<int,FaqQuestion>  $faqs
     * @return Collection<int,array<string,mixed>>
     */
    private function intentOverlapRisks(Collection $faqs): Collection
    {
        return $faqs
            ->groupBy(fn (FaqQuestion $faq): string => implode('|', [
                $faq->language,
                (string) $faq->search_intent?->value,
                (string) $faq->funnel_stage?->value,
                $this->semanticKey($faq->question),
            ]))
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->map(fn (Collection $group): array => $this->risk('overlapping_intent', $group, 'move'))
            ->values();
    }

    /**
     * @param  Collection<int,FaqQuestion>  $faqs
     * @return Collection<int,array<string,mixed>>
     */
    private function typeAssignmentRisks(Collection $faqs): Collection
    {
        return $faqs
            ->filter(function (FaqQuestion $faq): bool {
                return $faq->assignments->contains(function ($assignment) use ($faq): bool {
                    return ! in_array((string) $faq->faq_type?->value, ['resource', (string) $assignment->page_type], true)
                        && $assignment->page_type !== 'homepage';
                });
            })
            ->map(fn (FaqQuestion $faq): array => [
                'risk_type' => 'wrong_faq_type_assignment',
                'question' => $faq->question,
                'faq_ids' => [$faq->id],
                'count' => 1,
                'page_assignments' => $faq->assignments->map(fn ($assignment): string => $assignment->page_type.'/'.$assignment->page_slug)->values()->all(),
                'advice' => 'verplaatsen',
            ])
            ->values();
    }

    private function risk(string $type, Collection $group, string $advice): array
    {
        return [
            'risk_type' => $type,
            'question' => (string) $group->first()->question,
            'faq_ids' => $group->pluck('id')->values()->all(),
            'count' => $group->count(),
            'page_assignments' => $group
                ->flatMap(fn (FaqQuestion $faq): Collection => $faq->assignments->map(fn ($assignment): string => $assignment->page_type.'/'.$assignment->page_slug))
                ->unique()
                ->values()
                ->all(),
            'advice' => match ($advice) {
                'reuse' => 'hergebruiken',
                'rewrite' => 'herschrijven',
                'move' => 'verplaatsen',
                default => 'verwijderen',
            },
        ];
    }

    private function normalize(string $question): string
    {
        $question = Str::lower(strip_tags($question));
        $question = preg_replace('/[^\pL\pN\s]+/u', '', $question) ?? $question;
        $question = preg_replace('/\s+/u', ' ', $question) ?? $question;

        return trim($question);
    }

    private function semanticKey(string $question): string
    {
        $tokens = collect(explode(' ', $this->normalize($question)))
            ->reject(fn (string $token): bool => in_array($token, ['hoe', 'wat', 'waarom', 'welke', 'does', 'how', 'what', 'why', 'the', 'een', 'het', 'dit', 'bij', 'for'], true))
            ->map(fn (string $token): string => Str::singular($token))
            ->unique()
            ->sort()
            ->values();

        return $tokens->take(6)->implode(' ');
    }
}
