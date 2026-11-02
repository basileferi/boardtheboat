# 0001. Symfony 8.1 on PHP 8.5

- **Status:** accepted
- **Date:** 2026-09-30

## Context

The project targets Symfony 8.1, checked against symfony.com on 2026-09-30:

- Symfony 8.1.0 was released on 2026-05-29; 8.1.8 is the current patch.
- Symfony 8.0 is already out of maintenance.
- The current LTS is 7.4; the next LTS will be 8.4 (November 2027 branch).
- Symfony minor versions ship every six months (May and November); 8.1 receives bug fixes
  for eight months and security fixes for fourteen, i.e. until roughly July 2027.

The development machine (Windows 11 + WAMP) exposes **PHP 8.5.0** on both the CLI and Apache,
not the 8.4 assumed when the project brief was written. Every extension the stack needs is
already enabled: intl, pdo_mysql, mbstring, xml, curl, zip, opcache, sodium, gd.

## Decision

Build on Symfony 8.1 with `"php": ">=8.4"` in `composer.json`, and develop on the PHP 8.5
runtime that WAMP provides. PHPStan is pinned to the 8.4–8.5 range so it analyses the code
against both the minimum supported and the actually used runtime.

We accept a non-LTS branch because the project is a single-tenant application we deploy
ourselves, and an upgrade to the next LTS is a routine dependency bump rather than a rewrite.
A calendar reminder to move to the 8.4 LTS branch belongs in `docs/RUNBOOK.md`.

## Consequences

- Security patches must be pulled regularly; `composer audit` runs inside `composer qa`.
- Before July 2027 the app has to move to a maintained branch (8.2/8.3, then the 8.4 LTS).
- Code may use PHP 8.4 syntax but not 8.5-only features, so production can run 8.4 if the
  host does not offer 8.5.
- The project guidelines said "PHP 8.4 selected in WAMP"; they now record 8.5 as the dev runtime.
