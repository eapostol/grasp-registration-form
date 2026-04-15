#!/usr/bin/env bash
set -euo pipefail

# -----------------------------
# GRASP deploy (WHC)
# - Pulls a selected Git branch into ~/deploy/grasp-src
# - Deploys to staging OR to prod (release/symlink model)
# - Makes timestamped tar.gz backups before changing target
# -----------------------------

REPO_SSH_URL="${REPO_SSH_URL:-git@github.com:eapostol/grasp-registration-form.git}"

DEFAULT_STAGING_BRANCH="${DEFAULT_STAGING_BRANCH:-develop}"
DEFAULT_PROD_BRANCH="${DEFAULT_PROD_BRANCH:-main}"

SRC_DIR="${SRC_DIR:-$HOME/deploy/grasp-src}"
EXCLUDES_FILE="${EXCLUDES_FILE:-$HOME/deploy/grasp-rsync-excludes.txt}"

STAGING_DIR="${STAGING_DIR:-$HOME/public_html/staging}"
PROD_DIR="${PROD_DIR:-$HOME/public_html/prod}"
RELEASES_DIR="${RELEASES_DIR:-$HOME/public_html/releases}"
DEPLOY_CURRENT_LINK="${DEPLOY_CURRENT_LINK:-$HOME/public_html/deploy_current}"

BACKUP_DIR="${BACKUP_DIR:-$HOME/deploy/backups}"
LOCK_DIR="${LOCK_DIR:-$HOME/deploy/.deploy.lock}"

usage() {
  cat <<USAGE
Usage:
  grasp_deploy.sh staging [branch] [--dry-run]
  grasp_deploy.sh prod [branch] [--dry-run]
  grasp_deploy.sh rollback <target> <backup-tar.gz>
  grasp_deploy.sh status

Defaults:
  staging branch: $DEFAULT_STAGING_BRANCH
  prod branch:    $DEFAULT_PROD_BRANCH

Examples:
  bash ~/deploy/bin/grasp_deploy.sh staging
  bash ~/deploy/bin/grasp_deploy.sh staging develop
  bash ~/deploy/bin/grasp_deploy.sh prod main
  bash ~/deploy/bin/grasp_deploy.sh prod main --dry-run
USAGE
}

timestamp() { date +"%Y%m%d_%H%M%S"; }

acquire_lock() {
  if mkdir "$LOCK_DIR" 2>/dev/null; then
    trap 'rmdir "$LOCK_DIR" >/dev/null 2>&1 || true' EXIT
  else
    echo "ERROR: Deploy lock exists ($LOCK_DIR). Another deploy may be running." >&2
    exit 1
  fi
}

parse_branch_and_dry_run() {
  local default_branch="$1"
  shift || true

  DEPLOY_BRANCH="$default_branch"
  DRY_RUN="false"

  if [[ "$#" -gt 2 ]]; then
    echo "ERROR: Too many arguments." >&2
    usage
    exit 1
  fi

  if [[ "$#" -ge 1 ]]; then
    if [[ "$1" == "--dry-run" ]]; then
      DRY_RUN="true"
    else
      DEPLOY_BRANCH="$1"
    fi
  fi

  if [[ "$#" -eq 2 ]]; then
    if [[ "$1" == "--dry-run" || "$2" != "--dry-run" ]]; then
      echo "ERROR: Unexpected argument order. Use: [branch] [--dry-run]" >&2
      usage
      exit 1
    fi
    DRY_RUN="true"
  fi
}

ensure_repo_synced() {
  local branch="$1"

  mkdir -p "$(dirname "$SRC_DIR")"
  if [[ ! -d "$SRC_DIR/.git" ]]; then
    echo "Cloning repo into $SRC_DIR..."
    git clone "$REPO_SSH_URL" "$SRC_DIR"
  fi

  echo "Fetching latest origin/$branch..."
  git -C "$SRC_DIR" fetch origin "$branch"
  git -C "$SRC_DIR" checkout -B "$branch" "origin/$branch"
  git -C "$SRC_DIR" reset --hard "origin/$branch"
  git -C "$SRC_DIR" clean -fdx

  echo "Checked out branch: $branch"
  git -C "$SRC_DIR" --no-pager log -1 --oneline
}

backup_target() {
  local target="$1"
  local name="$2"

  mkdir -p "$BACKUP_DIR"
  local file="$BACKUP_DIR/${name}_$(timestamp).tgz"

  echo "Backing up $target -> $file"
  if [[ -d "$target" ]]; then
    tar -czf "$file" -C "$target" .
  else
    tar -czf "$file" --files-from /dev/null
  fi

  echo "$file"
}

rsync_flags_for() {
  RSYNC_FLAGS=(-rlptDz --delete --force --no-owner --no-group)

  if [[ -f "$EXCLUDES_FILE" ]]; then
    RSYNC_FLAGS+=(--exclude-from="$EXCLUDES_FILE")
  fi

  if [[ "$DRY_RUN" == "true" ]]; then
    RSYNC_FLAGS+=(--dry-run)
  fi
}

