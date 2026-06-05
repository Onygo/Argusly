<?php

namespace App\Console\Commands;

use App\Services\Seo\StructuredDataValidationService;
use Illuminate\Console\Command;

class ValidateStructuredDataCommand extends Command
{
    protected $signature = 'seo:validate-structured-data';

    protected $description = 'Validate required fields for public JSON-LD structured data.';

    public function handle(StructuredDataValidationService $validator): int
    {
        $result = $validator->validate();
        $this->line('Checked: ' . $result['summary']['checked']);
        $this->line('Items with issues: ' . $result['summary']['with_issues']);

        foreach (array_slice($result['items'], 0, 20) as $item) {
            $this->line(sprintf('- %s %s missing: %s', $item['type'], $item['title'], implode(', ', $item['missing'])));
        }

        return $result['summary']['with_issues'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
