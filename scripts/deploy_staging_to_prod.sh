#!/usr/bin/env bash
set -euo pipefail

# Syncs staging -> prod safely on WHC/cPanel-style layouts.
# Adds:
# - --yes confirmation for live deploys
# - --strategy=symlink for near-zero-downtime cutover using deploy_current

usage() {
  cat <<'USAGE'
Usage:
  bash deploy_staging_to_prod.sh --dry-run [--strategy=rsync|symlink]
  bash deploy_staging_to_prod.sh --yes [--strategy=rsync|symlink]

Options:
  --dry-run            Show what would change; no writes.
  --yes                Required for live deploys.
  --strategy=VALUE     Deploy strategy: rsync (default) or symlink.
  --help               Show this help.

Environment overrides:
  BASE_DIR, STAGING_DIR, PROD_DIR, BACKUP_DIR, LOG_DIR, RELEASES_DIR, DEPLOY_CURRENT_LINK
USAGE
}

DRY_RUN=0
CONFIRM_YES=0
STRATEGY="rsync"

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --yes) CONFIRM_YES=1 ;;
    --strategy=*) STRATEGY="${arg#*=}" ;;
    --help|-h) usage; exit 0 ;;
    *)
      echo "ERROR: unknown argument: $arg"
      usage
      exit 1
      ;;
  esac
done

if [[ "$DRY_RUN" -eq 0 && "$CONFIRM_YES" -ne 1 ]]; then
  echo "ERROR: live deploy requires --yes"
  usage
  exit 1
fi

if [[ "$STRATEGY" != "rsync" && "$STRATEGY" != "symlink" ]]; then
  echo "ERROR: invalid strategy '$STRATEGY' (expected rsync or symlink)"
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "${BASE_DIR:-}" ]]; then
  if [[ -d "$SCRIPT_DIR/staging" && ( -d "$SCRIPT_DIR/prod" || -L "$SCRIPT_DIR/prod" ) ]]; then
    BASE_DIR="$SCRIPT_DIR"
  elif [[ -d "$SCRIPT_DIR/../staging" && ( -d "$SCRIPT_DIR/../prod" || -L "$SCRIPT_DIR/../prod" ) ]]; then
    BASE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
  else
    BASE_DIR="$(pwd)"
  fi
fi
STAGING_DIR="${STAGING_DIR:-$BASE_DIR/staging}"
PROD_DIR="${PROD_DIR:-$BASE_DIR/prod}"
BACKUP_DIR="${BACKUP_DIR:-$BASE_DIR/deploy-backups}"
LOG_DIR="${LOG_DIR:-$BASE_DIR/deploy-logs}"
RELEASES_DIR="${RELEASES_DIR:-$BASE_DIR/releases}"
DEPLOY_CURRENT_LINK="${DEPLOY_CURRENT_LINK:-$BASE_DIR/deploy_current}"
LOCK_DIR="${BASE_DIR}/.deploy_staging_to_prod.lock"

timestamp="$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR" "$LOG_DIR"
LOG_FILE="$LOG_DIR/deploy_${timestamp}.log"

# WHC shells may not support process substitution (/dev/fd/*).
if [[ -e /dev/fd/1 ]] && exec > >(tee -a "$LOG_FILE") 2>&1; then
  :
else
  exec >>"$LOG_FILE" 2>&1
  echo "WARN: tee mirroring unavailable; logging to file only: $LOG_FILE"
fi

cleanup() {
  rm -rf "$LOCK_DIR"
}
trap cleanup EXIT

if ! mkdir "$LOCK_DIR" 2>/dev/null; then
  echo "ERROR: deployment already in progress (lock: $LOCK_DIR)"
  exit 1
fi

echo "=== Deploy staging -> prod ==="
echo "time:       $(date -Is)"
echo "base:       $BASE_DIR"
echo "staging:    $STAGING_DIR"
echo "prod:       $PROD_DIR"
echo "strategy:   $STRATEGY"
echo "dry-run:    $DRY_RUN"
echo "backup:     $BACKUP_DIR"
echo "log:        $LOG_FILE"

command -v rsync >/dev/null 2>&1 || { echo "ERROR: rsync is required."; exit 1; }

