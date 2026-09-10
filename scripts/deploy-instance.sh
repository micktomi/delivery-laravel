#!/usr/bin/env bash
# Deploys one release artifact to one already-provisioned mono-tenant
# instance (see scripts/provision-instance.sh), atomically. No `git`,
# `composer`, or `npm` runs against the instance — the artifact already
# contains everything needed to run.
#
# Usage:
#   scripts/deploy-instance.sh --instance-root PATH --artifact FILE
#       [--checksum-file FILE] [--release-id ID] [--health-timeout SECONDS]
#
# Instance layout expected under --instance-root (see provision-instance.sh):
#   releases/<release-id>/     candidate/previous releases (immutable once written)
#   current -> releases/<id>   atomically-switched symlink, what nginx/FPM serve
#   shared/.env
#   shared/storage/...
#   shared/database/database.sqlite   (only when the instance's .env is sqlite)
#   shared/backups/
#
# Exit non-zero on any failure. On a post-switch health-check failure, the
# `current` symlink is switched back to the previous release before exiting
# non-zero; the database is never touched by that revert.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

INSTANCE_ROOT=""
ARTIFACT=""
CHECKSUM_FILE=""
RELEASE_ID=""
HEALTH_TIMEOUT=30
KEEP_RELEASES=5

while [[ $# -gt 0 ]]; do
    case "$1" in
        --instance-root) INSTANCE_ROOT="$2"; shift 2 ;;
        --artifact) ARTIFACT="$2"; shift 2 ;;
        --checksum-file) CHECKSUM_FILE="$2"; shift 2 ;;
        --release-id) RELEASE_ID="$2"; shift 2 ;;
        --health-timeout) HEALTH_TIMEOUT="$2"; shift 2 ;;
        --keep-releases) KEEP_RELEASES="$2"; shift 2 ;;
        -h|--help)
            grep '^#' "$0" | sed 's/^#//'
            exit 0
            ;;
        *) die "unknown argument: $1" ;;
    esac
done

[[ -n "$INSTANCE_ROOT" ]] || die "--instance-root is required"
[[ -n "$ARTIFACT" ]] || die "--artifact is required"
require_safe_absolute_path "$INSTANCE_ROOT" "--instance-root"
require_cmd tar
require_cmd php

require_dir "$INSTANCE_ROOT" "instance root does not exist — run provision-instance.sh first"
require_dir "$INSTANCE_ROOT/shared" "instance is missing shared/ — not a provisioned instance"
require_file "$INSTANCE_ROOT/shared/.env" "instance is missing shared/.env — not a provisioned instance"
require_file "$ARTIFACT" "release artifact"

if [[ -z "$RELEASE_ID" ]]; then
    RELEASE_ID="$(basename "$ARTIFACT")"
    RELEASE_ID="${RELEASE_ID#delivery-}"
    RELEASE_ID="${RELEASE_ID%.tar.gz}"
fi
[[ "$RELEASE_ID" =~ ^[A-Za-z0-9._-]+$ ]] \
    || die "release id must match [A-Za-z0-9._-]+, got: $RELEASE_ID"

if [[ -n "$CHECKSUM_FILE" ]]; then
    require_file "$CHECKSUM_FILE" "checksum file"
    EXPECTED="$(awk '{print $1}' "$CHECKSUM_FILE" | head -n1)"
    ACTUAL="$(sha256_file "$ARTIFACT")"
    [[ "$EXPECTED" == "$ACTUAL" ]] \
        || die "checksum mismatch for $ARTIFACT: expected $EXPECTED, got $ACTUAL"
    log "checksum verified: $ACTUAL"
else
    log "WARNING: no --checksum-file supplied, artifact integrity was not verified"
fi

RELEASES_DIR="$INSTANCE_ROOT/releases"
mkdir -p "$RELEASES_DIR"
RELEASE_DIR="$RELEASES_DIR/$RELEASE_ID"
[[ ! -e "$RELEASE_DIR" ]] || die "release directory already exists, refusing to overwrite: $RELEASE_DIR"

