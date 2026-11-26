#!/usr/bin/env bash
#
# Points "current" back to an earlier release, atomically.
#
# Piped over SSH by the rollback workflow:
#   ssh production bash -s -- <deploy_path> <php_bin> [release_id] < deploy/remote-rollback.sh
#
# Without a release id, the release just before the active one is restored.
# The database is NOT rolled back: if the release being left ran migrations, restore the
# matching backup from shared/backups by hand (see docs/DEPLOY.md).

main() {
    set -Eeuo pipefail
    exec </dev/null

    local deploy_path="${1:?deploy path required}"
    local php_bin="${2:-/opt/php8.4/bin/php}"
    local target="${3:-}"

    [[ "$deploy_path" =~ ^/[A-Za-z0-9._/-]+$ && "$deploy_path" != *..* ]] || die "invalid deploy path: $deploy_path"
    if [ -n "$target" ]; then
        [[ "$target" =~ ^[0-9]{14}-[0-9a-f]{12}$ ]] || die "invalid release id: $target"
    fi

    local releases="$deploy_path/releases"
    local current="$deploy_path/current"
    [ -L "$current" ] || die "$current is not a symlink: nothing to roll back"

    exec 9>"$deploy_path/.deploy.lock"
    flock -n 9 || die "a deployment is running on this host"

    local active
    active="$(basename "$(readlink "$current")")"

    if [ -z "$target" ]; then
        target="$(find "$releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' \
            | grep -E '^[0-9]{14}-[0-9a-f]{12}$' | sort \
            | awk -v active="$active" '$0 == active { print prev; exit } { prev = $0 }')"
        [ -n "$target" ] || die "no release older than $active is available"
    fi

    [ "$target" != "$active" ] || die "$target is already the active release"
    [ -f "$releases/$target/vendor/autoload_runtime.php" ] || die "release $target is missing or incomplete"
    [ -d "$releases/$target/var/cache/prod" ] || die "release $target has no warmed cache"

    log "active release: $active"
    log "restoring:      $target"
    ln -sfn "releases/$target" "$deploy_path/.current.next"
    mv -Tf "$deploy_path/.current.next" "$current"

    (cd "$releases/$target" && APP_ENV=prod APP_DEBUG=0 "$php_bin" bin/console --no-interaction messenger:stop-workers) \
        || warn "messenger:stop-workers failed (no worker running is fine)"

    log "rolled back to $target ($(cat "$releases/$target/REVISION" 2>/dev/null || echo 'unknown revision'))"
    warn "the database was not rolled back; latest backups:"
    find "$deploy_path/shared/backups" -maxdepth 1 -type f -name '*.sql.gz' -printf '%T@ %f\n' 2>/dev/null \
        | sort -rn | head -n 3 | cut -d' ' -f2- | sed 's/^/[rollback]   /' >&2 || true
}

log()  { printf '[rollback] %s\n' "$*"; }
warn() { printf '[rollback] WARNING: %s\n' "$*" >&2; }
die()  { printf '[rollback] ERROR: %s\n' "$*" >&2; exit 1; }

main "$@"
