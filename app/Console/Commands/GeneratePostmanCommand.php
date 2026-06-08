<?php

namespace App\Console\Commands;

use App\Services\ApiDocs\PostmanGenerator;
use Illuminate\Console\Command;

class GeneratePostmanCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'argusly:generate-postman
        {--openapi= : Path to OpenAPI spec (default: docs/openapi/argusly.yaml)}
        {--output= : Output directory (default: docs/postman/)}
        {--collection-name= : Collection filename}
        {--environment-name= : Environment filename}';

    /**
     * The console command description.
     */
    protected $description = 'Generate Postman collection from OpenAPI specification';

    /**
     * Execute the console command.
     */
    public function handle(PostmanGenerator $generator): int
    {
        $this->info('Generating Postman collection...');
        $this->newLine();

        // Get paths
        $openApiPath = $this->option('openapi') ?? config('argusly-docs.openapi.output', 'docs/openapi/argusly.yaml');
        $outputDir = $this->option('output') ?? config('argusly-docs.postman.output_dir', 'docs/postman/');
        $collectionName = $this->option('collection-name') ?? 'argusly-collection.json';
        $environmentName = $this->option('environment-name') ?? 'argusly-environment.json';

        // Ensure output dir ends with /
        if (! str_ends_with($outputDir, '/')) {
            $outputDir .= '/';
        }

        // Check if OpenAPI file exists
        if (! file_exists(base_path($openApiPath))) {
            $this->components->error("OpenAPI file not found: {$openApiPath}");
            $this->line('Run `php artisan argusly:generate-openapi` first.');

            return self::FAILURE;
        }

        try {
            // Parse OpenAPI and generate collection
            $this->components->task('Parsing OpenAPI specification', function () {
                return true;
            });

            $collection = $generator->generateFromFile($openApiPath);

            // Count items
            $folderCount = count($collection['item'] ?? []);
            $requestCount = 0;
            foreach ($collection['item'] ?? [] as $folder) {
                $requestCount += count($folder['item'] ?? []);
            }

            $this->components->task("Generated {$requestCount} requests in {$folderCount} folders", function () {
                return true;
            });

            // Generate environment
            $environment = $generator->generateEnvironment();
            $variableCount = count($environment['values'] ?? []);

            $this->components->task("Generated environment with {$variableCount} variables", function () {
                return true;
            });

            // Write files
            $collectionPath = $generator->writeCollection($collection, $outputDir.$collectionName);
            $environmentPath = $generator->writeEnvironment($environment, $outputDir.$environmentName);

            $this->newLine();
            $this->components->info('Postman files generated:');
            $this->line("  Collection: <comment>{$collectionPath}</comment>");
            $this->line("  Environment: <comment>{$environmentPath}</comment>");

            // Show summary
            $this->newLine();
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Collection Name', $collection['info']['name'] ?? 'N/A'],
                    ['Total Folders', $folderCount],
                    ['Total Requests', $requestCount],
                    ['Environment Variables', $variableCount],
                ]
            );

            $this->newLine();
            $this->info('Import instructions:');
            $this->line('1. Open Postman');
            $this->line('2. Click Import → Upload Files');
            $this->line("3. Select both files from {$outputDir}");
            $this->line('4. Set your API key in the environment variables');

        } catch (\Throwable $e) {
            $this->components->error("Failed to generate Postman collection: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
