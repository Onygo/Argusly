<?php

namespace App\Actions\Social;

use App\Enums\SocialPlatform;
use App\Enums\SupportedLanguage;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostVariant;
use App\Services\SocialDistribution\SocialCopyLanguageAgent;
use Illuminate\Support\Str;

class GenerateLinkedInPostFromContent
{
    public function __construct(
        private readonly SocialCopyLanguageAgent $languageAgent,
    ) {}

    /**
     * @param array{campaign?:Campaign|null,social_account?:SocialAccount|null,target_audience?:string|null,tone_of_voice?:string|null,language?:string|null,source_url?:string|null,hashtags?:array<int,string>|null,tracking_parameters?:array<string,string>|null} $options
     */
    public function handle(Content $content, array $options = []): SocialPost
    {
        $campaign = $options['campaign'] ?? null;
        $socialAccount = $options['social_account'] ?? null;
        $language = in_array((string) ($options['language'] ?? ''), ['nl', 'en'], true) ? (string) $options['language'] : 'en';
        $sourceContent = $this->contentForLanguage($content, $language);
        $sourceUrl = trim((string) ($options['source_url'] ?? ''));
        $sourceUrl = $sourceUrl !== '' ? $sourceUrl : $this->defaultSourceUrl($sourceContent);
        $trackingParameters = $this->cleanTrackingParameters((array) ($options['tracking_parameters'] ?? []));
        $trackedSourceUrl = $campaign?->trackedUrl($sourceUrl) ?? $this->trackedUrl($sourceUrl, $trackingParameters) ?? $sourceUrl;
        $hashtags = $this->cleanHashtags((array) ($options['hashtags'] ?? []));
        $audience = trim((string) ($options['target_audience'] ?? $socialAccount?->actorLabel() ?? 'the target audience'));
        $tone = trim((string) ($options['tone_of_voice'] ?? $socialAccount?->toneProfile() ?? 'clear, useful, and practical'));
        $accountContext = $this->accountContext($socialAccount);
        $summary = Str::of((string) ($sourceContent->public_blog_excerpt ?: $sourceContent->seo_meta_description ?: $sourceContent->title))
            ->stripTags()
            ->squish()
            ->limit(240, '');

        $post = SocialPost::query()->create([
            'organization_id' => $content->organization_id ?? $content->workspace?->organization_id,
            'workspace_id' => $content->workspace_id,
            'campaign_id' => $campaign?->id,
            'content_id' => $sourceContent->id,
            'social_account_id' => $socialAccount?->id,
            'provider' => SocialPlatform::LINKEDIN->value,
            'type' => filled($sourceUrl) ? 'article' : 'text',
            'body' => $this->withAdditions($this->variantBody('short_hook', $sourceContent->title, (string) $summary, $audience, $tone, $language), $trackedSourceUrl, $hashtags),
            'url' => $trackedSourceUrl,
            'title' => $sourceContent->seo_title ?: $sourceContent->title,
            'description' => $sourceContent->seo_meta_description ?: $sourceContent->public_blog_excerpt,
            'visibility' => 'public',
            'status' => 'draft',
            'metadata' => [
                'approval_required' => true,
                'language' => $language,
                'source_url' => $sourceUrl,
                'tracked_source_url' => $trackedSourceUrl,
                'tracking_parameters' => $trackingParameters,
                'hashtags' => $hashtags,
                'target_audience' => $audience,
                'tone_of_voice' => $tone,
                'target_social_account' => $accountContext,
                'source' => static::class,
            ],
        ]);

        foreach ($this->variantTypes($sourceContent) as $index => $type) {
            SocialPostVariant::query()->create([
                'organization_id' => $post->organization_id,
                'workspace_id' => $post->workspace_id,
                'social_post_id' => $post->id,
                'campaign_id' => $post->campaign_id,
                'content_id' => $sourceContent->id,
                'social_account_id' => $socialAccount?->id,
                'platform' => SocialPlatform::LINKEDIN->value,
                'post_type' => $type === 'insight' ? 'insight_post' : $type,
                'variant_type' => $type,
                'status' => 'draft',
                'variant_number' => $index + 1,
                'body' => $this->variantBody($type, $sourceContent->title, (string) $summary, $audience, $tone, $language),
                'hashtags' => $hashtags,
                'score' => null,
                'selected' => $index === 0,
                'generated_at' => now(),
                'generation_prompt_context' => [
                    'language' => $language,
                    'source_url' => $sourceUrl,
                    'tracking_parameters' => $trackingParameters,
                    'source_content_id' => (string) $content->id,
                    'resolved_content_id' => (string) $sourceContent->id,
                    'hashtags' => $hashtags,
                    'target_audience' => $audience,
                    'tone_of_voice' => $tone,
                    'target_social_account' => $accountContext,
                    'approval_required' => true,
                ],
            ]);
        }

        return $post->fresh(['variants']);
    }