deploy_staging_from_branch() {
  local branch="$1"

  DEPLOY_BRANCH="$branch"
  ensure_repo_synced "$DEPLOY_BRANCH"
  backup_target "$STAGING_DIR" "staging" >/dev/null

  mkdir -p "$STAGING_DIR"
  rsync_flags_for

  echo "Rsync -> $STAGING_DIR (branch=$DEPLOY_BRANCH dry-run=$DRY_RUN)"
  rsync "${RSYNC_FLAGS[@]}" "$SRC_DIR"/ "$STAGING_DIR"/
}

sync_prod_symlink() {
  local new_release="$1"

  local tmp_current="${DEPLOY_CURRENT_LINK}.tmp"
  ln -sfn "$new_release" "$tmp_current"
  mv -Tf "$tmp_current" "$DEPLOY_CURRENT_LINK"

  if [[ -L "$PROD_DIR" ]]; then
    local tmp_prod="${PROD_DIR}.tmp"
    ln -sfn "$DEPLOY_CURRENT_LINK" "$tmp_prod"
    mv -Tf "$tmp_prod" "$PROD_DIR"
  elif [[ -d "$PROD_DIR" ]]; then
    local legacy_prod="${PROD_DIR}_legacy_$(timestamp)"
    mv "$PROD_DIR" "$legacy_prod"
    ln -s "$DEPLOY_CURRENT_LINK" "$PROD_DIR"
    echo "Moved old prod dir to: $legacy_prod"
  else
    ln -s "$DEPLOY_CURRENT_LINK" "$PROD_DIR"
  fi
}

deploy_prod_from_branch() {
  local branch="$1"

  DEPLOY_BRANCH="$branch"
  ensure_repo_synced "$DEPLOY_BRANCH"

  mkdir -p "$RELEASES_DIR"

  local active_prod
  active_prod="$(readlink -f "$PROD_DIR" 2>/dev/null || true)"
  if [[ -z "$active_prod" || ! -d "$active_prod" ]]; then
    active_prod="$PROD_DIR"
  fi

  backup_target "$active_prod" "prod" >/dev/null

  rsync_flags_for

  if [[ "$DRY_RUN" == "true" ]]; then
    mkdir -p "$active_prod"
    echo "Dry-run rsync -> active prod target $active_prod (branch=$DEPLOY_BRANCH)"
    rsync "${RSYNC_FLAGS[@]}" "$SRC_DIR"/ "$active_prod"/
    echo "Dry run complete. No release/symlink changes were applied."
    return
  fi

  local new_release="$RELEASES_DIR/release_$(timestamp)"
  mkdir -p "$new_release"

  echo "Rsync -> $new_release (branch=$DEPLOY_BRANCH)"
  rsync "${RSYNC_FLAGS[@]}" "$SRC_DIR"/ "$new_release"/

  sync_prod_symlink "$new_release"

  echo "Production deploy complete. Active release: $new_release"
}

rollback() {
  local target="$1"
  local tarball="$2"

  local target_dir=""
  case "$target" in
    staging) target_dir="$STAGING_DIR" ;;
    prod) target_dir="$(readlink -f "$PROD_DIR" 2>/dev/null || echo "$PROD_DIR")" ;;
    *) echo "ERROR: rollback target must be staging|prod"; exit 1 ;;
  esac

  if [[ ! -f "$tarball" ]]; then
    echo "ERROR: backup file not found: $tarball" >&2
    exit 1
  fi

  echo "ROLLBACK: Restoring $target_dir from $tarball"
  mkdir -p "$target_dir"
  rm -rf "${target_dir:?}/"*
  tar -xzf "$tarball" -C "$target_dir"
  echo "Rollback complete."
}

status() {
  echo "Repo: $REPO_SSH_URL"
  if [[ -d "$SRC_DIR/.git" ]]; then
    git -C "$SRC_DIR" --no-pager log -1 --oneline
  else
    echo "SRC not cloned yet: $SRC_DIR"
  fi
  echo "Staging:        $STAGING_DIR"
  echo "Prod:           $PROD_DIR"
  echo "Releases:       $RELEASES_DIR"
  echo "deploy_current: $DEPLOY_CURRENT_LINK"
}

main() {
  local cmd="${1:-}"
  shift || true

  case "$cmd" in
    staging)
      acquire_lock
      parse_branch_and_dry_run "$DEFAULT_STAGING_BRANCH" "$@"
      deploy_staging_from_branch "$DEPLOY_BRANCH"
      ;;
    prod)
      acquire_lock
      parse_branch_and_dry_run "$DEFAULT_PROD_BRANCH" "$@"
      deploy_prod_from_branch "$DEPLOY_BRANCH"
      ;;
    rollback)
      acquire_lock
      rollback "${1:-}" "${2:-}"
      ;;
    status)
      status
      ;;
    ""|-h|--help|help)
      usage
      ;;
    *)
      echo "ERROR: Unknown command: $cmd" >&2
      usage
      exit 1
      ;;
  esac
}

main "$@"
