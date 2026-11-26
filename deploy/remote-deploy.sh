#!/usr/bin/env bash
#
# Installs one release on the Infomaniak host and activates it atomically.
#
# Piped over SSH by the CI/CD workflow:
#   ssh production bash -s -- <deploy_path> <release_id> <php_bin> <keep_releases> <keep_backups> < deploy/remote-deploy.sh
#
# Layout under <deploy_path> (document root = <deploy_path>/current/public):
#   releases/<release_id>/   one immutable directory per deployment
#   shared/                  survives deployments: .env.local, config/jwt, var/log, var/share, backups
#   current -> releases/<release_id>
#   .incoming/<release_id>/  uploaded archive, removed once installed
#
# Order: verify -> extract -> link shared files -> warm cache -> back up DB -> migrate -> switch
# -> stop old workers -> prune. Anything failing before the switch leaves the site untouched.

main() {
    set -Eeuo pipefail
    umask 022
    # The script itself arrives on stdin: nothing below may read from it.
    exec </dev/null

    local deploy_path="${1:?deploy path required}"
    local release_id="${2:?release id required}"
    local php_bin="${3:-/opt/php8.4/bin/php}"
    local keep_releases="${4:-5}"
    local keep_backups="${5:-10}"

    [[ "$deploy_path" =~ ^/[A-Za-z0-9._/-]+$ && "$deploy_path" != *..* ]] || die "invalid deploy path: $deploy_path"
    [[ "$release_id" =~ ^[0-9]{14}-[0-9a-f]{12}$ ]] || die "invalid release id: $release_id"
    [[ "$keep_releases" =~ ^[0-9]+$ && "$keep_releases" -ge 3 ]] || die "keep_releases must be >= 3"
    [[ "$keep_backups" =~ ^[0-9]+$ && "$keep_backups" -ge 1 ]] || die "keep_backups must be >= 1"

    DEPLOY_PATH="$deploy_path"
    RELEASE_ID="$release_id"
    PHP_BIN="$php_bin"
    SHARED="$deploy_path/shared"
    RELEASES="$deploy_path/releases"
    RELEASE="$RELEASES/$release_id"
    INCOMING="$deploy_path/.incoming/$release_id"
    CURRENT="$deploy_path/current"
    RELEASE_CREATED=0
    SWITCHED=0
    MIGRATED=0
    BACKUP_FILE=""
    DB_CNF=""

    trap on_exit EXIT

    preflight

    exec 9>"$deploy_path/.deploy.lock"
    flock -n 9 || die "another deployment is running on this host"

    log "verifying archive checksum"
    (cd "$INCOMING" && sha256sum --check --strict --quiet release.tar.gz.sha256) || die "checksum mismatch"

    [ ! -e "$RELEASE" ] || die "release $release_id already exists"
    mkdir -p "$RELEASE"
    RELEASE_CREATED=1
    log "extracting release $release_id"
    tar -xzf "$INCOMING/release.tar.gz" -C "$RELEASE" --no-same-owner

    link_shared

    ensure_jwt_keys

    log "warming up the prod cache"
    console cache:warmup

    log "checking the database connection"
    console dbal:run-sql 'SELECT 1' >/dev/null || die "cannot reach the database (check DATABASE_URL in $SHARED/.env.local)"

    if console doctrine:migrations:up-to-date >/dev/null 2>&1; then
        log "database schema already up to date"
    else
        backup_database "$keep_backups"
        log "running migrations"
        MIGRATED=1
        console doctrine:migrations:migrate --allow-no-migration
    fi

    switch_current

    log "asking running workers to stop after their current message"
    console messenger:stop-workers || warn "messenger:stop-workers failed (no worker running is fine)"

    prune_releases "$keep_releases"

    log "release $release_id is live ($(cat "$RELEASE/REVISION" 2>/dev/null || echo 'unknown revision'))"
}

log()  { printf '[deploy] %s\n' "$*"; }
warn() { printf '[deploy] WARNING: %s\n' "$*" >&2; }
die()  { printf '[deploy] ERROR: %s\n' "$*" >&2; exit 1; }

console() {
    (cd "$RELEASE" && APP_ENV=prod APP_DEBUG=0 "$PHP_BIN" -d memory_limit=512M bin/console --no-interaction "$@")
}

on_exit() {
    local status=$?
    [ -n "$DB_CNF" ] && rm -f "$DB_CNF"
    [ -n "$BACKUP_FILE" ] && rm -f "$BACKUP_FILE.partial"
    rm -rf "${INCOMING:?}"
    if [ "$status" -ne 0 ] && [ "$SWITCHED" -eq 0 ]; then
        if [ "$RELEASE_CREATED" -eq 1 ]; then
            rm -rf "${RELEASE:?}"
        fi
        warn "deployment failed, the site still serves the previous release"
        if [ "$MIGRATED" -eq 1 ]; then
            warn "migrations had started: the previous code now runs against the migrated schema"
            warn "database backup taken before migrating: ${BACKUP_FILE:-none}"
        fi
    fi
    exit "$status"
}