CURRENT_LINK="$INSTANCE_ROOT/current"
PREVIOUS_RELEASE=""
if [[ -L "$CURRENT_LINK" ]]; then
    PREVIOUS_RELEASE="$(readlink -f "$CURRENT_LINK")"
fi

# Once the candidate release directory exists but hasn't been switched in
# yet, an interrupted deploy should not leave a half-extracted directory
# behind for the next run to trip over.
CANDIDATE_CREATED=0
cleanup_on_failure() {
    local status=$?
    if (( status != 0 )) && (( CANDIDATE_CREATED )) && [[ -d "$RELEASE_DIR" ]]; then
        log "deploy failed before switching current — removing incomplete release dir $RELEASE_DIR"
        rm -rf "$RELEASE_DIR"
    fi
    exit "$status"
}
trap cleanup_on_failure EXIT

log "extracting $ARTIFACT into $RELEASE_DIR"
mkdir -p "$RELEASE_DIR"
CANDIDATE_CREATED=1
tar -xzf "$ARTIFACT" -C "$RELEASE_DIR"
require_file "$RELEASE_DIR/artisan" "extracted release is missing artisan — bad artifact"

log "linking instance-owned shared paths into the candidate release"
rm -rf "$RELEASE_DIR/storage"
ln -s "$INSTANCE_ROOT/shared/storage" "$RELEASE_DIR/storage"
ln -sf "$INSTANCE_ROOT/shared/.env" "$RELEASE_DIR/.env"

ENV_FILE="$INSTANCE_ROOT/shared/.env"
DB_CONNECTION="$(env_get "$ENV_FILE" DB_CONNECTION)"
DB_DATABASE="$(env_get "$ENV_FILE" DB_DATABASE)"
APP_URL="$(env_get "$ENV_FILE" APP_URL)"

php_artisan() { (cd "$RELEASE_DIR" && php artisan "$@"); }

log "resolving public/storage symlink"
php_artisan storage:link >/dev/null

BACKUP_DIR="$INSTANCE_ROOT/shared/backups"
mkdir -p "$BACKUP_DIR"
BACKUP_STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

if [[ "$DB_CONNECTION" == "sqlite" ]]; then
    if [[ -n "$DB_DATABASE" && -f "$DB_DATABASE" ]]; then
        BACKUP_FILE="$BACKUP_DIR/pre-deploy-${BACKUP_STAMP}-${RELEASE_ID}.sqlite"
        log "backing up SQLite database to $BACKUP_FILE"
        sqlite_backup "$DB_DATABASE" "$BACKUP_FILE"
    else
        log "DB_CONNECTION=sqlite but DB_DATABASE ($DB_DATABASE) does not exist yet — first deploy, nothing to back up"
    fi
elif [[ "$DB_CONNECTION" == "mysql" ]]; then
    if command -v mysqldump >/dev/null 2>&1; then
        BACKUP_FILE="$BACKUP_DIR/pre-deploy-${BACKUP_STAMP}-${RELEASE_ID}.sql.gz"
        log "backing up MySQL database ($DB_DATABASE) to $BACKUP_FILE"
        DB_HOST="$(env_get "$ENV_FILE" DB_HOST)"; DB_PORT="$(env_get "$ENV_FILE" DB_PORT)"
        DB_USERNAME="$(env_get "$ENV_FILE" DB_USERNAME)"; DB_PASSWORD="$(env_get "$ENV_FILE" DB_PASSWORD)"
        MYSQL_PWD="$DB_PASSWORD" mysqldump --single-transaction --quick \
            -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" "$DB_DATABASE" \
            | gzip > "$BACKUP_FILE"
        [[ -s "$BACKUP_FILE" ]] || die "mysqldump produced an empty backup: $BACKUP_FILE"
    else
        die "DB_CONNECTION=mysql but mysqldump is not installed — refusing to migrate without a pre-deploy backup"
    fi
else
    die "unsupported DB_CONNECTION for automated backup: '$DB_CONNECTION'"
fi

