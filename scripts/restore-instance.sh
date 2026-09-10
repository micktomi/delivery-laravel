#!/usr/bin/env bash
# Emergency restore of an instance's SQLite database from an explicit
# backup file. This is a manual, operator-invoked, last-resort tool — it
# is never called automatically by deploy-instance.sh or anything else.
#
# Usage:
#   scripts/restore-instance.sh --instance-root PATH --backup-file FILE --yes
#
# What this does NOT do: merge/replay anything, restore uploads, restore a
# MySQL instance, or stop/start any service for you. Read the warning below.
#
# ############################################################################
# # WARNING: restoring overwrites the live database with the backup's       #
# # contents. Every order, catalogue edit, and setting change written AFTER #
# # the backup was taken is permanently discarded by this operation.        #
# ############################################################################

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

INSTANCE_ROOT=""
BACKUP_FILE=""
CONFIRMED=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --instance-root) INSTANCE_ROOT="$2"; shift 2 ;;
        --backup-file) BACKUP_FILE="$2"; shift 2 ;;
        --yes) CONFIRMED=1; shift ;;
        -h|--help)
            grep '^#' "$0" | sed 's/^#//'
            exit 0
            ;;
        *) die "unknown argument: $1" ;;
    esac
done

[[ -n "$INSTANCE_ROOT" ]] || die "--instance-root is required"
[[ -n "$BACKUP_FILE" ]] || die "--backup-file is required (no 'latest' auto-detection — name it explicitly)"
require_safe_absolute_path "$INSTANCE_ROOT" "--instance-root"
require_cmd sqlite3

require_dir "$INSTANCE_ROOT" "instance root"
require_file "$INSTANCE_ROOT/shared/.env" "instance is missing shared/.env — not a provisioned instance"
require_file "$BACKUP_FILE" "backup file"
[[ -s "$BACKUP_FILE" ]] || die "backup file is empty: $BACKUP_FILE"

ENV_FILE="$INSTANCE_ROOT/shared/.env"
DB_CONNECTION="$(env_get "$ENV_FILE" DB_CONNECTION)"
[[ "$DB_CONNECTION" == "sqlite" ]] \
    || die "this script only restores SQLite instances (DB_CONNECTION here is '$DB_CONNECTION')"

TARGET_DB="$(env_get "$ENV_FILE" DB_DATABASE)"
[[ -n "$TARGET_DB" ]] || die "DB_DATABASE is empty in $ENV_FILE"
require_file "$TARGET_DB" "instance database"

log "verifying backup file integrity: $BACKUP_FILE"
sqlite3 "$BACKUP_FILE" "PRAGMA integrity_check;" | grep -qx ok \
    || die "backup file failed PRAGMA integrity_check — refusing to restore from it: $BACKUP_FILE"

echo >&2
echo "!! DESTRUCTIVE OPERATION !!" >&2
echo "Instance:    $INSTANCE_ROOT" >&2
echo "Target DB:   $TARGET_DB" >&2
echo "Restoring:   $BACKUP_FILE" >&2
echo "Every write to the target DB after the backup was taken will be lost." >&2
echo >&2

if (( ! CONFIRMED )); then
    die "refusing to proceed without --yes (this is intentionally not an interactive prompt — pass --yes only once you have confirmed the instance/backup above)"
fi

# Best-effort concurrency guard: SQLite has no session-kill primitive we can
# reach from here, so this only detects an in-progress write at this exact
# moment — it does not prevent one from starting immediately after. Stopping
# PHP-FPM for this instance first is the operator's job; we just check and warn.
log "checking whether the live database is busy right now"
if ! sqlite3 -cmd ".timeout 2000" "$TARGET_DB" "BEGIN IMMEDIATE; ROLLBACK;" 2>/dev/null; then
    log "WARNING: could not obtain an immediate write lock on $TARGET_DB within 2s — something is actively writing to it right now. Stop the application (e.g. PHP-FPM for this instance) before continuing, or expect the restore to race with live traffic."
fi

BACKUP_DIR="$INSTANCE_ROOT/shared/backups"
mkdir -p "$BACKUP_DIR"
SAFETY_STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
SAFETY_COPY="$BACKUP_DIR/pre-restore-${SAFETY_STAMP}.sqlite"

log "taking a safety copy of the current live database before touching it: $SAFETY_COPY"
sqlite_backup "$TARGET_DB" "$SAFETY_COPY"

ORIG_OWNER=""
ORIG_MODE=""
if stat -c '%U:%G' "$TARGET_DB" >/dev/null 2>&1; then
    ORIG_OWNER="$(stat -c '%U:%G' "$TARGET_DB")"
    ORIG_MODE="$(stat -c '%a' "$TARGET_DB")"
fi

TARGET_DIR="$(dirname "$TARGET_DB")"
TMP_RESTORE="$TARGET_DIR/.restore-tmp.$$"
cp "$BACKUP_FILE" "$TMP_RESTORE"

log "atomically replacing $TARGET_DB"
mv -f "$TMP_RESTORE" "$TARGET_DB"

# The old file's WAL/SHM sidecars belong to the database we just replaced;
# left in place they would be replayed against the restored file on next
# open and corrupt it. The restored file itself came from `.backup`, which
# never leaves WAL/SHM behind, so it is safe to open cleanly.
rm -f "${TARGET_DB}-wal" "${TARGET_DB}-shm"

if [[ -n "$ORIG_OWNER" ]]; then
    chown "$ORIG_OWNER" "$TARGET_DB" 2>/dev/null || log "WARNING: could not chown $TARGET_DB back to $ORIG_OWNER (not root?) — check ownership manually"
    chmod "$ORIG_MODE" "$TARGET_DB" 2>/dev/null || true
fi

log "verifying restored database integrity"
sqlite3 "$TARGET_DB" "PRAGMA integrity_check;" | grep -qx ok \
    || die "restored database failed PRAGMA integrity_check — investigate immediately. Pre-restore safety copy is at $SAFETY_COPY"

log "restore complete."
log "pre-restore safety copy of the database as it was before this operation: $SAFETY_COPY"
log "if anything looks wrong, restore that safety copy the same way to undo this."
