#!/usr/bin/env bash
# Builds one immutable release artifact from the current Git commit.
# Runs on a build machine / developer workstation — never on a customer
# production instance. The same artifact this produces is later deployed
# unchanged (composer install / npm build already done) to every instance
# with scripts/deploy-instance.sh.
#
# Usage:
#   scripts/build-release.sh [--release-id ID] [--skip-tests] [--output-dir DIR]
#
# Requires a clean Git working tree (tracked and untracked changes both
# block the build — an untracked file could be a forgotten secret). The
# artifact's file list comes from `git archive`, so anything not committed
# can never end up inside it, including `.env`, vendor/, node_modules/, or
# any local scratch file.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
# shellcheck source=lib/common.sh
source "$SCRIPT_DIR/lib/common.sh"

OUTPUT_DIR="$REPO_ROOT/dist"
RELEASE_ID=""
SKIP_TESTS=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --release-id) RELEASE_ID="$2"; shift 2 ;;
        --skip-tests) SKIP_TESTS=1; shift ;;
        --output-dir) OUTPUT_DIR="$2"; shift 2 ;;
        -h|--help)
            grep '^#' "$0" | sed 's/^#//'
            exit 0
            ;;
        *) die "unknown argument: $1" ;;
    esac
done

require_cmd git
require_cmd composer
require_cmd npm
require_cmd tar
require_cmd php

cd "$REPO_ROOT"

[[ -z "$(git status --porcelain)" ]] \
    || die "Git working tree is not clean (tracked or untracked changes present). Commit, stash, or clean it first."

GIT_SHA="$(git rev-parse HEAD)"
GIT_SHA_SHORT="${GIT_SHA:0:12}"
BUILT_AT="$(date -u +%Y%m%dT%H%M%SZ)"

if [[ -z "$RELEASE_ID" ]]; then
    RELEASE_ID="${BUILT_AT}-${GIT_SHA_SHORT}"
fi
[[ "$RELEASE_ID" =~ ^[A-Za-z0-9._-]+$ ]] \
    || die "release id must match [A-Za-z0-9._-]+, got: $RELEASE_ID"

if (( SKIP_TESTS )); then
    log "skipping test gate (--skip-tests): building an unverified artifact"
else
    log "running the full test suite as the release gate"
    php artisan test
fi

BUILD_DIR="$(mktemp -d "${TMPDIR:-/tmp}/delivery-release.XXXXXX")"
cleanup() { rm -rf "$BUILD_DIR"; }
trap cleanup EXIT

log "exporting commit $GIT_SHA_SHORT via git archive (tracked files only)"
git archive "$GIT_SHA" | tar -x -C "$BUILD_DIR"

pushd "$BUILD_DIR" >/dev/null

log "installing production PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist

log "installing frontend dependencies and building assets"
npm ci --no-audit --no-fund
npm run build
[[ -f public/build/manifest.json ]] || die "npm run build did not produce public/build/manifest.json"

log "removing artifact-only content not needed at runtime"
rm -rf node_modules tests .github .git .gitattributes .gitignore

popd >/dev/null

mkdir -p "$OUTPUT_DIR"
ARTIFACT_NAME="delivery-${RELEASE_ID}.tar.gz"
ARTIFACT_PATH="$OUTPUT_DIR/$ARTIFACT_NAME"

log "packaging $ARTIFACT_NAME"
# Deterministic-ish tarball: fixed owner, sorted entries, no mtimes leaking
# the build machine's clock into the artifact's bytes.
tar --sort=name --owner=0 --group=0 --numeric-owner --mtime="@0" \
    -czf "$ARTIFACT_PATH" -C "$BUILD_DIR" .

CHECKSUM="$(sha256_file "$ARTIFACT_PATH")"
printf '%s  %s\n' "$CHECKSUM" "$ARTIFACT_NAME" > "$ARTIFACT_PATH.sha256"

META_PATH="$OUTPUT_DIR/delivery-${RELEASE_ID}.json"
cat > "$META_PATH" <<JSON
{
    "release_id": "$RELEASE_ID",
    "git_sha": "$GIT_SHA",
    "built_at": "$BUILT_AT",
    "artifact": "$ARTIFACT_NAME",
    "sha256": "$CHECKSUM",
    "tests_run": $([[ "$SKIP_TESTS" -eq 1 ]] && echo false || echo true)
}
JSON

log "artifact:   $ARTIFACT_PATH"
log "checksum:   $ARTIFACT_PATH.sha256 ($CHECKSUM)"
log "metadata:   $META_PATH"
log "done"
