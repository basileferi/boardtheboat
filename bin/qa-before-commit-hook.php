<?php

/**
 * Pre-commit hook: refuse a `git commit` while `composer qa` is red.
 *
 * Exit code 2 blocks the command and returns STDERR to the caller.
 * Any other command passes through.
 */

declare(strict_types=1);

$projectDir = \dirname(__DIR__);

$payload = json_decode((string) file_get_contents('php://stdin'), true);
if (!\is_array($payload) || 'Bash' !== ($payload['tool_name'] ?? null)) {
    exit(0);
}

$command = (string) ($payload['tool_input']['command'] ?? '');
if (!preg_match('/\bgit\s+(-\S+\s+)*commit\b/', $command)) {
    exit(0);
}

// A commit that only records a message change (amend of message, or --no-verify asked
// for explicitly by the user) still has to pass: there is no exception on purpose.
putenv('XDEBUG_MODE=off');

$qa = sprintf('composer --working-dir=%s --no-interaction qa', escapeshellarg($projectDir));
exec($qa.' 2>&1', $output, $status);

if (0 !== $status) {
    fwrite(STDERR, "composer qa failed - commit blocked. Fix these first:\n".implode("\n", \array_slice($output, -40))."\n");
    exit(2);
}

exit(0);
