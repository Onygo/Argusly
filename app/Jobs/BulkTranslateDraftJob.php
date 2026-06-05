<?php

namespace App\Jobs;

use App\Enums\SupportedLanguage;
use App\Models\Draft;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BulkTranslateDraftJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(
        public string $sourceDraftId,
        public array $targetLanguages,
        public ?string $userId = null,
        public ?string $modelOverride = null,
    ) {
        $this->queue = config('translation.queue.name', 'default');
        $this->connection = config('translation.queue.connection');
    }

    public function handle(): void
    {
        $sourceDraft = Draft::query()->find($this->sourceDraftId);

        if (! $sourceDraft) {
            Log::warning('BulkTranslateDraftJob: source draft not found', [
                'source_draft_id' => $this->sourceDraftId,
            ]);
            return;
        }

        $delay = (int) config('translation.bulk.delay_between_jobs_seconds', 2);
        $maxLanguages = (int) config('translation.bulk.max_languages_per_batch', 5);

        $languages = array_slice($this->targetLanguages, 0, $maxLanguages);

        Log::info('BulkTranslateDraftJob dispatching translation jobs', [
            'source_draft_id' => $this->sourceDraftId,
            'target_languages' => $languages,
            'user_id' => $this->userId,
        ]);

        $dispatched = 0;
        foreach ($languages as $languageCode) {
            $language = SupportedLanguage::tryFrom($languageCode);

            if (! $language) {
                Log::warning('BulkTranslateDraftJob: invalid language code', [
                    'source_draft_id' => $this->sourceDraftId,
                    'language_code' => $languageCode,
                ]);
                continue;
            }

            if ($language === $sourceDraft->language) {
                continue;
            }

            TranslateDraftJob::dispatch(
                $this->sourceDraftId,
                $language->value,
                $this->userId,
                $this->modelOverride,
            )->delay(now()->addSeconds($delay * $dispatched));

            $dispatched++;
        }

        Log::info('BulkTranslateDraftJob dispatched jobs', [
            'source_draft_id' => $this->sourceDraftId,
            'jobs_dispatched' => $dispatched,
        ]);
    }
}
