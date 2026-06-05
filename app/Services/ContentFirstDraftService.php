<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Brand;
use App\Models\BrandNarrative;
use App\Models\BrandProduct;
use App\Models\BrandProfile;
use App\Models\BrandService;
use App\Models\ContentAsset;
use App\Models\User;
use App\Services\Llm\LlmPromptRuntime;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class ContentFirstDraftService
{
    public function __construct(
        private readonly BrandKnowledgeCenterService $knowledge,
        private readonly ContentAssetService $contentAssets,
        private readonly LlmPromptRuntime $llm,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, ContentAsset>
     */
    public function create(Account $account, Brand $brand, User $user, array $attributes): Collection
    {
        $profile = $this->knowledge->profileForBrand($account, $brand);
        $context = $this->contextFor($account, $brand, $profile);
        $mode = $attributes['draft_mode'] ?? 'single';
        $count = $mode === 'chain' ? (int) ($attributes['chain_count'] ?? 3) : 1;
        $chainId = $mode === 'chain' ? (string) Str::uuid() : null;
        $titles = $this->titlesFor($attributes, $count);

        return collect($titles)
            ->map(function (string $title, int $index) use ($account, $brand, $user, $attributes, $profile, $context, $chainId, $count): ContentAsset {
                $keyword = trim((string) ($attributes['primary_keyword'] ?? ''));
                $angle = trim((string) ($attributes['angle'] ?? ''));
                $audience = trim((string) ($attributes['audience'] ?? ''));
                $language = (string) ($attributes['language'] ?? app(ContentLanguageService::class)->defaultFor($brand, $account));

                $fallbackBody = $this->body($title, $keyword, $angle, $audience, $profile, $context, $index + 1, $count);
                $llmResponse = null;

                try {
                    $llmResponse = $this->llm->generate(
                        account: $account,
                        brand: $brand,
                        user: $user,
                        purpose: 'guided_first_draft',
                        messages: [
                            [
                                'role' => 'user',
                                'content' => $this->prompt($title, $keyword, (string) ($attributes['secondary_keywords'] ?? ''), $angle, $audience, $language, $profile, $context, $index + 1, $count),
                            ],
                        ],
                        systemPrompt: 'You are Argusly content engine. Write useful, specific, publishable first drafts from brand context. Avoid meta instructions, placeholders and generic editor notes.',
                        fakeContent: $fallbackBody,
                        temperature: 0.7,
                        maxTokens: 1800,
                        metadata: [
                            'content_asset_workflow' => 'guided_first_draft',
                            'chain_id' => $chainId,
                            'chain_position' => $chainId ? $index + 1 : null,
                            'chain_count' => $chainId ? $count : null,
                        ],
                    );
                } catch (Throwable) {
                    $llmResponse = null;
                }

                $body = trim((string) ($llmResponse?->content ?: $fallbackBody));
                $isFallback = $llmResponse === null || (bool) ($llmResponse->rawResponse['fake'] ?? false);

                return $this->contentAssets->create($account, $brand, [
                    'type' => $attributes['type'] ?? 'article',
                    'status' => 'draft',
                    'title' => $title,
                    'language' => $language,
                    'locale' => $attributes['locale'] ?? 'en_US',
                    'source' => 'guided_first_draft',
                    'excerpt' => $this->excerpt($title, $keyword, $profile),
                    'body' => $body !== '' ? $body : $fallbackBody,
                    'metadata' => [
                        'workflow' => 'guided_first_draft',
                        'draft_mode' => $chainId ? 'chain' : 'single',
                        'generation_mode' => $isFallback ? 'fallback_draft' : 'llm_draft',
                        'primary_keyword' => $keyword,
                        'secondary_keywords' => $this->keywords((string) ($attributes['secondary_keywords'] ?? '')),
                        'angle' => $angle,
                        'audience' => $audience,
                        'context_sources' => $context['sources'],
                        'chain_id' => $chainId,
                        'chain_position' => $chainId ? $index + 1 : null,
                        'chain_count' => $chainId ? $count : null,
                        'llm_response' => $llmResponse?->toArray(),
                        'llm_fake' => $isFallback,
                    ],
                    'seo_metadata' => [
                        'primary_keyword' => $keyword,
                        'secondary_keywords' => $this->keywords((string) ($attributes['secondary_keywords'] ?? '')),
                        'recommended_title' => $title,
                    ],
                ], $user);
            });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, string>
     */
    private function titlesFor(array $attributes, int $count): array
    {
        $title = trim((string) ($attributes['title'] ?? ''));
        $keyword = trim((string) ($attributes['primary_keyword'] ?? ''));
        $base = $title !== '' ? $title : Str::headline($keyword);

        if ($count === 1) {
            return [$base];
        }

        return collect(range(1, $count))
            ->map(fn (int $position): string => match ($position) {
                1 => $base,
                2 => 'How '.$keyword.' works in practice',
                3 => 'Common mistakes around '.$keyword,
                4 => 'Choosing the right '.$keyword.' approach',
                5 => 'Measuring '.$keyword.' results',
                default => Str::headline($keyword).' playbook part '.$position,
            })
            ->map(fn (string $generated): string => Str::limit($generated, 250, ''))
            ->all();
    }

    /**
     * @return array{sources: array<int, string>, products: Collection<int, BrandProduct>, services: Collection<int, BrandService>, narratives: Collection<int, BrandNarrative>}
     */
    private function contextFor(Account $account, Brand $brand, BrandProfile $profile): array
    {
        $products = BrandProduct::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->whereIn('status', ['active', 'draft'])
            ->latest()
            ->limit(3)
            ->get();
        $services = BrandService::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->whereIn('status', ['active', 'draft'])
            ->latest()
            ->limit(3)
            ->get();
        $narratives = BrandNarrative::query()
            ->where('account_id', $account->id)
            ->where('brand_id', $brand->id)
            ->whereIn('status', ['active', 'draft'])
            ->latest()
            ->limit(3)
            ->get();

        return [
            'sources' => collect([
                filled($profile->short_description) || filled($profile->long_description) ? 'company_profile' : null,
                filled($profile->tone_of_voice) ? 'tone_of_voice' : null,
                $products->isNotEmpty() ? 'products' : null,
                $services->isNotEmpty() ? 'services' : null,
                $narratives->isNotEmpty() ? 'narratives' : null,
            ])->filter()->values()->all(),
            'products' => $products,
            'services' => $services,
            'narratives' => $narratives,
        ];
    }

    /**
     * @param  array{sources: array<int, string>, products: Collection<int, BrandProduct>, services: Collection<int, BrandService>, narratives: Collection<int, BrandNarrative>}  $context
     */
    private function body(string $title, string $keyword, string $angle, string $audience, BrandProfile $profile, array $context, int $position, int $count): string
    {
        $company = $profile->official_name ?: $profile->brand?->name ?: 'the brand';
        $target = $audience !== '' ? $audience : ($profile->primary_audience ?: 'the target audience');
        $focus = $angle !== '' ? $angle : 'turning the topic into a practical plan';
        $keywordLabel = $keyword !== '' ? $keyword : $title;

        $sections = [
            "# {$title}",
            "{$keywordLabel} is no longer just a planning topic for {$target}. It is becoming a practical operating question: what should we publish, what should we prove, and how do we make sure the right answers are visible when buyers ask for help? {$company} can use this moment to move beyond generic advice and give readers a clear way to act.",
            "This draft focuses on {$focus}. It is written to be edited into a publish-ready article with stronger examples, proof points and source links where needed.",
            "## Why {$keywordLabel} matters now\nReaders are surrounded by advice that sounds polished but does not help them decide what to do next. The stronger opportunity is to explain the problem in plain language, show the trade-offs, and connect the recommendation to a workflow the reader can actually use.",
            $this->coreMessage($keywordLabel, $profile) !== ''
                ? "For {$company}, the strongest angle is this: ".$this->coreMessage($keywordLabel, $profile)
                : "For {$company}, the strongest angle is to help readers separate useful strategy from generic noise.",
            "## A practical way to approach it\n".$this->practicalFramework($keywordLabel, $profile, $context),
            "## How {$company} can support the work\n".$this->brandContext($profile, $context),
            "## What to do next\nStart with one specific reader question, then build the article around the answer that would help that reader make progress. Keep the primary keyword visible in the title, introduction and at least one H2, but make the value come from specificity: examples, evidence, constraints and a clear next step.",
        ];

        if ($count > 1) {
            $sections[] = "## Chain position\nThis is article {$position} of {$count}. It should stand alone, but it should also link naturally to the other articles in the chain once their final titles are approved.";
        }

        return trim(implode("\n\n", array_filter($sections)));
    }

    private function intro(string $keyword, string $angle, string $audience, BrandProfile $profile): string
    {
        $company = $profile->official_name ?: $profile->brand?->name ?: 'the brand';
        $target = $audience !== '' ? $audience : ($profile->primary_audience ?: 'the target audience');
        $focus = $angle !== '' ? $angle : 'a practical, answer-ready explanation';

        return "This first draft frames {$keyword} for {$target}. It should use {$company}'s context to create {$focus}, with enough specificity for editors to refine into publish-ready content.";
    }

    private function coreMessage(string $keyword, BrandProfile $profile): string
    {
        $value = $profile->value_proposition ?: $profile->positioning ?: $profile->short_description;

        return trim(implode(' ', array_filter([
            "Readers searching for {$keyword} need a clear point of view, not generic advice.",
            $value ? "Connect the answer to this positioning: {$value}" : null,
        ])));
    }

    private function outline(string $keyword, int $position, int $count): string
    {
        $items = [
            "- Define the reader problem behind {$keyword}.",
            "- Explain the strategic context and why it matters now.",
            "- Give a practical framework or checklist.",
            "- Show how the brand helps solve the problem.",
            "- Close with a clear next step.",
        ];

        if ($count > 1) {
            array_splice($items, 1, 0, "- Make this part {$position} distinct inside the broader content chain.");
        }

        return implode("\n", $items);
    }

    /**
     * @param  array{products: Collection<int, BrandProduct>, services: Collection<int, BrandService>, narratives: Collection<int, BrandNarrative>}  $context
     */
    private function practicalFramework(string $keyword, BrandProfile $profile, array $context): string
    {
        $lines = [
            "1. Define the reader's immediate problem around {$keyword}.",
            '2. Explain why the old approach is no longer enough.',
            '3. Give the reader a small decision framework they can use internally.',
            '4. Add one concrete example from the brand context.',
            '5. Close with a next step that fits the reader\'s level of readiness.',
        ];

        $service = $context['services']->first();
        if ($service instanceof BrandService) {
            $lines[] = "A useful proof point to expand: {$service->name}".($service->description ? " - {$service->description}" : '').'.';
        }

        $value = $profile->value_proposition ?: $profile->positioning;
        if ($value) {
            $lines[] = "Keep the draft anchored in this value proposition: {$value}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{products: Collection<int, BrandProduct>, services: Collection<int, BrandService>, narratives: Collection<int, BrandNarrative>}  $context
     */
    private function brandContext(BrandProfile $profile, array $context): string
    {
        $lines = collect([
            $profile->short_description ? '- Company: '.$profile->short_description : null,
            $profile->tone_of_voice ? '- Tone of voice: '.$profile->tone_of_voice : null,
            $profile->primary_audience ? '- Primary audience: '.$profile->primary_audience : null,
        ]);

        $context['products']->each(fn (BrandProduct $product) => $lines->push('- Product: '.$product->name.($product->description ? ' - '.$product->description : '')));
        $context['services']->each(fn (BrandService $service) => $lines->push('- Service: '.$service->name.($service->description ? ' - '.$service->description : '')));
        $context['narratives']->each(fn (BrandNarrative $narrative) => $lines->push('- Narrative: '.$narrative->title.($narrative->description ? ' - '.$narrative->description : '')));

        return $lines->filter()->implode("\n") ?: '- No brand knowledge center context is complete yet. Fill the brand profile before final publication.';
    }

    /**
     * @param  array{sources: array<int, string>, products: Collection<int, BrandProduct>, services: Collection<int, BrandService>, narratives: Collection<int, BrandNarrative>}  $context
     */
    private function prompt(string $title, string $keyword, string $secondaryKeywords, string $angle, string $audience, string $language, BrandProfile $profile, array $context, int $position, int $count): string
    {
        return trim(implode("\n\n", array_filter([
            "Write a first draft for: {$title}",
            "Language: {$language}",
            $keyword !== '' ? "Primary keyword: {$keyword}" : null,
            trim($secondaryKeywords) !== '' ? "Secondary keywords: {$secondaryKeywords}" : null,
            $audience !== '' ? "Audience: {$audience}" : ($profile->primary_audience ? "Audience: {$profile->primary_audience}" : null),
            $angle !== '' ? "Angle: {$angle}" : null,
            "Brand context:\n".$this->brandContext($profile, $context),
            $count > 1 ? "This is draft {$position} of {$count} in a content chain. Make it distinct and self-contained." : null,
            'Requirements: produce the draft itself, not an outline. Use Markdown headings. Include a clear introduction, 3-5 useful sections, concrete recommendations and a practical closing. Do not mention that this is a generated draft.',
        ])));
    }

    private function excerpt(string $title, string $keyword, BrandProfile $profile): string
    {
        $audience = $profile->primary_audience ?: 'the target audience';

        return Str::limit("A first draft for {$title}, focused on {$keyword} and written for {$audience}.", 240);
    }

    /**
     * @return array<int, string>
     */
    private function keywords(string $keywords): array
    {
        return collect(preg_split('/[,\\n]/', $keywords) ?: [])
            ->map(fn (string $keyword): string => trim($keyword))
            ->filter()
            ->values()
            ->all();
    }
}
