<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DebugPhpMemoryCommand extends Command
{
    protected $signature = 'debug:php-memory';

    protected $description = 'Display PHP memory-related runtime diagnostics.';

    public function handle(): int
    {
        $before = ini_get('memory_limit');
        $setResult = ini_set('memory_limit', '2G');
        $after = ini_get('memory_limit');

        $this->line('PHP_BINARY: ' . PHP_BINARY);
        $this->line('phpversion(): ' . phpversion());
        $this->line('ini_get(memory_limit) before: ' . ($before === false ? 'false' : $before));
        $this->line('ini_set(memory_limit, 2G) result: ' . ($setResult === false ? 'false' : (string) $setResult));
        $this->line('ini_get(memory_limit) after: ' . ($after === false ? 'false' : $after));
        $this->line('php_ini_loaded_file(): ' . (php_ini_loaded_file() ?: 'false'));
        $this->line('php_ini_scanned_files(): ' . (php_ini_scanned_files() ?: 'false'));

        return self::SUCCESS;
    }
}
