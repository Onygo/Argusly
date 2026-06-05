<?php

namespace App\Console\Commands;

use App\Enums\DraftType;
use App\Enums\SupportedLanguage;
use App\Models\Draft;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillDraftLanguagesCommand extends Command
{
    protected $signature = 'drafts:backfill-languages
        {--dry-run : Show what would be updated without making changes}
        {--batch-size=500 : Number of records to process per batch}
        {--default=en : Default language for drafts without a brief}';

    protected $description = 'Backfill language and draft_type fields for existing drafts';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');
        $defaultLanguage = $this->option('default');

        if (! SupportedLanguage::tryFrom($defaultLanguage)) {
            $this->error("Invalid default language: {$defaultLanguage}");
            return self::FAILURE;
        }

        $this->info($dryRun ? 'DRY RUN - No changes will be made' : 'Starting backfill...');

        $totalNeedingUpdate = Draft::query()
            ->where(function ($query) {
                $query->whereNull('language')
                    ->orWhere('language', '');
            })
            ->count();

        $this->info("Found {$totalNeedingUpdate} drafts needing language backfill");

        if ($totalNeedingUpdate === 0) {
            $this->info('No drafts need updating');
            return self::SUCCESS;
        }

        $processed = 0;
        $updated = 0;
        $bar = $this->output->createProgressBar($totalNeedingUpdate);

        Draft::query()
            ->with('brief')
            ->where(function ($query) {
                $query->whereNull('language')
                    ->orWhere('language', '');
            })
            ->chunkById($batchSize, function ($drafts) use (&$processed, &$updated, $dryRun, $defaultLanguage, $bar) {
                $updates = [];

                foreach ($drafts as $draft) {
                    $processed++;

                    $language = $this->resolveLanguageForDraft($draft, $defaultLanguage);
                    $draftType = $this->resolveDraftType($draft);

                    $updates[] = [
                        'id' => $draft->id,
                        'language' => $language,
                        'draft_type' => $draftType,
                    ];

                    $bar->advance();
                }

                if (! $dryRun && ! empty($updates)) {
                    foreach ($updates as $update) {
                        DB::table('drafts')
                            ->where('id', $update['id'])
                            ->update([
                                'language' => $update['language'],
                                'draft_type' => $update['draft_type'],
                            ]);
                    }
                }

                $updated += count($updates);
            });

        $bar->finish();
        $this->newLine(2);

        $this->info("Processed {$processed} drafts");
        $this->info($dryRun ? "Would update {$updated} drafts" : "Updated {$updated} drafts");

        $this->backfillWorkspaceLanguageSettings($dryRun);
        $this->backfillContentLanguages($dryRun, $batchSize, $defaultLanguage);

        return self::SUCCESS;
    }

    private function resolveLanguageForDraft(Draft $draft, string $default): string
    {
        $briefLanguage = $draft->brief?->language;
        if ($briefLanguage && SupportedLanguage::tryFrom($briefLanguage)) {
            return $briefLanguage;
        }

        $metaLanguage = data_get($draft->meta, 'language');
        if (is_string($metaLanguage) && SupportedLanguage::tryFrom($metaLanguage)) {
            return $metaLanguage;
        }

        return $default;
    }

    private function resolveDraftType(Draft $draft): string
    {
        if ($draft->draft_comparison_id) {
            $comparisonMeta = data_get($draft->meta, 'draft_compare', []);
            if (data_get($comparisonMeta, 'is_hybrid', false)) {
                return DraftType::HYBRID->value;
            }
        }

        return DraftType::ORIGINAL->value;
    }

    private function backfillWorkspaceLanguageSettings(bool $dryRun): void
    {
        $this->info('Checking workspace language settings...');

        $workspacesNeedingUpdate = DB::table('workspaces')
            ->whereNull('default_content_language')
            ->orWhere('default_content_language', '')
            ->count();

        if ($workspacesNeedingUpdate === 0) {
            $this->info('All workspaces have language settings');
            return;
        }

        $this->info("Found {$workspacesNeedingUpdate} workspaces needing language settings");

        if (! $dryRun) {
            DB::table('workspaces')
                ->whereNull('default_content_language')
                ->orWhere('default_content_language', '')
                ->update([
                    'default_content_language' => SupportedLanguage::default()->value,
                    'enabled_content_languages' => json_encode([
                        SupportedLanguage::EN->value,
                        SupportedLanguage::NL->value,
                    ]),
                ]);
        }

        $this->info($dryRun ? "Would update {$workspacesNeedingUpdate} workspaces" : "Updated {$workspacesNeedingUpdate} workspaces");
    }

    private function backfillContentLanguages(bool $dryRun, int $batchSize, string $defaultLanguage): void
    {
        $this->info('Checking content language settings...');

        $contentsNeedingUpdate = DB::table('contents')
            ->whereNull('language')
            ->orWhere('language', '')
            ->count();

        if ($contentsNeedingUpdate === 0) {
            $this->info('All content records have language settings');
            return;
        }

        $this->info("Found {$contentsNeedingUpdate} content records needing language");

        if (! $dryRun) {
            DB::statement("
                UPDATE contents c
                LEFT JOIN (
                    SELECT content_id, language
                    FROM drafts
                    WHERE language IS NOT NULL AND language != ''
                    GROUP BY content_id
                ) d ON c.id = d.content_id
                SET c.language = COALESCE(d.language, ?)
                WHERE c.language IS NULL OR c.language = ''
            ", [$defaultLanguage]);
        }

        $this->info($dryRun ? "Would update {$contentsNeedingUpdate} content records" : "Updated content records");
    }
}