[[ -d "$STAGING_DIR" ]] || { echo "ERROR: missing staging dir: $STAGING_DIR"; exit 1; }
if [[ ! -d "$PROD_DIR" && ! -L "$PROD_DIR" ]]; then
  echo "ERROR: missing prod path: $PROD_DIR"
  exit 1
fi

if [[ "$STAGING_DIR" == "/" || "$PROD_DIR" == "/" ]]; then
  echo "ERROR: refusing to operate on '/'."
  exit 1
fi

RSYNC_EXCLUDES=(
  "--exclude=.htaccess"
  "--exclude=.htpasswd"
  "--exclude=.htpasswds"
  "--exclude=.git/"
  "--exclude=.github/"
  "--exclude=deploy-backups/"
  "--exclude=deploy-logs/"
  "--exclude=releases/"
)

RSYNC_FLAGS=(
  "-a"
  "--delete"
  "--human-readable"
  "--itemize-changes"
  "--stats"
)
if [[ "$DRY_RUN" -eq 1 ]]; then
  RSYNC_FLAGS+=("--dry-run")
fi

assert_no_auth_in_htaccess() {
  local file="$1"
  if [[ -f "$file" ]] && grep -Eiq '^\s*(AuthType|AuthName|AuthUserFile|Require\s+valid-user)\b' "$file"; then
    echo "ERROR: auth directives detected in $file"
    exit 1
  fi
}

backup_current_prod() {
  local src
  src="$(readlink -f "$PROD_DIR" 2>/dev/null || true)"
  if [[ -z "$src" || ! -d "$src" ]]; then
    src="$PROD_DIR"
  fi
  local backup_file="$BACKUP_DIR/prod_backup_${timestamp}.tar.gz"
  echo "Creating prod backup: $backup_file"
  tar -czf "$backup_file" -C "$src" .
}

if [[ "$DRY_RUN" -eq 0 ]]; then
  backup_current_prod
fi

if [[ "$STRATEGY" == "rsync" ]]; then
  echo "Running rsync strategy..."
  rsync "${RSYNC_FLAGS[@]}" "${RSYNC_EXCLUDES[@]}" "$STAGING_DIR/" "$PROD_DIR/"
  assert_no_auth_in_htaccess "$PROD_DIR/.htaccess"
  echo "Deploy complete (rsync strategy)."
  exit 0
fi

# symlink strategy
mkdir -p "$RELEASES_DIR"
NEW_RELEASE_DIR="$RELEASES_DIR/release_${timestamp}"
echo "Preparing release dir: $NEW_RELEASE_DIR"

if [[ "$DRY_RUN" -eq 0 ]]; then
  mkdir -p "$NEW_RELEASE_DIR"
fi

rsync "${RSYNC_FLAGS[@]}" "${RSYNC_EXCLUDES[@]}" "$STAGING_DIR/" "$NEW_RELEASE_DIR/"
assert_no_auth_in_htaccess "$NEW_RELEASE_DIR/.htaccess"

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "Dry run complete (symlink strategy). No symlinks changed."
  exit 0
fi

tmp_current="${DEPLOY_CURRENT_LINK}.tmp"
ln -sfn "$NEW_RELEASE_DIR" "$tmp_current"
mv -Tf "$tmp_current" "$DEPLOY_CURRENT_LINK"

if [[ -L "$PROD_DIR" ]]; then
  tmp_prod="${PROD_DIR}.tmp"
  ln -sfn "$DEPLOY_CURRENT_LINK" "$tmp_prod"
  mv -Tf "$tmp_prod" "$PROD_DIR"
else
  # First-time conversion of physical prod dir -> symlink.
  legacy_prod="${BASE_DIR}/prod_legacy_${timestamp}"
  mv "$PROD_DIR" "$legacy_prod"
  ln -s "$DEPLOY_CURRENT_LINK" "$PROD_DIR"
  echo "Moved old prod dir to: $legacy_prod"
fi

assert_no_auth_in_htaccess "$DEPLOY_CURRENT_LINK/.htaccess"
echo "Deploy complete (symlink strategy)."
echo "Active release: $NEW_RELEASE_DIR"
