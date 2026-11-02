# boardtheboat

Internship and spontaneous-application assistant for France, with an International switch.

Its job is to find **companies worth contacting** — whether or not they published an offer — rank
them against your profile, and (from Phase 2) prepare a per-company brief you can turn into a
motivation letter and a tailored CV.

Two modes, both first-class:

- **Automatic** — a scheduled pipeline discovers, deduplicates, scores and ranks companies and
  offers for your profile. You open the app and find a ranked list plus an "À relancer" panel.
- **Manual** — you add and manage companies, applications, saved views and document templates by
  hand. Manual entries flow into the same ranking.

Server-rendered Twig + Turbo + Stimulus. No SPA, no Node build step.

## Quickstart (Windows 11 + WAMP)

Requirements: WAMP with **PHP 8.5** (8.4 also works) and **MariaDB**, Composer 2, the Symfony CLI,
and the extensions `intl pdo_mysql mbstring xml curl zip opcache`.

```
php -v
composer install
```

Create the databases and the application user once (MariaDB listens on port 3307 in WAMP):

```
C:\wamp64\bin\mariadb\mariadb11.4.9\bin\mysql.exe -u root -h 127.0.0.1 -P 3307
```

```sql
CREATE DATABASE boardtheboat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE boardtheboat_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'boardtheboat'@'127.0.0.1' IDENTIFIED BY 'choose-one';
GRANT ALL PRIVILEGES ON boardtheboat.* TO 'boardtheboat'@'127.0.0.1';
GRANT ALL PRIVILEGES ON boardtheboat_test.* TO 'boardtheboat'@'127.0.0.1';
```

Copy the real values into `.env.local` (git-ignored — `.env` only ever holds dummies):

```
APP_SECRET=<32 random hex characters>
DATABASE_URL="mysql://boardtheboat:choose-one@127.0.0.1:3307/boardtheboat?serverVersion=11.4.9-MariaDB&charset=utf8mb4"
```

Then:

```
php bin/console doctrine:migrations:migrate -n
php bin/console doctrine:fixtures:load --group=users --append
php bin/console app:pipeline:run
symfony serve
```

The app answers on <http://127.0.0.1:8000/> (French by default, `/en` for English).

Test accounts (from the fixtures): `admin@test.fr` and `user@test.fr`, password `boardtheboat`.
`--group=demo` loads fake companies and offers too (it wipes the database unless `--append`).

The JSON API lives under `/api` and needs a token: `POST /api/login` with
`{"username": "...", "password": "..."}`, then `Authorization: Bearer <token>`. Generate the keys
once with `php bin/console lexik:jwt:generate-keypair` (on Windows set `OPENSSL_CONF` to PHP's
`extras/ssl/openssl.cnf` first).

## Running the tests

Symfony deliberately ignores `.env.local` when `APP_ENV=test`, so the test database connection
lives in its own git-ignored file. Create `.env.test.local` once:

```
DATABASE_URL="mysql://boardtheboat:choose-one@127.0.0.1:3307/boardtheboat?serverVersion=11.4.9-MariaDB&charset=utf8mb4"
```

Doctrine appends the `_test` suffix automatically, so this points at `boardtheboat_test`; the
schema is rebuilt by Foundry at the start of each run. Tests never reach the network.

```
composer test
```

## Day-to-day commands

```
composer qa                                  quality gate: cs, phpstan 8, linters, audit, tests
composer cs                                  apply coding standards
php bin/console messenger:consume async -vv  background worker (dev)
```

Each command works identically in PowerShell 7 and cmd: Composer sequences the steps, so no
shell-specific chaining is ever needed.

## Documentation

| File | What it holds |
|---|---|
| `docs/ARCHITECTURE.md` | layers, request flow, async flow |
| `docs/DOMAIN.md` | glossary and entity relationships |
| `docs/STACK_MAP.md` | which Symfony component is used where |
| `docs/DEPLOY.md`, `docs/RUNBOOK.md` | deployment to Infomaniak, operations |
| `docs/adr/` | one record per non-obvious decision |
| `docs/generated/` | produced by `php bin/console app:docs:generate` — never edited by hand |
