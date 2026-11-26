# 0008. Atomic releases over SSH, approved from GitHub Actions

- **Status:** accepted
- **Date:** 2026-11-26

## Context

Production is an Infomaniak shared web hosting account: SSH access without root, PHP 8.4.27 at
`/opt/php8.4/bin/php`, `rsync`, `tar`, `flock`, `mysqldump` available, no permanent worker, and a
remote **MariaDB 10.6.28**. Development runs MariaDB 11.4 (ADR 0002), so the version gap has to be
covered somewhere before code reaches production.

A Symfony release cannot be a plain file copy: the container cache embeds absolute paths and has
to be warmed on the host, migrations and the JWT keypair need the PHP CLI there, and a
file-by-file upload (FTP) serves a mix of old and new `vendor/` while it runs.

## Decision

GitHub Actions runs a secret scan and `composer qa` on PHP 8.4 against a MariaDB 10.6 service
(plus migrations on an empty database and `doctrine:schema:validate`), then builds one
`release.tar.gz` without dev dependencies. After a manual approval on the `production`
environment, the archive is copied over SSH (pinned host key, dedicated ed25519 key) into
`releases/<id>`, linked to `shared/` (`.env.local`, `config/jwt`, `var/log`, `var/share`),
warmed, the database is dumped if migrations are pending, migrations run, and the `current`
symlink is swapped with an atomic rename. Production secrets stay on the host only.

`framework.cache.prefix_seed` is fixed so cache pools in `var/share` (scheduler state, rate
limiters) keep their namespace across release directories. `DATABASE_URL` is read without the
`resolve:` processor so url-encoded passwords (`%23`, `%40`) are not mistaken for parameters.
Migrations run with `transactional: false`, since MariaDB commits implicitly after each DDL.

A probe on the host showed that PHP receives the real client address and `HTTPS=on` with no
reverse proxy in between, and that a client-sent `X-Forwarded-For` reaches PHP untouched.
`TRUSTED_PROXIES` therefore stays `127.0.0.1`; trusting `REMOTE_ADDR` would let any client
choose the IP the rate limiters see.

## Consequences

- A failed deployment leaves the previous release serving; rollback is a symlink swap.
- Migrations run before the switch: each one must stay compatible with the previous code.
- The database is never rolled back automatically; restoring a dump is a manual step.
- `composer audit` failing blocks every deployment until the vulnerable package is updated.
- ADR 0002 still describes 11.4 as the reference version; production being 10.6, migrations are
  checked against 10.6 in CI, and a feature only 11.x offers must not be relied upon.