    private function contentForLanguage(Content $content, string $language): Content
    {
        $resolvedLanguage = SupportedLanguage::fromStringOrDefault($language)->value;

        if ($content->localeCode() === $resolvedLanguage) {
            return $content;
        }

        return $content->localizedVariantFor($resolvedLanguage) ?? $content;
    }

    private function defaultSourceUrl(Content $content): string
    {
        return trim((string) ($content->published_url ?: $content->seo_canonical));
    }

    /**
     * @param array<string,mixed> $parameters
     * @return array<string,string>
     */
    private function cleanTrackingParameters(array $parameters): array
    {
        return collect($parameters)
            ->only(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->all();
    }

    /**
     * @param array<string,string> $parameters
     */
    private function trackedUrl(?string $url, array $parameters): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || $parameters === []) {
            return $url !== '' ? $url : null;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        $query = array_replace($query, $parameters);

        $authority = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $userInfo = $parts['user'] ?? null;
        if ($userInfo !== null) {
            $authority = $userInfo.(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@'.$authority;
        }

        return $parts['scheme'].'://'.$authority
            .($parts['path'] ?? '')
            .($query !== [] ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function accountContext(?SocialAccount $account): ?array
    {
        if (! $account) {
            return null;
        }

        return [
            'id' => (string) $account->id,
            'display_name' => $account->display_name,
            'account_type' => $account->account_type,
            'labels' => $account->labels(),
            'tone_profile' => $account->toneProfile(),
            'engagement_role' => $account->engagementRole(),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function variantTypes(Content $content): array
    {
        $types = ['thought_leadership', 'short_hook', 'insight', 'building_in_public'];
        $text = Str::lower($content->title.' '.$content->type.' '.$content->primary_keyword);

        if (Str::contains($text, ['technical', 'api', 'architecture', 'developer', 'engineering'])) {
            $types[] = 'technical_deep_dive';
        }

        return $types;
    }

    private function variantBody(string $type, string $title, string $summary, string $audience, string $tone, string $language): string
    {
        $body = $language === 'nl'
            ? match ($type) {
                'thought_leadership' => "{$title}\n\nDe nuttige verschuiving voor {$audience}: {$summary}\n\nEen {$tone} inzicht: publiceer minder ruis, maar maak elk idee makkelijker om op te volgen.",
                'insight' => "Een inzicht uit {$title}:\n\n{$summary}\n\nDe kans zit in het vertalen naar een herhaalbare contentbeslissing, niet naar een losse post.",
                'building_in_public' => "We denken na over {$title}.\n\n{$summary}\n\nHet werk zit nu in distributie die nuttig, goedgekeurd en meetbaar blijft.",
                'technical_deep_dive' => "Technische noot: {$title}\n\n{$summary}\n\nDe architectuur moet approval, planning en providerlimieten gescheiden houden zodat publiceren controleerbaar blijft.",
                default => "{$title}\n\n{$summary}\n\nWat zou jij toevoegen?",
            }
            : match ($type) {
                'thought_leadership' => "{$title}\n\nThe useful shift for {$audience}: {$summary}\n\nA {$tone} takeaway: publish less noise, but make every idea easier to act on.",
                'insight' => "One insight from {$title}:\n\n{$summary}\n\nThe opportunity is to turn that into a repeatable content decision, not a one-off post.",
                'building_in_public' => "We are thinking through {$title}.\n\n{$summary}\n\nThe work now is keeping distribution useful, approved, and measurable.",
                'technical_deep_dive' => "Technical note: {$title}\n\n{$summary}\n\nThe architecture should keep approval, scheduling, and provider limits separate so publishing stays controlled.",
                default => "{$title}\n\n{$summary}\n\nWhat would you add?",
            };

        return $this->languageAgent->review('', $body, $language)['body'];
    }

    /**
     * @param array<int,string> $hashtags
     */
    private function withAdditions(string $body, string $sourceUrl, array $hashtags): string
    {
        return collect([
            trim($body),
            $sourceUrl,
            collect($hashtags)->implode(' '),
        ])->filter()->implode("\n\n");
    }

    /**
     * @param array<int,mixed> $hashtags
     * @return list<string>
     */
    private function cleanHashtags(array $hashtags): array
    {
        return collect($hashtags)
            ->map(fn (mixed $tag): string => trim((string) $tag))
            ->filter()
            ->map(fn (string $tag): string => Str::startsWith($tag, '#') ? $tag : '#'.$tag)
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }
}
