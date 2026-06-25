<?php

namespace App\Actions\Social;

use App\Enums\SocialPlatform;
use App\Enums\SupportedLanguage;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostVariant;
use App\Services\SocialDistribution\SocialArticleUrlResolver;
use App\Services\SocialDistribution\SocialCopyLanguageAgent;
use Illuminate\Support\Str;

class GenerateLinkedInPostFromContent
{
    public function __construct(
        private readonly SocialCopyLanguageAgent $languageAgent,
        private readonly SocialArticleUrlResolver $articleUrls,
    ) {}

    /**
     * @param array{campaign?:Campaign|null,social_account?:SocialAccount|null,target_audience?:string|null,tone_of_voice?:string|null,language?:string|null,source_url?:string|null,hashtags?:array<int,string>|null,tracking_parameters?:array<string,string>|null,variant_count?:int|null,desired_post_length?:string|null,desired_publication_date?:string|null,distribution_context?:array<string,mixed>|null} $options
     */
    public function handle(Content $content, array $options = []): SocialPost
    {
        $campaign = $options['campaign'] ?? null;
        $socialAccount = $options['social_account'] ?? null;
        $language = SupportedLanguage::tryFromString((string) ($options['language'] ?? ''))?->value
            ?? SupportedLanguage::EN->value;
        $sourceContent = $this->contentForLanguage($content, $language);
        $sourceUrl = trim((string) ($options['source_url'] ?? ''));
        $sourceUrl = $sourceUrl !== '' ? $sourceUrl : $this->defaultSourceUrl($sourceContent);
        $trackingParameters = $this->cleanTrackingParameters((array) ($options['tracking_parameters'] ?? []));
        $trackedSourceUrl = $campaign?->trackedUrl($sourceUrl) ?? $this->trackedUrl($sourceUrl, $trackingParameters) ?? $sourceUrl;
        $hashtags = $this->cleanHashtags((array) ($options['hashtags'] ?? []));
        $audience = $this->audienceForCopy(
            trim((string) ($options['target_audience'] ?? $socialAccount?->actorLabel() ?? '')),
            $language,
        );
        $tone = trim((string) ($options['tone_of_voice'] ?? $socialAccount?->toneProfile() ?? 'clear, useful, and practical'));
        $accountContext = $this->accountContext($socialAccount);
        $distributionContext = (array) ($options['distribution_context'] ?? []);
        $variantCount = max(3, min(5, (int) ($options['variant_count'] ?? 5)));
        $desiredPostLength = trim((string) ($options['desired_post_length'] ?? 'standard')) ?: 'standard';
        $desiredPublicationDate = trim((string) ($options['desired_publication_date'] ?? ''));
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
            'body' => $this->withAdditions($this->variantBody('short_hook', $sourceContent->title, (string) $summary, $audience, $tone, $language, $distributionContext), $trackedSourceUrl, $hashtags),
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
                'desired_post_length' => $desiredPostLength,
                'desired_publication_date' => $desiredPublicationDate !== '' ? $desiredPublicationDate : null,
                'distribution_context' => $distributionContext,
                'target_social_account' => $accountContext,
                'source' => static::class,
            ],
        ]);

        foreach (array_slice($this->variantTypes($sourceContent), 0, $variantCount) as $index => $type) {
            SocialPostVariant::query()->create([
                'organization_id' => $post->organization_id,
                'workspace_id' => $post->workspace_id,
                'social_post_id' => $post->id,
                'campaign_id' => $post->campaign_id,
                'content_id' => $sourceContent->id,
                'social_account_id' => $socialAccount?->id,
                'platform' => SocialPlatform::LINKEDIN->value,
                'post_type' => $this->postTypeForVariantType($type),
                'variant_type' => $type,
                'status' => 'draft',
                'variant_number' => $index + 1,
                'body' => $this->variantBody($type, $sourceContent->title, (string) $summary, $audience, $tone, $language, $distributionContext),
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
                    'desired_post_length' => $desiredPostLength,
                    'desired_publication_date' => $desiredPublicationDate !== '' ? $desiredPublicationDate : null,
                    'distribution_context' => $distributionContext,
                    'variant_angle' => data_get($distributionContext, 'variant_angles.'.$index),
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
        return $this->articleUrls->forContent($content);
    }

    private function audienceForCopy(string $audience, string $language): string
    {
        $audience = Str::of($audience)->stripTags()->squish()->toString();
        $default = $language === 'nl' ? 'de doelgroep' : 'the target audience';

        if ($audience === '' || strtolower($audience) === 'the target audience') {
            return $default;
        }

        $audience = preg_replace('/\s*(?:,|;|\/|\||\+|&|\band\b|\ben\b)\s*/iu', ', ', $audience) ?: $audience;
        $audience = preg_replace('/\s+(?=(?:CMO\'?s|CFOs?|CTOs?|CEOs?|COOs?|CROs?|Growth Leaders|Marketing Managers|Marketing Directors|Revenue Leaders|RevOps Leaders|Founders|Content Teams)\b)/u', ', ', $audience) ?: $audience;

        $parts = collect(explode(',', $audience))
            ->map(fn (string $part): string => trim($part))
            ->filter()
            ->map(fn (string $part): string => $language === 'nl' ? Str::lower($part) : $part)
            ->unique(fn (string $part): string => strtolower($part))
            ->values();

        if ($parts->count() <= 1) {
            return $parts->first() ?: $default;
        }

        $last = $parts->pop();

        return $parts->implode(', ') . ($language === 'nl' ? ' en ' : ', and ') . $last;
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
        $types = ['thought_leadership', 'seo_shift', 'practical_tip', 'trend_discussion', 'data_driven'];
        $text = Str::lower($content->title.' '.$content->type.' '.$content->primary_keyword);

        if (Str::contains($text, ['technical', 'api', 'architecture', 'developer', 'engineering'])) {
            $types[] = 'technical_deep_dive';
        }

        return $types;
    }

    private function postTypeForVariantType(string $type): string
    {
        return match ($type) {
            'thought_leadership' => 'thought_leadership',
            'practical_tip' => 'article',
            'trend_discussion', 'data_driven', 'seo_shift' => 'insight_post',
            'technical_deep_dive' => 'technical_deep_dive',
            default => 'short_hook',
        };
    }

    /**
     * @param array<string,mixed> $distributionContext
     */
    private function variantBody(string $type, string $title, string $summary, string $audience, string $tone, string $language, array $distributionContext = []): string
    {
        $subject = trim((string) data_get($distributionContext, 'subject', $title)) ?: $title;
        $cta = trim((string) data_get($distributionContext, 'primary_cta', 'Lees het volledige artikel op Argusly.'));
        $messages = collect((array) data_get($distributionContext, 'key_messages', []))
            ->map(fn (mixed $message): string => Str::of((string) $message)->stripTags()->squish()->toString())
            ->filter()
            ->values();
        $firstMessage = $messages->get(0, $summary);
        $secondMessage = $messages->get(1, $summary);
        $thirdMessage = $messages->get(2, $summary);
        $fourthMessage = $messages->get(3, $summary);
        $fifthMessage = $messages->get(4, $summary);
        $sixthMessage = $messages->get(5, 'Argusly helpt bedrijven AI zichtbaarheid te analyseren, kansen te ontdekken en content autonoom te organiseren.');

        $body = $language === 'nl'
            ? match ($type) {
                'thought_leadership' => "{$subject}\n\n{$firstMessage}\n\nDe nuttige verschuiving voor {$audience}: gevonden worden draait steeds vaker om het beste antwoord zijn, niet alleen om de beste ranking.\n\n{$cta}",
                'seo_shift' => "SEO alleen is niet meer genoeg.\n\n{$secondMessage}\n\nAEO vraagt om content die helder, feitelijk en antwoordwaardig is voor mensen en AI-systemen.\n\n{$cta}",
                'practical_tip' => "3 manieren om vandaag sterker zichtbaar te worden in AI:\n\n1. Beantwoord concrete buyer questions direct.\n2. Maak definities, stappen en bewijs makkelijk te citeren.\n3. Verbind elk artikel met duidelijke context, bronnen en vervolgacties.\n\n{$sixthMessage}\n\n{$cta}",
                'trend_discussion' => "Wordt jouw bedrijf al genoemd door AI?\n\nChatGPT, Gemini en Perplexity veranderen hoe mensen opties ontdekken, vergelijken en shortlist maken.\n\n{$fourthMessage}\n\nWat zou jij als eerste willen meten: vermeldingen, sentiment of ontbrekende antwoorden?",
                'data_driven' => "Minder klikken uit Google betekent niet minder belang voor zichtbaarheid.\n\n{$fifthMessage}\n\nDe vraag verschuift van alleen verkeer naar aanwezigheid in de antwoorden die beslissers al gebruiken.\n\n{$cta}",
                'technical_deep_dive' => "Technische noot: {$title}\n\n{$summary}\n\nDe architectuur moet approval, planning en providerlimieten gescheiden houden zodat publiceren controleerbaar blijft.",
                default => "{$title}\n\n{$summary}\n\nDe nuttige verschuiving voor {$audience}: maak elk inzicht makkelijker om op te volgen.\n\nWat zou jij toevoegen?",
            }
            : match ($type) {
                'thought_leadership' => "{$subject}\n\n{$firstMessage}\n\nThe useful shift for {$audience}: being found increasingly means becoming the clearest answer, not only the highest-ranking page.\n\n{$cta}",
                'seo_shift' => "SEO alone is no longer enough.\n\n{$secondMessage}\n\nAEO asks for content that is clear, factual, and answer-worthy for humans and AI systems.\n\n{$cta}",
                'practical_tip' => "3 ways to improve AI visibility today:\n\n1. Answer concrete buyer questions directly.\n2. Make definitions, steps, and proof easy to cite.\n3. Connect each article to clear context, sources, and next actions.\n\n{$sixthMessage}\n\n{$cta}",
                'trend_discussion' => "Is your company already mentioned by AI?\n\nChatGPT, Gemini, and Perplexity are changing how people discover, compare, and shortlist options.\n\n{$fourthMessage}\n\nWhat would you measure first: mentions, sentiment, or missing answers?",
                'data_driven' => "Fewer clicks from Google does not make visibility less important.\n\n{$fifthMessage}\n\nThe question is shifting from traffic alone to presence inside the answers decision-makers already use.\n\n{$cta}",
                'technical_deep_dive' => "Technical note: {$title}\n\n{$summary}\n\nThe architecture should keep approval, scheduling, and provider limits separate so publishing stays controlled.",
                default => "{$title}\n\n{$summary}\n\nThe useful shift for {$audience}: make every insight easier to act on.\n\nWhat would you add?",
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
