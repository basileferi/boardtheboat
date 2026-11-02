# 0003. One quality gate: `composer qa`

- **Status:** accepted
- **Date:** 2026-09-30

## Context

The project is developed on Windows with PowerShell 7 and cmd, and deployed on Linux. Any
tooling that assumes a POSIX shell (`&&` chains, `export`, `$(…)`, heredocs) breaks on one of
the two sides. We also want a single command a human, the IDE and CI can all run.

Xdebug is loaded in the WAMP CLI, which triples the runtime of static analysis and tests.

## Decision

All quality tooling is exposed through Composer scripts, which run the same in PowerShell, cmd
and on the server because Composer — not the shell — sequences them:

| Script | What it does |
|---|---|
| `composer cs` / `cs:check` | php-cs-fixer, `@PSR12` + `@Symfony` + risky rules + `declare_strict_types` |
| `composer phpstan` | PHPStan level 8 with the Symfony, Doctrine and PHPUnit extensions |
| `composer test` | PHPUnit |
| `composer lint:twig` / `lint:yaml` / `lint:container` / `lint:schema` | framework linters |
| `composer qa` | all of the above, plus `composer audit` |

Every script that runs PHP passes `-dxdebug.mode=off`, so the gate is fast without asking the
developer to change `php.ini`.

Static analysis runs against `src/` and `tests/`. The PHPStan bootstrap files
(`tests/object-manager.php`, `tests/console-application.php`) are excluded: they exist to give the
Doctrine and Symfony extensions a booted kernel, and analysing them would be circular.

## Consequences

- A slice is "done" only when `composer qa` is green; the pre-commit hook
  (`bin/qa-before-commit-hook.php`) enforces it by blocking `git commit` otherwise.
- Suppressing a PHPStan error with a baseline or an ignore comment is not an accepted fix:
  the code changes instead. `src/Kernel.php::getAllowedEnvs()` was made `protected` rather than
  ignored, and the environment guard was verified by booting with `APP_ENV=bogus`.
- `composer qa` will later gain `app:docs:generate` plus a `git diff --exit-code docs/generated`
  check, so stale generated documentation fails the build.
