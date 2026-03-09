#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage:
  bash rollback_to_previous_release.sh --dry-run
  bash rollback_to_previous_release.sh --yes

Options:
  --dry-run    Show target rollback release; no symlinks changed.
  --yes        Required for live rollback.
  --help       Show this help.

Environment overrides:
  BASE_DIR, RELEASES_DIR, DEPLOY_CURRENT_LINK, PROD_DIR, LOG_DIR
USAGE
}

DRY_RUN=0
CONFIRM_YES=0

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --yes) CONFIRM_YES=1 ;;
    --help|-h) usage; exit 0 ;;
    *)
      echo "ERROR: unknown argument: $arg"
      usage
      exit 1
      ;;
  esac
done

if [[ "$DRY_RUN" -eq 0 && "$CONFIRM_YES" -ne 1 ]]; then
  echo "ERROR: live rollback requires --yes"
  usage
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -z "${BASE_DIR:-}" ]]; then
  if [[ -d "$SCRIPT_DIR/releases" ]]; then
    BASE_DIR="$SCRIPT_DIR"
  elif [[ -d "$SCRIPT_DIR/../releases" ]]; then
    BASE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
  else
    BASE_DIR="$(pwd)"
  fi
fi
RELEASES_DIR="${RELEASES_DIR:-$BASE_DIR/releases}"
DEPLOY_CURRENT_LINK="${DEPLOY_CURRENT_LINK:-$BASE_DIR/deploy_current}"
PROD_DIR="${PROD_DIR:-$BASE_DIR/prod}"
LOG_DIR="${LOG_DIR:-$BASE_DIR/deploy-logs}"
LOCK_DIR="${BASE_DIR}/.rollback_to_previous_release.lock"
timestamp="$(date +%Y%m%d_%H%M%S)"

mkdir -p "$LOG_DIR"
LOG_FILE="$LOG_DIR/rollback_${timestamp}.log"

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
  echo "ERROR: rollback already in progress (lock: $LOCK_DIR)"
  exit 1
fi

echo "=== Rollback deploy_current -> previous release ==="
echo "time:       $(date -Is)"
echo "base:       $BASE_DIR"
echo "releases:   $RELEASES_DIR"
echo "current:    $DEPLOY_CURRENT_LINK"
echo "prod:       $PROD_DIR"
echo "dry-run:    $DRY_RUN"
echo "log:        $LOG_FILE"

[[ -d "$RELEASES_DIR" ]] || { echo "ERROR: missing releases dir: $RELEASES_DIR"; exit 1; }
[[ -L "$DEPLOY_CURRENT_LINK" ]] || { echo "ERROR: deploy_current is not a symlink: $DEPLOY_CURRENT_LINK"; exit 1; }

current_target="$(readlink -f "$DEPLOY_CURRENT_LINK")"
if [[ -z "$current_target" || ! -d "$current_target" ]]; then
  echo "ERROR: current deploy_current target is invalid: $current_target"
  exit 1
fi

mapfile -t releases < <(find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -name 'release_*' | sort)
if [[ "${#releases[@]}" -lt 2 ]]; then
  echo "ERROR: need at least 2 releases to rollback."
  exit 1
fi

previous_target=""
for i in "${!releases[@]}"; do
  rel="${releases[$i]}"
  if [[ "$(readlink -f "$rel")" == "$current_target" ]]; then
    if [[ "$i" -eq 0 ]]; then
      echo "ERROR: current release is already the oldest; no previous release available."
      exit 1
    fi
    previous_target="${releases[$((i - 1))]}"
    break
  fi
done

if [[ -z "$previous_target" ]]; then
  echo "ERROR: current target not found inside releases dir."
  echo "current target: $current_target"
  exit 1
fi

echo "Current release:  $current_target"
echo "Rollback target:  $previous_target"

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "Dry run complete. No changes applied."
  exit 0
fi

tmp_current="${DEPLOY_CURRENT_LINK}.tmp"
ln -sfn "$previous_target" "$tmp_current"
mv -Tf "$tmp_current" "$DEPLOY_CURRENT_LINK"

if [[ -L "$PROD_DIR" ]]; then
  tmp_prod="${PROD_DIR}.tmp"
  ln -sfn "$DEPLOY_CURRENT_LINK" "$tmp_prod"
  mv -Tf "$tmp_prod" "$PROD_DIR"
fi

echo "Rollback complete."
echo "deploy_current now points to: $(readlink -f "$DEPLOY_CURRENT_LINK")"
