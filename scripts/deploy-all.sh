#!/usr/bin/env bash
# Deploys the same release artifact to a fixed, explicit list of instance
# roots, one at a time, stopping at the first failure. No orchestration
# infrastructure — this is a for-loop around deploy-instance.sh.
#
# Usage:
#   scripts/deploy-all.sh --artifact FILE [--checksum-file FILE] \
#       [--instances-file FILE] [instance-root ...]
#
# Instance roots come from --instances-file (one absolute path per line,
# blank lines and lines starting with # ignored) and/or trailing positional
# arguments; at least one of the two is required. Every extra flag this
# script doesn't recognize itself is not forwarded — deploy-instance.sh is
# called with exactly --instance-root/--artifact/--checksum-file per instance.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

ARTIFACT=""
CHECKSUM_FILE=""
INSTANCES_FILE=""
INSTANCE_ROOTS=()

while [[ $# -gt 0 ]]; do
    case "$1" in
        --artifact) ARTIFACT="$2"; shift 2 ;;
        --checksum-file) CHECKSUM_FILE="$2"; shift 2 ;;
        --instances-file) INSTANCES_FILE="$2"; shift 2 ;;
        -h|--help)
            grep '^#' "$0" | sed 's/^#//'
            exit 0
            ;;
        --) shift; while [[ $# -gt 0 ]]; do INSTANCE_ROOTS+=("$1"); shift; done ;;
        -*) die "unknown argument: $1" ;;
        *) INSTANCE_ROOTS+=("$1"); shift ;;
    esac
done

[[ -n "$ARTIFACT" ]] || die "--artifact is required"
require_file "$ARTIFACT" "release artifact"

if [[ -n "$INSTANCES_FILE" ]]; then
    require_file "$INSTANCES_FILE" "instances file"
    while IFS= read -r line; do
        line="${line%%#*}"
        # Trim leading/trailing whitespace without invoking an external
        # command or a shell that could re-interpret the line's content.
        line="${line#"${line%%[![:space:]]*}"}"
        line="${line%"${line##*[![:space:]]}"}"
        [[ -n "$line" ]] && INSTANCE_ROOTS+=("$line")
    done < "$INSTANCES_FILE"
fi

[[ ${#INSTANCE_ROOTS[@]} -gt 0 ]] \
    || die "no instance roots given (use --instances-file and/or positional arguments)"

SUCCEEDED=()
FAILED=""

for root in "${INSTANCE_ROOTS[@]}"; do
    log "=== deploying to $root ==="
    args=(--instance-root "$root" --artifact "$ARTIFACT")
    [[ -n "$CHECKSUM_FILE" ]] && args+=(--checksum-file "$CHECKSUM_FILE")

    if "$SCRIPT_DIR/deploy-instance.sh" "${args[@]}"; then
        SUCCEEDED+=("$root")
    else
        FAILED="$root"
        break
    fi
done

echo
log "=== deploy-all summary ==="
for root in "${SUCCEEDED[@]}"; do log "  OK    $root"; done
if [[ -n "$FAILED" ]]; then
    log "  FAIL  $FAILED"
    REMAINING=$(( ${#INSTANCE_ROOTS[@]} - ${#SUCCEEDED[@]} - 1 ))
    if (( REMAINING > 0 )); then
        log "  SKIPPED ($REMAINING remaining instance(s) not attempted)"
    fi
    exit 1
fi

log "all ${#SUCCEEDED[@]} instance(s) deployed successfully"
