#!/usr/bin/env bash
# Creates the filesystem skeleton for a brand-new mono-tenant instance:
# releases/, shared/ (with storage subtree), and installs the operator's
# own .env. Never clones the repo, never invents secrets, never seeds
# demo/business data, never overwrites an existing instance.
#
# Usage:
#   scripts/provision-instance.sh --instance-root PATH --env-file FILE
#       [--artifact FILE [--checksum-file FILE]]
#
# --env-file must be a complete, filled-in .env (see .env.production.example)
# with a real, non-empty APP_KEY — this script will not generate one for
# you; run `php artisan key:generate` against the file yourself first if
# needed. If --artifact is given, the initial release is deployed via
# scripts/deploy-instance.sh once the skeleton exists.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

INSTANCE_ROOT=""
ENV_FILE=""
ARTIFACT=""
CHECKSUM_FILE=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --instance-root) INSTANCE_ROOT="$2"; shift 2 ;;
        --env-file) ENV_FILE="$2"; shift 2 ;;
        --artifact) ARTIFACT="$2"; shift 2 ;;
        --checksum-file) CHECKSUM_FILE="$2"; shift 2 ;;
        -h|--help)
            grep '^#' "$0" | sed 's/^#//'
            exit 0
            ;;
        *) die "unknown argument: $1" ;;
    esac
done

[[ -n "$INSTANCE_ROOT" ]] || die "--instance-root is required"
[[ -n "$ENV_FILE" ]] || die "--env-file is required"
require_safe_absolute_path "$INSTANCE_ROOT" "--instance-root"
require_file "$ENV_FILE" "env file"

APP_KEY="$(env_get "$ENV_FILE" APP_KEY)"
[[ -n "$APP_KEY" ]] || die "APP_KEY is empty in $ENV_FILE — generate one (php artisan key:generate --show against a throwaway copy, or on the instance after linking) before provisioning"

DB_CONNECTION="$(env_get "$ENV_FILE" DB_CONNECTION)"
[[ "$DB_CONNECTION" == "sqlite" || "$DB_CONNECTION" == "mysql" ]] \
    || die "DB_CONNECTION in $ENV_FILE must be sqlite or mysql, got: '$DB_CONNECTION'"

if [[ -e "$INSTANCE_ROOT" ]]; then
    if [[ -n "$(ls -A "$INSTANCE_ROOT" 2>/dev/null)" ]]; then
        die "instance root already exists and is not empty, refusing to overwrite: $INSTANCE_ROOT"
    fi
fi

log "creating instance skeleton at $INSTANCE_ROOT"
mkdir -p "$INSTANCE_ROOT/releases"
mkdir -p "$INSTANCE_ROOT/shared/backups"
mkdir -p "$INSTANCE_ROOT/shared/database"
mkdir -p "$INSTANCE_ROOT/shared/storage/app/public"
mkdir -p "$INSTANCE_ROOT/shared/storage/app/private"
mkdir -p "$INSTANCE_ROOT/shared/storage/framework/cache/data"
mkdir -p "$INSTANCE_ROOT/shared/storage/framework/sessions"
mkdir -p "$INSTANCE_ROOT/shared/storage/framework/testing/disks"
mkdir -p "$INSTANCE_ROOT/shared/storage/framework/views"
mkdir -p "$INSTANCE_ROOT/shared/storage/logs"

# Instance-owned data only: no group/world write access by default. The
# deploying operator's umask/user should own these; we do not chmod 777
# anywhere, and we do not attempt to chown to a web-server user we cannot
# safely assume the name of.
chmod 750 "$INSTANCE_ROOT" "$INSTANCE_ROOT/shared" "$INSTANCE_ROOT/shared/backups" "$INSTANCE_ROOT/shared/database"
find "$INSTANCE_ROOT/shared/storage" -type d -exec chmod 750 {} +

log "installing shared/.env from $ENV_FILE"
install -m 600 "$ENV_FILE" "$INSTANCE_ROOT/shared/.env"

if [[ "$DB_CONNECTION" == "sqlite" ]]; then
    DB_DATABASE="$(env_get "$INSTANCE_ROOT/shared/.env" DB_DATABASE)"
    [[ -n "$DB_DATABASE" ]] || die "DB_CONNECTION=sqlite but DB_DATABASE is empty in the env file"
    require_safe_absolute_path "$DB_DATABASE" "DB_DATABASE"
    case "$DB_DATABASE" in
        "$INSTANCE_ROOT/shared/"*) : ;;
        *) die "DB_DATABASE ($DB_DATABASE) must live under $INSTANCE_ROOT/shared/ so it survives every release" ;;
    esac
    if [[ ! -e "$DB_DATABASE" ]]; then
        log "creating empty SQLite database at $DB_DATABASE"
        mkdir -p "$(dirname "$DB_DATABASE")"
        : > "$DB_DATABASE"
        chmod 640 "$DB_DATABASE"
    else
        log "SQLite database already present at $DB_DATABASE — leaving it as is"
    fi
fi

log "instance skeleton ready: $INSTANCE_ROOT"
log "remember the one-time host setup this script does not do: a cron entry"
log "  * * * * * php $INSTANCE_ROOT/current/artisan schedule:run >> /dev/null 2>&1"
log "and, if this instance prints/needs it, the separate kitchen-print-worker systemd unit."

if [[ -n "$ARTIFACT" ]]; then
    log "deploying initial release"
    args=(--instance-root "$INSTANCE_ROOT" --artifact "$ARTIFACT")
    [[ -n "$CHECKSUM_FILE" ]] && args+=(--checksum-file "$CHECKSUM_FILE")
    "$SCRIPT_DIR/deploy-instance.sh" "${args[@]}"
else
    log "no --artifact given: run scripts/deploy-instance.sh separately to deploy the first release"
fi
