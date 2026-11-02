<?php

/**
 * Editor hook: run php-cs-fixer on the PHP file that was just written.
 *
 * Written in PHP rather than shell so it behaves identically in PowerShell 7, cmd and CI.
 * Reads the hook payload on STDIN and always exits 0: formatting must never block work.
 */

declare(strict_types=1);

$projectDir = \dirname(__DIR__);

$payload = json_decode((string) file_get_contents('php://stdin'), true);
if (!\is_array($payload)) {
    exit(0);
}

$file = $payload['tool_input']['file_path'] ?? null;
if (!\is_string($file) || !str_ends_with(strtolower($file), '.php') || !is_file($file)) {
    exit(0);
}

$real = realpath($file);
$src = realpath($projectDir.'/src');
$tests = realpath($projectDir.'/tests');
if (false === $real || (!str_starts_with($real, (string) $src) && !str_starts_with($real, (string) $tests))) {
    exit(0);
}

$binary = $projectDir.'/vendor/bin/php-cs-fixer';
if (!is_file($binary)) {
    exit(0);
}

$command = sprintf(
    '%s %s fix --config=%s --quiet %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg($binary),
    escapeshellarg($projectDir.'/.php-cs-fixer.dist.php'),
    escapeshellarg($real),
);

putenv('XDEBUG_MODE=off');
putenv('PHP_CS_FIXER_IGNORE_ENV=1');
exec($command, $output, $status);

if (0 !== $status) {
    fwrite(STDERR, "php-cs-fixer could not format {$real}\n".implode("\n", $output)."\n");
}

exit(0);
