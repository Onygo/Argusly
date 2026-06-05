<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Services\Content\TranslationDebugService;
use Illuminate\Console\Command;

class ContentTranslationDebugCommand extends Command
{
    protected $signature = 'content:translation-debug {contentId}';

    protected $description = 'Show translation diagnostics for a content family.';

    public function __construct(
        private readonly TranslationDebugService $debug,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $content = Content::query()->find($this->argument('contentId'));

        if (! $content instanceof Content) {
            $this->error('Content not found.');

            return self::FAILURE;
        }

        $data = $this->debug->diagnosticsForContent($content);

        $this->info('Translation states');
        $this->table(
            ['Translation', 'Locale', 'Status', 'Queue state', 'Job UUID', 'Heartbeat age', 'Retry count', 'Last error'],
            collect($data['translations'])->map(fn (array $row): array => [
                $row['id'],
                strtoupper((string) $row['locale']),
                $row['status'],
                $row['queue_state'] ?? '-',
                $row['job_uuid'] ?? '-',
                $row['heartbeat_age_seconds'] !== null ? ((string) $row['heartbeat_age_seconds'] . 's') : '-',
                (string) ($row['retry_count'] ?? 0),
                $row['error_message'] ?? '-',
            ])->all()
        );

        $this->newLine();
        $this->info('Latest debug events');
        foreach ($data['events'] as $event) {
            $this->line(sprintf(
                '[%s] %s %s',
                $event->created_at?->format('Y-m-d H:i:s') ?? 'n/a',
                $event->event_type,
                $event->message,
            ));
        }

        $this->newLine();
        $this->info('Pending queue jobs');
        $this->line(json_encode($data['pending_jobs'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->info('Failed queue jobs');
        $this->line(json_encode($data['failed_jobs'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->info('translation.log tail');
        foreach ($data['log_tail'] as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