preflight() {
    local tool
    for tool in tar sha256sum flock mysqldump gzip; do
        command -v "$tool" >/dev/null 2>&1 || die "missing tool on the host: $tool"
    done

    [ -x "$PHP_BIN" ] || die "PHP binary not found: $PHP_BIN"
    "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' || die "PHP 8.4+ required, got $("$PHP_BIN" -r 'echo PHP_VERSION;')"
    "$PHP_BIN" -r '
        $missing = array_filter(["ctype", "iconv", "intl", "mbstring", "pdo_mysql", "sodium", "openssl", "xml"], static fn (string $e): bool => !extension_loaded($e));
        if ($missing !== []) { fwrite(STDERR, "missing PHP extensions: ".implode(", ", $missing)."\n"); exit(1); }
    ' || die "PHP extensions check failed"

    [ -d "$SHARED" ] || die "$SHARED is missing: run deploy/server-init.sh once on the host"
    [ -f "$SHARED/.env.local" ] || die "$SHARED/.env.local is missing: run deploy/server-init.sh once, then fill it in"
    if grep -qE '^[^#]*CHANGE_ME' "$SHARED/.env.local"; then
        die "$SHARED/.env.local still contains CHANGE_ME placeholders"
    fi
    if [ -e "$CURRENT" ] && [ ! -L "$CURRENT" ]; then
        die "$CURRENT exists and is not a symlink: move it away before deploying"
    fi
    [ -f "$INCOMING/release.tar.gz" ] || die "uploaded archive not found in $INCOMING"
}

link_shared() {
    log "linking shared files"
    mkdir -p "$SHARED/config/jwt" "$SHARED/var/log" "$SHARED/var/share" "$SHARED/backups"
    chmod 700 "$SHARED/config/jwt" "$SHARED/backups"
    chmod 600 "$SHARED/.env.local"

    ln -s "$SHARED/.env.local" "$RELEASE/.env.local"
    rm -rf "$RELEASE/config/jwt"
    ln -s "$SHARED/config/jwt" "$RELEASE/config/jwt"
    mkdir -p "$RELEASE/var"
    ln -s "$SHARED/var/log" "$RELEASE/var/log"
    # APP_SHARE_DIR=var/share: app cache pools (scheduler state, rate limiters) outlive a release.
    ln -s "$SHARED/var/share" "$RELEASE/var/share"
}

ensure_jwt_keys() {
    if [ -f "$SHARED/config/jwt/private.pem" ] && [ -f "$SHARED/config/jwt/public.pem" ]; then
        return
    fi
    log "generating the JWT keypair (first deployment)"
    console lexik:jwt:generate-keypair --skip-if-exists
    chmod 600 "$SHARED/config/jwt/private.pem"
    chmod 644 "$SHARED/config/jwt/public.pem"
}

backup_database() {
    local keep="$1" db_name stamp
    DB_CNF="$(mktemp "$SHARED/backups/.client.XXXXXX.cnf")"
    chmod 600 "$DB_CNF"

    # Credentials go through a 0600 option file, never through the command line.
    db_name="$("$PHP_BIN" -r '
        require $argv[1]."/vendor/autoload.php";
        $_SERVER["APP_ENV"] = "prod";
        (new Symfony\Component\Dotenv\Dotenv())->bootEnv($argv[1]."/.env");
        $parts = parse_url((string) ($_SERVER["DATABASE_URL"] ?? ""));
        if (!is_array($parts) || !isset($parts["host"], $parts["user"], $parts["path"])) {
            fwrite(STDERR, "DATABASE_URL is not a usable mysql:// url\n");
            exit(1);
        }
        $quote = static fn (string $v): string => "\"".addcslashes($v, "\\\"")."\"";
        file_put_contents($argv[2], "[client]\n"
            ."host=".$quote($parts["host"])."\n"
            ."port=".(int) ($parts["port"] ?? 3306)."\n"
            ."user=".$quote(rawurldecode($parts["user"]))."\n"
            ."password=".$quote(rawurldecode($parts["pass"] ?? ""))."\n");
        echo ltrim(rawurldecode($parts["path"]), "/");
    ' -- "$RELEASE" "$DB_CNF")" || die "could not read DATABASE_URL"
    [[ "$db_name" =~ ^[A-Za-z0-9_]+$ ]] || die "unexpected database name"

    stamp="$(date -u +%Y%m%dT%H%M%SZ)"
    BACKUP_FILE="$SHARED/backups/${db_name}-${stamp}-before-${RELEASE_ID}.sql.gz"
    log "backing up database $db_name"
    mysqldump --defaults-extra-file="$DB_CNF" \
        --single-transaction --quick --no-tablespaces --skip-lock-tables \
        --default-character-set=utf8mb4 "$db_name" | gzip -9 > "$BACKUP_FILE.partial"
    mv "$BACKUP_FILE.partial" "$BACKUP_FILE"
    chmod 600 "$BACKUP_FILE"
    rm -f "$DB_CNF"
    DB_CNF=""
    log "backup written: $BACKUP_FILE"

    # Keep the newest backups only.
    find "$SHARED/backups" -maxdepth 1 -type f -name '*.sql.gz' -printf '%T@ %p\n' \
        | sort -rn | tail -n +"$((keep + 1))" | cut -d' ' -f2- \
        | while IFS= read -r old; do rm -f -- "$old"; done
}

switch_current() {
    log "switching current -> releases/$RELEASE_ID"
    ln -sfn "releases/$RELEASE_ID" "$DEPLOY_PATH/.current.next"
    # rename(2) is atomic: every request sees either the old or the new release.
    mv -Tf "$DEPLOY_PATH/.current.next" "$CURRENT"
    SWITCHED=1
}

prune_releases() {
    local keep="$1" active name
    active="$(basename "$(readlink "$CURRENT")")"
    find "$RELEASES" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' \
        | grep -E '^[0-9]{14}-[0-9a-z]+$' | sort -r | tail -n +"$((keep + 1))" \
        | while IFS= read -r name; do
            if [ "$name" != "$active" ]; then
                log "removing old release $name"
                rm -rf -- "${RELEASES:?}/$name"
            fi
        done
}

main "$@"
