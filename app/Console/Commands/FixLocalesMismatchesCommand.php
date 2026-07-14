<?php

namespace App\Console\Commands;

use App\Enums\SupportedLanguage;
use App\Models\Content;
use App\Services\Content\ContentDeclaredLocaleTranslationRepairService;
use App\Services\Content\LocaleMismatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixLocalesMismatchesCommand extends Command
{
    protected $signature = 'content:fix-locale-mismatches
        {--site= : Filter by client site ID}
        {--dry-run : Show what would be fixed without making changes}
        {--limit=100 : Maximum number of content items to analyze}
        {--min-confidence=0.7 : Minimum detection confidence (0.0-1.0)}
        {--auto-fix : Automatically fix detected mismatches}
        {--declared-locale= : Only process mismatches whose stored locale matches this locale}
        {--translate-to-declared : Translate mismatched text into the stored locale instead of changing locale metadata}
        {--preserve-slugs : Keep existing slug fields when translating to the stored locale}
        {--model= : Override the LLM model for translate-to-declared repairs}
        {--fix-families : Also enforce single source per family}';

    protected $description = 'Detect and fix locale mismatches in content where declared locale does not match actual language.';

    public function handle(
        LocaleMismatchService $service,
        ContentDeclaredLocaleTranslationRepairService $translationRepair,
    ): int
    {
        $siteId = $this->option('site') ? trim((string) $this->option('site')) : null;
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $minConfidence = max(0.0, min(1.0, (float) $this->option('min-confidence')));
        $autoFix = (bool) $this->option('auto-fix');
        $translateToDeclared = (bool) $this->option('translate-to-declared');
        $preserveSlugs = (bool) $this->option('preserve-slugs');
        $modelOverride = trim((string) $this->option('model')) ?: null;
        $fixFamilies = (bool) $this->option('fix-families');
        $declaredLocale = null;
        $declaredLocaleOption = trim((string) $this->option('declared-locale'));

        if ($declaredLocaleOption !== '') {
            $declaredLocale = SupportedLanguage::tryFromString($declaredLocaleOption);

            if (! $declaredLocale instanceof SupportedLanguage) {
                $this->error(sprintf(
                    'Unsupported declared locale "%s". Supported locales: %s',
                    $declaredLocaleOption,
                    implode(', ', SupportedLanguage::values()),
                ));

                return self::FAILURE;
            }
        }

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $this->info('Analyzing content for locale mismatches...');
        $this->newLine();

        $mismatches = $service->findMismatches($siteId, $limit)
            ->filter(fn (array $item) => $item['analysis']['confidence'] >= $minConfidence);

        if ($declaredLocale instanceof SupportedLanguage) {
            $mismatches = $mismatches
                ->filter(fn (array $item) => $item['analysis']['declared_locale'] === $declaredLocale->value)
                ->values();
        }

        if ($mismatches->isEmpty()) {
            $this->info('No locale mismatches detected.');

            if ($fixFamilies) {
                return $this->fixFamilyIntegrity($service, $siteId, $dryRun);
            }

            return self::SUCCESS;
        }

        $this->table(
            ['Content ID', 'Title', 'Declared', 'Detected', 'Confidence', 'Can Fix', 'Action'],
            $mismatches->map(fn (array $item) => [
                $item['content']->id,
                \Illuminate\Support\Str::limit($item['content']->title, 40),
                $item['analysis']['declared_locale'],
                $item['analysis']['detected_locale'] ?? 'unknown',
                sprintf('%.0f%%', $item['analysis']['confidence'] * 100),
                $translateToDeclared
                    ? ($item['analysis']['detected_locale'] !== null ? 'Translate' : 'No')
                    : ($item['analysis']['can_auto_fix'] ? 'Yes' : ((bool) ($item['analysis']['can_swap_locales'] ?? false) ? 'Swap' : 'No')),
                $translateToDeclared ? 'translate_to_declared' : ($item['analysis']['fix_action'] ?? '-'),
            ])->all()
        );

        $this->newLine();
        $this->info(sprintf('Found %d content items with locale mismatches.', $mismatches->count()));

        if (! $autoFix) {
            $this->comment($translateToDeclared
                ? 'Run with --auto-fix to translate these items into their stored locale. Slugs will be updated unless --preserve-slugs is used.'
                : 'Run with --auto-fix to automatically fix these mismatches.');

            if ($fixFamilies) {
                return $this->fixFamilyIntegrity($service, $siteId, $dryRun);
            }

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info($translateToDeclared
                ? sprintf(
                    'Would translate the above mismatches into their stored locale and %s slugs (dry run mode).',
                    $preserveSlugs ? 'preserve' : 'update',
                )
                : 'Would fix the above mismatches (dry run mode).');

            if ($fixFamilies) {
                return $this->fixFamilyIntegrity($service, $siteId, $dryRun);
            }

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info($translateToDeclared
            ? 'Translating mismatches into their stored locale...'
            : 'Fixing locale mismatches...');

        $fixed = 0;
        $failed = 0;

        $progressBar = $this->output->createProgressBar($mismatches->count());
        $progressBar->start();

        foreach ($mismatches as $item) {
            $content = $item['content'];
            $analysis = $item['analysis'];

            if ($translateToDeclared) {
                if ($analysis['detected_locale'] === null) {
                    $failed++;
                    $progressBar->advance();

                    continue;
                }

                $sourceLanguage = SupportedLanguage::tryFromString((string) $analysis['detected_locale']);
                $targetLanguage = SupportedLanguage::tryFromString((string) $analysis['declared_locale']);

                if (! $sourceLanguage instanceof SupportedLanguage
                    || ! $targetLanguage instanceof SupportedLanguage
                    || $sourceLanguage === $targetLanguage
                ) {
                    $failed++;
                    $progressBar->advance();

                    continue;
                }

                try {
                    $result = $translationRepair->translate(
                        $content,
                        $sourceLanguage,
                        updateSlug: ! $preserveSlugs,
                        modelOverride: $modelOverride,
                    );

                    if ((bool) ($result['success'] ?? false)) {
                        $fixed++;
                    } else {
                        $failed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->error("Failed to translate content {$content->id}: {$e->getMessage()}");
                }

                $progressBar->advance();

                continue;
            }

            $canSwapLocales = (bool) ($analysis['can_swap_locales'] ?? false);

            if (! $analysis['can_auto_fix'] && ! $canSwapLocales) {
                $progressBar->advance();

                continue;
            }

            if ($analysis['detected_locale'] === null) {
                $failed++;
                $progressBar->advance();

                continue;
            }

            $newLocale = SupportedLanguage::tryFrom($analysis['detected_locale']);
            if ($newLocale === null) {
                $failed++;
                $progressBar->advance();

                continue;
            }

            try {
                $result = (string) ($analysis['fix_action'] ?? '') === 'swap_inverted_locales'
                    ? $this->swapInvertedLocales($service, $content, (string) ($analysis['conflicting_content_id'] ?? ''))
                    : ($analysis['declared_locale'] === SupportedLanguage::EN->value
                    && $analysis['detected_locale'] === SupportedLanguage::NL->value
                    ? ['success' => true] + $service->autoCorrectSourceLocale($content)
                    : $service->fixLocale($content, $newLocale));

                if ((bool) ($result['success'] ?? false)) {
                    $fixed++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->error("Failed to fix content {$content->id}: {$e->getMessage()}");
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("Fixed: {$fixed}, Failed: {$failed}");

        if ($fixFamilies) {
            return $this->fixFamilyIntegrity($service, $siteId, $dryRun);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{success: bool, message: string, changes: array<string,mixed>}
     */
    private function swapInvertedLocales(LocaleMismatchService $service, Content $content, string $conflictingContentId): array
    {
        if ($conflictingContentId === '') {
            return [
                'success' => false,
                'message' => 'Cannot swap inverted locales: missing conflicting content id.',
                'changes' => [],
            ];
        }

        $variant = Content::query()->find($conflictingContentId);
        if (! $variant instanceof Content) {
            return [
                'success' => false,
                'message' => "Cannot swap inverted locales: conflicting content {$conflictingContentId} was not found.",
                'changes' => [],
            ];
        }

        return $service->swapInvertedLocales($content, $variant);
    }

    private function fixFamilyIntegrity(LocaleMismatchService $service, ?string $siteId, bool $dryRun): int
    {
        $this->newLine();
        $this->info('Checking family integrity (single source per family)...');

        // Find families with multiple sources
        $query = DB::table('contents')
            ->select('family_id', DB::raw('COUNT(*) as source_count'))
            ->whereNotNull('family_id')
            ->where('is_source_locale', true)
            ->where('status', '!=', 'archived')
            ->groupBy('family_id')
            ->having('source_count', '>', 1);

        if ($siteId !== null) {
            $query->where('client_site_id', $siteId);
        }

        $problematicFamilies = $query->pluck('family_id');

        if ($problematicFamilies->isEmpty()) {
            $this->info('All families have single source. No fixes needed.');

            return self::SUCCESS;
        }

        $this->warn(sprintf('Found %d families with multiple sources.', $problematicFamilies->count()));

        if ($dryRun) {
            $this->info('Would fix the above families (dry run mode).');

            return self::SUCCESS;
        }

        $fixed = 0;

        foreach ($problematicFamilies as $familyId) {
            $result = $service->enforceSingleSourcePerFamily($familyId);
            $fixed += $result['fixed'];

            if (! empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    $this->error("Family {$familyId}: {$error}");
                }
            }
        }

        $this->info("Fixed {$fixed} content items across {$problematicFamilies->count()} families.");

        return self::SUCCESS;
    }
}
