#!/usr/bin/env bash
# Shared helpers for the deployment scripts under scripts/. Sourced, not
# executed — every script that uses this still sets its own
# `set -Eeuo pipefail` and traps. This file intentionally stays plain
# functions: no config, no side effects on source, no framework.

# --- logging -----------------------------------------------------------

log()  { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
die()  { printf '[%s] ERROR: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; exit 1; }

# --- guards --------------------------------------------------------------

# Fails unless $1 is an absolute path with no ".." segment, so a
# caller-supplied instance name/root can never escape its intended parent
# directory via path traversal.
require_safe_absolute_path() {
    local path="$1" label="${2:-path}"
    [[ "$path" == /* ]] || die "$label must be an absolute path: $path"
    case "/$path/" in
        */../*|*/./*) die "$label must not contain '.' or '..' segments: $path" ;;
    esac
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || die "required command not found: $1"
}

require_file() {
    [[ -f "$1" ]] || die "${2:-required file missing}: $1"
}

require_dir() {
    [[ -d "$1" ]] || die "${2:-required directory missing}: $1"
}

# --- sqlite ----------------------------------------------------------------

# Reads a KEY=VALUE (optionally quoted) line out of a .env-shaped file
# without sourcing it — sourcing an operator-provided .env would execute
# arbitrary shell if a value ever contained backticks/$(...).
env_get() {
    local file="$1" key="$2" line value
    line="$(grep -E "^${key}=" "$file" | tail -n1 || true)"
    [[ -n "$line" ]] || { printf ''; return 0; }
    value="${line#*=}"
    value="${value%\"}"; value="${value#\"}"
    value="${value%\'}"; value="${value#\'}"
    printf '%s' "$value"
}

# SQLite-safe snapshot: uses the sqlite3 .backup command (consistent even
# with an active WAL and concurrent readers/writers), never a raw file copy.
sqlite_backup() {
    local source_db="$1" dest_file="$2"
    require_cmd sqlite3
    require_file "$source_db" "source SQLite database"
    # dest_file is embedded in a single-quoted sqlite3 dot-command string
    # below, not shell-interpolated SQL — a literal single quote in the path
    # would break that syntax, so refuse it outright rather than trust every
    # caller to only ever pass script-generated paths.
    [[ "$dest_file" != *"'"* ]] || die "sqlite_backup: destination path must not contain a single quote: $dest_file"
    sqlite3 "$source_db" ".backup '${dest_file}'"
    [[ -s "$dest_file" ]] || die "SQLite backup produced an empty file: $dest_file"
    sqlite3 "$dest_file" "PRAGMA integrity_check;" | grep -qx ok \
        || die "SQLite backup failed integrity_check: $dest_file"
}

sha256_file() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    else
        shasum -a 256 "$1" | awk '{print $1}'
    fi
}
