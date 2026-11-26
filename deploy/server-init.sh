#!/usr/bin/env bash
#
# One-time preparation of the Infomaniak host, run by hand over SSH before the first deployment:
#   bash server-init.sh /home/clients/<id>/sites/<site-folder>
#
# Idempotent: it never overwrites an existing shared/.env.local nor an existing "current".
# It creates the releases/shared layout, a placeholder page served by current/public (so the
# document root can be switched in the Manager before any real release exists) and a
# shared/.env.local whose random secrets are generated here, on the host, and never leave it.

set -Eeuo pipefail
umask 022

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

deploy_path="${1:-}"
[ -n "$deploy_path" ] || die "usage: bash server-init.sh <absolute path of the site folder>"
[[ "$deploy_path" =~ ^/[A-Za-z0-9._/-]+$ && "$deploy_path" != *..* ]] || die "invalid path: $deploy_path"
[ -d "$deploy_path" ] || die "$deploy_path does not exist"
command -v openssl >/dev/null 2>&1 || die "openssl is required"

shared="$deploy_path/shared"
placeholder="00000000000000-init"

mkdir -p "$deploy_path/releases" "$deploy_path/.incoming" \
    "$shared/config/jwt" "$shared/var/log" "$shared/var/share" "$shared/backups"
chmod 700 "$deploy_path/.incoming" "$shared/config/jwt" "$shared/backups"

# Defence in depth: should the document root ever point above current/public, these folders
# still refuse to serve anything.
for dir in "$shared" "$deploy_path/.incoming"; do
    printf 'Require all denied\n' > "$dir/.htaccess"
    chmod 644 "$dir/.htaccess"
done

if [ ! -e "$deploy_path/current" ]; then
    mkdir -p "$deploy_path/releases/$placeholder/public"
    cat > "$deploy_path/releases/$placeholder/public/index.html" <<'HTML'
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>boardtheboat</title></head>
<body><p>Installation en cours.</p></body></html>
HTML
    ln -s "releases/$placeholder" "$deploy_path/current"
    echo "created current -> releases/$placeholder (placeholder page)"
elif [ ! -L "$deploy_path/current" ]; then
    die "$deploy_path/current exists and is not a symlink: move it away first"
fi

env_file="$shared/.env.local"
if [ -f "$env_file" ]; then
    echo "$env_file already exists: left untouched"
else
    app_secret="$(openssl rand -hex 16)"
    cron_token="$(openssl rand -hex 32)"
    jwt_passphrase="$(openssl rand -hex 32)"
    (
        umask 077
        cat > "$env_file" <<ENV
# Production settings. This file never leaves the server (chmod 600).
# Replace every placeholder value below: the deployment refuses to run while one remains.
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=${app_secret}
DEFAULT_URI=https://CHANGE_ME_DOMAIN

# Password URL-encoded: php -r "echo rawurlencode('the password');"
DATABASE_URL="mysql://CHANGE_ME_USER:CHANGE_ME_PASSWORD@CHANGE_ME_HOST:3306/CHANGE_ME_DATABASE?serverVersion=10.6.28-MariaDB&charset=utf8mb4"

# Infomaniak shared hosting hands PHP the real client address and HTTPS=on: no proxy to trust.
# Never add REMOTE_ADDR here, it would let any client forge X-Forwarded-For.
TRUSTED_PROXIES=127.0.0.1
APP_REGISTRATION_OPEN=0
MAINTENANCE_ENABLED=0

MAILER_DSN=null://null
MAILER_FROM_ADDRESS=CHANGE_ME_SENDER_ADDRESS
MAILER_FROM_NAME=boardtheboat
ADMIN_ALERT_ADDRESS=CHANGE_ME_ADMIN_ADDRESS
HTTP_FETCHER_CONTACT_URL=https://CHANGE_ME_DOMAIN

CRON_TOKEN=${cron_token}
JWT_PASSPHRASE=${jwt_passphrase}

FRANCE_TRAVAIL_CLIENT_ID=
FRANCE_TRAVAIL_CLIENT_SECRET=
ENV
    )
    echo "created $env_file with fresh APP_SECRET, CRON_TOKEN and JWT_PASSPHRASE"
fi
chmod 600 "$env_file"

cat <<DONE

Layout ready in $deploy_path
Next steps:
  1. edit $env_file and replace every CHANGE_ME
  2. Manager: set the site document root to  $deploy_path/current/public  (path as the Manager expects it)
     then open the site: the placeholder page "Installation en cours." must show
  3. push to master and approve the "production" deployment in GitHub Actions
DONE
