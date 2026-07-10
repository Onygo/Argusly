<?php

it('keeps implicit migration identifiers within MySQL limits', function (): void {
    $violations = collect(glob(base_path('database/migrations/*.php')) ?: [])
        ->flatMap(fn (string $path): array => migrationIdentifierLengthViolations($path))
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

/**
 * @return array<int, string>
 */
function migrationIdentifierLengthViolations(string $path): array
{
    $violations = [];
    $tableName = null;

    foreach (file($path) ?: [] as $lineNumber => $line) {
        if (preg_match("/Schema::(?:create|table)\('([^']+)'/", $line, $matches) === 1) {
            $tableName = $matches[1];
        }

        if ($tableName !== null) {
            foreach (implicitIndexNames($tableName, $line) as $name) {
                if (strlen($name) > 64) {
                    $violations[] = sprintf('%s:%d %s (%d chars)', $path, $lineNumber + 1, $name, strlen($name));
                }
            }

            foreach (implicitForeignKeyNames($tableName, $line) as $name) {
                if (strlen($name) > 64) {
                    $violations[] = sprintf('%s:%d %s (%d chars)', $path, $lineNumber + 1, $name, strlen($name));
                }
            }
        }

        if ($tableName !== null && preg_match('/^\s*}\);/', $line) === 1) {
            $tableName = null;
        }
    }

    return $violations;
}

/**
 * @return array<int, string>
 */
function implicitIndexNames(string $tableName, string $line): array
{
    $names = [];

    if (preg_match("/\\\$table->(?:uuid|string|date|timestamp|char|foreignId|unsignedBigInteger|integer|unsignedInteger|boolean|decimal|text|json)\('([^']+)'.*->(index|unique)\(\s*\)/", $line, $matches) === 1) {
        $names[] = $tableName.'_'.$matches[1].'_'.($matches[2] === 'unique' ? 'unique' : 'index');
    }

    if (preg_match("/\\\$table->(index|unique)\(\s*(\[[^\]]+\]|'[^']+')\s*\)/", $line, $matches) === 1) {
        $columns = migrationIdentifierColumns($matches[2]);

        if ($columns !== []) {
            $names[] = $tableName.'_'.implode('_', $columns).'_'.($matches[1] === 'unique' ? 'unique' : 'index');
        }
    }

    return $names;
}

/**
 * @return array<int, string>
 */
function implicitForeignKeyNames(string $tableName, string $line): array
{
    if (preg_match("/\\\$table->foreign\(\s*(\[[^\]]+\]|'[^']+')\s*\)/", $line, $matches) !== 1) {
        return [];
    }

    $columns = migrationIdentifierColumns($matches[1]);

    if ($columns === []) {
        return [];
    }

    return [$tableName.'_'.implode('_', $columns).'_foreign'];
}

/**
 * @return array<int, string>
 */
function migrationIdentifierColumns(string $rawColumns): array
{
    preg_match_all("/'([^']+)'/", $rawColumns, $matches);

    return $matches[1] ?? [];
}
