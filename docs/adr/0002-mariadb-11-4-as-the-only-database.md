# 0002. MariaDB 11.4 as the only database

- **Status:** accepted
- **Date:** 2026-09-30

## Context

Production is Infomaniak shared hosting with a remote MariaDB instance. The development
machine runs WAMP, which ships both `wampmysqld64` (MySQL 8.4.7) and `wampmariadb64`
(MariaDB 11.4.9). Both services are running; MariaDB answers on port **3307**.

Doctrine changes its behaviour depending on the reported server version (identity generation,
JSON column type, index length, `MATCH … AGAINST` support), so a mismatch between dev and prod
is a source of migrations that pass locally and fail on the server.

## Decision

Develop and deploy on MariaDB only, and pin the version explicitly in `DATABASE_URL`:

```
mysql://boardtheboat:<password>@127.0.0.1:3307/boardtheboat?serverVersion=11.4.9-MariaDB&charset=utf8mb4
```

The application database, its user and the test database were created as:

```sql
CREATE DATABASE boardtheboat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE boardtheboat_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'boardtheboat'@'127.0.0.1' IDENTIFIED BY '…';
GRANT ALL PRIVILEGES ON boardtheboat.* TO 'boardtheboat'@'127.0.0.1';
GRANT ALL PRIVILEGES ON boardtheboat_test.* TO 'boardtheboat'@'127.0.0.1';
```

The test suite reuses the same credentials; `config/packages/doctrine.yaml` appends the
`_test` suffix to the database name in the test environment.

## Consequences

- No PostgreSQL-only feature may be used (no `ILIKE`, no array/JSONB operators, no `DISTINCT ON`).
- Full-text search uses a MySQL/MariaDB `FULLTEXT` index queried through a custom DQL
  `MATCH_AGAINST` function, declared in a later ADR when the entity ships.
- Every migration must be reviewed for MariaDB compatibility; generated SQL is never trusted blindly.
- At deploy time the production credentials use a least-privilege user; DDL rights are held by a
  separate migration account (see `docs/DEPLOY.md`).
