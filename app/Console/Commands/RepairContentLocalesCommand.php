<?php

namespace App\Console\Commands;

use App\Enums\DraftType;
use App\Enums\SupportedLanguage;
use App\Models\Brief;
use App\Models\Content;
use App\Models\Draft;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RepairContentLocalesCommand extends Command
{
    protected $signature = 'content:repair-locales
        {--dry-run : Preview locale repairs without persisting changes}';

    protected $description = 'Repair content, brief, and draft locale mismatches while preserving translation families.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $contents = Content::query()
            ->with([
                'brief',
                'drafts.sourceDraft',
                'currentVersion',
                'translationSourceContent',
            ])
            ->orderBy('created_at')
            ->get();

        $rows = [];
        $repairs = [];

        foreach ($contents as $content) {
            $repair = $this->inspectContent($content);

            if (! $repair['needs_changes']) {
                continue;
            }

            $rows[] = [
                'content_id' => (string) $content->id,
                'title' => mb_strimwidth((string) $content->title, 0, 36, '...'),
                'current' => strtoupper($content->localeCode()),
                'target' => strtoupper($repair['target_locale']),
                'source' => strtoupper($repair['source_locale'] ?? '-'),
                'scope' => implode(', ', $repair['change_scope']),
                'reason' => $repair['reason'],
            ];

            $repairs[] = $repair;
        }

        if ($rows === []) {
            $this->info('No locale repairs detected.');

            return self::SUCCESS;
        }

        $this->table(
            ['content_id', 'title', 'current', 'target', 'source', 'scope', 'reason'],
            $rows
        );

        if ($dryRun) {
            $this->warn(sprintf('Dry run: %d content locale repair(s) detected.', count($repairs)));

            return self::SUCCESS;
        }

        $applied = 0;

        foreach ($repairs as $repair) {
            DB::transaction(function () use ($repair): void {
                $this->applyRepair($repair);
            });

            $applied++;
        }

        $this->info(sprintf('Applied %d content locale repair(s).', $applied));

        return self::SUCCESS;
    }

    /**
     * @return array{
     *   needs_changes:bool,
     *   content:Content,
     *   target_locale:string,
     *   source_locale:?string,
     *   content_updates:array<string,mixed>,
     *   brief_updates:array<string,mixed>,
     *   draft_updates:array<int,array{id:string,attributes:array<string,mixed>}>,
     *   change_scope:array<int,string>,
     *   reason:string
     * }
     */
    private function inspectContent(Content $content): array
    {
        $targetLocale = $this->inferLocale($content);
        $sourceLocale = $content->translationSourceContent?->localeCode();
        $expectedSourceLocale = $content->isTranslationVariant()
            ? $sourceLocale
            : null;

        $contentUpdates = [];
        $briefUpdates = [];
        $draftUpdates = [];
        $changeScope = [];

        if ($content->localeCode() !== $targetLocale) {
            $contentUpdates['language'] = $targetLocale;
            $changeScope[] = 'content';
        }

        if ($content->isTranslationVariant()) {
            if ((string) ($content->translation_source_locale ?? '') !== (string) $expectedSourceLocale) {
                $contentUpdates['translation_source_locale'] = $expectedSourceLocale;
                $changeScope[] = 'source-locale';
            }

            if ((bool) $content->is_source_locale !== false) {
                $contentUpdates['is_source_locale'] = false;
                $changeScope[] = 'source-flag';
            }
        } else {
            if ($content->translation_source_locale !== null) {
                $contentUpdates['translation_source_locale'] = null;
                $changeScope[] = 'source-locale';
            }

            if ((bool) $content->is_source_locale !== true) {
                $contentUpdates['is_source_locale'] = true;
                $changeScope[] = 'source-flag';
            }
        }

        if ($content->brief instanceof Brief && SupportedLanguage::fromStringOrDefault((string) $content->brief->language)->value !== $targetLocale) {
            $briefUpdates['language'] = $targetLocale;
            $changeScope[] = 'brief';
        }

        foreach ($content->drafts as $draft) {
            $draftLocale = SupportedLanguage::fromStringOrDefault((string) $draft->getRawOriginal('language'))->value;
            $attributes = [];

            if ($draftLocale !== $targetLocale) {
                $attributes['language'] = $targetLocale;
            }

            $meta = is_array($draft->meta) ? $draft->meta : [];
            if ((string) data_get($meta, 'language', '') !== $targetLocale) {
                $meta['language'] = $targetLocale;
                $attributes['meta'] = $meta;
            }

            if ($draft->draft_type === DraftType::TRANSLATION && $draft->sourceDraft) {
                $translationSourceLocale = SupportedLanguage::fromStringOrDefault((string) $draft->sourceDraft->getRawOriginal('language'))->value;
                if ((string) ($draft->translation_source_language ?? '') !== $translationSourceLocale) {
                    $attributes['translation_source_language'] = $translationSourceLocale;
                }
            }

            if ($attributes !== []) {
                $draftUpdates[] = [
                    'id' => (string) $draft->id,
                    'attributes' => $attributes,
                ];
                $changeScope[] = 'draft';
            }
        }

        $reason = $this->repairReason($content, $targetLocale);

        return [
            'needs_changes' => $contentUpdates !== [] || $briefUpdates !== [] || $draftUpdates !== [],
            'content' => $content,
            'target_locale' => $targetLocale,
            'source_locale' => $expectedSourceLocale,
            'content_updates' => $contentUpdates,
            'brief_updates' => $briefUpdates,
            'draft_updates' => $draftUpdates,
            'change_scope' => array_values(array_unique($changeScope)),
            'reason' => $reason,
        ];
    }

    /**
     * @param  array{
     *   content:Content,
     *   content_updates:array<string,mixed>,
     *   brief_updates:array<string,mixed>,
     *   draft_updates:array<int,array{id:string,attributes:array<string,mixed>}>,
     *   target_locale:string,
     *   source_locale:?string,
     *   reason:string
     * }  $repair
     */
    private function applyRepair(array $repair): void
    {
        /** @var Content $content */
        $content = $repair['content'];

        if ($repair['content_updates'] !== []) {
            $meta = is_array($content->locale_repair_meta) ? $content->locale_repair_meta : [];
            $meta[] = [
                'repaired_at' => now()->toIso8601String(),
                'reason' => $repair['reason'],
                'target_locale' => $repair['target_locale'],
                'source_locale' => $repair['source_locale'],
                'changes' => array_keys($repair['content_updates']),
            ];

            $content->forceFill(array_merge($repair['content_updates'], [
                'locale_repair_meta' => $meta,
            ]))->save();
        }

        if ($repair['brief_updates'] !== [] && $content->brief instanceof Brief) {
            $content->brief->forceFill($repair['brief_updates'])->save();
        }

        foreach ($repair['draft_updates'] as $draftUpdate) {
            $draft = $content->drafts->firstWhere('id', $draftUpdate['id']);
            if (! $draft instanceof Draft) {
                continue;
            }

            $draft->forceFill($draftUpdate['attributes'])->save();
        }

        Log::info('content.locale_repair.applied', [
            'content_id' => (string) $content->id,
            'target_locale' => $repair['target_locale'],
            'source_locale' => $repair['source_locale'],
            'reason' => $repair['reason'],
            'content_changes' => array_keys($repair['content_updates']),
            'brief_changes' => array_keys($repair['brief_updates']),
            'draft_change_count' => count($repair['draft_updates']),
        ]);
    }

    private function inferLocale(Content $content): string
    {
        $scores = [];

        $this->addScore($scores, $content->localeCode(), 1);

        $versionLocale = SupportedLanguage::tryFromString((string) data_get($content->currentVersion?->meta, 'locale'))
            ?: SupportedLanguage::tryFromString((string) data_get($content->currentVersion?->meta, 'language'));
        if ($versionLocale instanceof SupportedLanguage) {
            $this->addScore($scores, $versionLocale->value, 6);
        }

        if ($content->brief instanceof Brief) {
            $briefLocale = SupportedLanguage::tryFromString((string) $content->brief->language);
            if ($briefLocale instanceof SupportedLanguage) {
                $this->addScore($scores, $briefLocale->value, 3);
            }
        }

        foreach ($content->drafts->sortByDesc('created_at')->take(3) as $draft) {
            $draftLocale = SupportedLanguage::tryFromString((string) $draft->getRawOriginal('language'));
            if ($draftLocale instanceof SupportedLanguage) {
                $this->addScore($scores, $draftLocale->value, 2);
            }
        }

        $textDetection = $this->detectTextLocale(
            title: (string) $content->title,
            excerpt: (string) data_get($content->currentVersion?->meta, 'excerpt', ''),
            body: (string) ($content->currentVersion?->body ?? ''),
            fallbackLocale: $content->localeCode(),
        );
        $this->addScore($scores, $textDetection['locale'], match ($textDetection['confidence']) {
            'high' => 6,
            'medium' => 2,
            default => 0,
        });

        arsort($scores);
        $winner = array_key_first($scores);

        return SupportedLanguage::fromStringOrDefault((string) $winner)->value;
    }

    /**
     * @param  array<string,int>  $scores
     */
    private function addScore(array &$scores, string $locale, int $weight): void
    {
        if ($weight <= 0 || ! SupportedLanguage::tryFromString($locale)) {
            return;
        }

        $scores[$locale] = ($scores[$locale] ?? 0) + $weight;
    }

    private function repairReason(Content $content, string $targetLocale): string
    {
        $briefLocale = $content->brief?->language ? SupportedLanguage::fromStringOrDefault((string) $content->brief->language)->value : null;
        $draftLocale = $content->drafts->sortByDesc('created_at')
            ->map(fn (Draft $draft): string => SupportedLanguage::fromStringOrDefault((string) $draft->getRawOriginal('language'))->value)
            ->first();

        return collect([
            $content->localeCode() !== $targetLocale ? 'content locale mismatch' : null,
            $briefLocale !== null && $briefLocale !== $targetLocale ? 'brief locale mismatch' : null,
            $draftLocale !== null && $draftLocale !== $targetLocale ? 'draft locale mismatch' : null,
        ])->filter()->implode('; ') ?: 'translation metadata mismatch';
    }

    /**
     * @return array{locale:string,confidence:string}
     */
    private function detectTextLocale(string $title, string $excerpt, string $body, string $fallbackLocale): array
    {
        $text = mb_strtolower(trim(strip_tags(implode(' ', array_filter([$title, $excerpt, $body])))));
        $text = preg_replace('/[^\p{L}\s]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        $dutchTerms = [
            'de', 'het', 'een', 'en', 'voor', 'van', 'met', 'op', 'dit', 'deze',
            'hoe', 'zo', 'je', 'jouw', 'niet', 'welke', 'waarom', 'nederlandse',
        ];
        $englishTerms = [
            'the', 'and', 'for', 'with', 'this', 'that', 'how', 'your', 'why',
            'english', 'translation', 'guide', 'build',
        ];

        $dutchScore = $this->scoreTerms($text, $dutchTerms);
        $englishScore = $this->scoreTerms($text, $englishTerms);

        return match (true) {
            $dutchScore >= max(2, $englishScore + 2) => ['locale' => SupportedLanguage::NL->value, 'confidence' => 'high'],
            $englishScore >= max(2, $dutchScore + 2) => ['locale' => SupportedLanguage::EN->value, 'confidence' => 'high'],
            $dutchScore > $englishScore => ['locale' => SupportedLanguage::NL->value, 'confidence' => 'medium'],
            $englishScore > $dutchScore => ['locale' => SupportedLanguage::EN->value, 'confidence' => 'medium'],
            default => ['locale' => $fallbackLocale, 'confidence' => 'low'],
        };
    }

    /**
     * @param  array<int,string>  $terms
     */
    private function scoreTerms(string $text, array $terms): int
    {
        $score = 0;

        foreach ($terms as $term) {
            $score += preg_match_all('/\b' . preg_quote($term, '/') . '\b/u', $text);
        }

        return $score;
    }
}