log "running database migrations"
php_artisan migrate --force

log "warming caches"
php_artisan config:cache >/dev/null
php_artisan route:cache >/dev/null
php_artisan view:cache >/dev/null

log "pre-switch sanity check"
php_artisan about --only=environment,drivers >/dev/null \
    || die "candidate release failed a basic boot check (php artisan about) — not switching current"

log "switching current -> $RELEASE_DIR"
TMP_LINK="$INSTANCE_ROOT/.current.tmp.$$"
ln -s "$RELEASE_DIR" "$TMP_LINK"
mv -T "$TMP_LINK" "$CURRENT_LINK"

if [[ -n "${FPM_RELOAD_CMD:-}" ]]; then
    log "reloading PHP-FPM: $FPM_RELOAD_CMD"
    if ! eval "$FPM_RELOAD_CMD"; then
        log "WARNING: FPM_RELOAD_CMD failed; the new release is live regardless (OPcache will pick it up on next compile, may just hold stale bytecode in memory until FPM restarts)"
    fi
else
    log "FPM_RELOAD_CMD not set — skipping FPM reload (harmless: each release has a distinct path, so OPcache serves the new files without one; only memory-growth hygiene is deferred)"
fi

HEALTH_OK=0
if [[ -n "$APP_URL" ]] && command -v curl >/dev/null 2>&1; then
    log "health-checking ${APP_URL%/}/up (timeout ${HEALTH_TIMEOUT}s)"
    DEADLINE=$((SECONDS + HEALTH_TIMEOUT))
    while (( SECONDS < DEADLINE )); do
        if curl -fsS -o /dev/null --max-time 5 "${APP_URL%/}/up"; then
            HEALTH_OK=1
            break
        fi
        sleep 1
    done
else
    log "no APP_URL in .env or curl unavailable — falling back to a local artisan boot check"
    if php_artisan about --only=environment >/dev/null; then
        HEALTH_OK=1
    fi
fi

if (( ! HEALTH_OK )); then
    log "HEALTH CHECK FAILED"
    if [[ -n "$PREVIOUS_RELEASE" ]]; then
        log "reverting current -> $PREVIOUS_RELEASE (database was migrated and is NOT being reverted)"
        TMP_LINK="$INSTANCE_ROOT/.current.tmp.$$"
        ln -s "$PREVIOUS_RELEASE" "$TMP_LINK"
        mv -T "$TMP_LINK" "$CURRENT_LINK"
        log "current now points back at $PREVIOUS_RELEASE. Failed candidate kept at $RELEASE_DIR for inspection."
    else
        log "no previous release to revert to (this looks like the first deploy). current still points at $RELEASE_DIR."
    fi
    log "RECOVERY STATE: database migrations from this deploy were NOT rolled back. If the schema change is incompatible with the reverted code, restore the pre-deploy backup at $BACKUP_DIR manually with scripts/restore-instance.sh."
    trap - EXIT
    exit 1
fi

log "health check passed"
CANDIDATE_CREATED=0
trap - EXIT

if [[ "$KEEP_RELEASES" -gt 0 ]]; then
    log "pruning old releases (keeping current + last $KEEP_RELEASES)"
    # Never touches the release `current` points at, nor $PREVIOUS_RELEASE.
    mapfile -t ALL_RELEASES < <(find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort)
    KEEP_COUNT=$((KEEP_RELEASES + 1))
    TOTAL=${#ALL_RELEASES[@]}
    if (( TOTAL > KEEP_COUNT )); then
        PRUNE_COUNT=$((TOTAL - KEEP_COUNT))
        for ((i = 0; i < PRUNE_COUNT; i++)); do
            OLD="$RELEASES_DIR/${ALL_RELEASES[$i]}"
            if [[ "$OLD" != "$RELEASE_DIR" && "$OLD" != "$PREVIOUS_RELEASE" ]]; then
                log "removing old release: $OLD"
                rm -rf "$OLD"
            fi
        done
    fi
fi

log "deploy complete: $RELEASE_ID is live at $CURRENT_LINK"
