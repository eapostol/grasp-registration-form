#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage:
  bash list_releases.sh [--limit=N] [--reverse]

Options:
  --limit=N   Show only the most recent N releases (default: all)
  --reverse   Show newest first (default: oldest first)
  --help      Show this help

Environment overrides:
  BASE_DIR, RELEASES_DIR, DEPLOY_CURRENT_LINK
USAGE
}

LIMIT=0
REVERSE=0

for arg in "$@"; do
  case "$arg" in
    --limit=*)
      LIMIT="${arg#*=}"
      if ! [[ "$LIMIT" =~ ^[0-9]+$ ]]; then
        echo "ERROR: --limit must be a non-negative integer"
        exit 1
      fi
      ;;
    --reverse)
      REVERSE=1
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      echo "ERROR: unknown argument: $arg"
      usage
      exit 1
      ;;
  esac
done

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

[[ -d "$RELEASES_DIR" ]] || {
  echo "ERROR: releases directory not found: $RELEASES_DIR"
  exit 1
}

if [[ -L "$DEPLOY_CURRENT_LINK" ]]; then
  CURRENT_TARGET="$(readlink -f "$DEPLOY_CURRENT_LINK")"
else
  CURRENT_TARGET=""
fi

TMP_RELEASES_FILE="$(mktemp)"
cleanup_tmp() {
  rm -f "$TMP_RELEASES_FILE"
}
trap cleanup_tmp EXIT

find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -name 'release_*' | sort > "$TMP_RELEASES_FILE"
RELEASES=()
while IFS= read -r rel; do
  RELEASES+=("$rel")
done < "$TMP_RELEASES_FILE"

if [[ "${#RELEASES[@]}" -eq 0 ]]; then
  echo "No releases found in: $RELEASES_DIR"
  exit 0
fi

if [[ "$REVERSE" -eq 1 ]]; then
  REVERSED_RELEASES=()
  for ((i=${#RELEASES[@]} - 1; i>=0; i--)); do
    REVERSED_RELEASES+=("${RELEASES[$i]}")
  done
  RELEASES=("${REVERSED_RELEASES[@]}")
fi

if [[ "$LIMIT" -gt 0 && "$LIMIT" -lt "${#RELEASES[@]}" ]]; then
  RELEASES=("${RELEASES[@]:0:$LIMIT}")
fi

echo "Releases directory: $RELEASES_DIR"
if [[ -n "$CURRENT_TARGET" ]]; then
  echo "deploy_current:     $CURRENT_TARGET"
else
  echo "deploy_current:     (not a symlink or missing)"
fi
echo
echo "Order: $([[ "$REVERSE" -eq 1 ]] && echo "newest -> oldest" || echo "oldest -> newest")"
echo "---------------------------------------------------------------------"

index=1
for rel in "${RELEASES[@]}"; do
  marker=" "
  if [[ -n "$CURRENT_TARGET" && "$(readlink -f "$rel")" == "$CURRENT_TARGET" ]]; then
    marker="*"
  fi
  name="$(basename "$rel")"
  mtime="$(date -r "$rel" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo '-')"
  printf "%3d. [%s] %-28s %s\n" "$index" "$marker" "$name" "$mtime"
  index=$((index + 1))
done

echo "---------------------------------------------------------------------"
echo "[*] = current deploy_current target"
