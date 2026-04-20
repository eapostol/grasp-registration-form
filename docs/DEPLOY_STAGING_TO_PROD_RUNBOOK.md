# Staging -> Prod Deploy Runbook

This runbook explains how to use these scripts safely:
- `scripts/grasp_deploy.sh`
- `scripts/deploy_staging_to_prod.sh`
- `scripts/list_releases.sh`
- `scripts/rollback_to_previous_release.sh`

## CI deploy flow (implemented)

- Merge to `develop` triggers GitHub Actions staging deploy:
  - workflow: `.github/workflows/deploy-staging.yml`
  - remote command: `bash ~/deploy/bin/grasp_deploy.sh staging develop`
- Merge to `main` triggers GitHub Actions production deploy:
  - workflow: `.github/workflows/deploy-prod.yml`
  - remote command: `bash ~/deploy/bin/grasp_deploy.sh prod main`

Production is standardized to the `prod + releases + deploy_current` symlink model.

### Composer dependency restoration

The deploy flow now syncs the versioned `scripts/grasp_deploy.sh` to the WHC host before each deploy and runs Composer inside the deployed `api/` directory:

```bash
cd api
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress
```

This is required because `api/vendor/` is not committed to Git, but PDF generation depends on Composer-installed TCPDF classes at runtime.

## Keep server deploy script in sync

`grasp_deploy.sh` is now versioned in this repo at `scripts/grasp_deploy.sh`.
GitHub Actions now copies it automatically before each deploy, but you can also sync it manually if needed:

```bash
scp scripts/grasp_deploy.sh tscu0290@<whc-host>:/home/tscu0290/deploy/bin/grasp_deploy.sh
ssh tscu0290@<whc-host> 'chmod +x /home/tscu0290/deploy/bin/grasp_deploy.sh'
```

## Manual usage of `grasp_deploy.sh`

Run on server over SSH:

```bash
# Deploy develop -> staging
bash /home/tscu0290/deploy/bin/grasp_deploy.sh staging develop

# Deploy main -> prod (creates new release and switches symlink)
bash /home/tscu0290/deploy/bin/grasp_deploy.sh prod main

# Preview only (no writes)
bash /home/tscu0290/deploy/bin/grasp_deploy.sh prod main --dry-run

# Quick status
bash /home/tscu0290/deploy/bin/grasp_deploy.sh status
```

## What `deploy_staging_to_prod.sh` does

`deploy_staging_to_prod.sh` synchronizes **staging -> prod** on the server.

It provides:
- `--dry-run` preview mode
- mandatory `--yes` confirmation for real deploys
- deploy lock to prevent concurrent runs
- automatic prod backup before live deploy
- logging to `deploy-logs`
- two strategies:
  - `rsync` (default): in-place sync to `prod`
  - `symlink`: create a new release folder and atomically switch symlinks

## Where to run it

Run this script on the **hosting server over SSH**, not on your local machine.

Reason: it operates on server paths like `/home/tscu0290/public_html/staging` and `/home/tscu0290/public_html/prod`.

If you run it locally, it will target local folders instead of the hosting environment.

## Required/optional arguments

Usage:

```bash
bash deploy_staging_to_prod.sh --dry-run [--strategy=rsync|symlink]
bash deploy_staging_to_prod.sh --yes [--strategy=rsync|symlink]
```

Arguments:
- `--dry-run`: show changes only; no writes
- `--yes`: required for a real deploy
- `--strategy=rsync|symlink`: optional; default is `rsync`

If you omit both `--dry-run` and `--yes`, the script exits with an error.

## Recommended invocation on your server

Use explicit `BASE_DIR` so paths are unambiguous:

```bash
BASE_DIR=/home/tscu0290/public_html \
bash /home/tscu0290/public_html/staging/scripts/deploy_staging_to_prod.sh --dry-run --strategy=symlink
```

Then run the live deploy:

```bash
BASE_DIR=/home/tscu0290/public_html \
bash /home/tscu0290/public_html/staging/scripts/deploy_staging_to_prod.sh --yes --strategy=symlink
```

Notes:
- `symlink` strategy matches your observed structure (`prod -> releases/release_*`).
- If you prefer direct file sync into existing `prod`, use `--strategy=rsync`.

## Output locations (under `BASE_DIR`)

- backups: `deploy-backups/prod_backup_YYYYMMDD_HHMMSS.tar.gz`
- logs: `deploy-logs/deploy_YYYYMMDD_HHMMSS.log`
- lock: `.deploy_staging_to_prod.lock` (auto-removed on exit)

## Verify current release

```bash
BASE_DIR=/home/tscu0290/public_html \
bash /home/tscu0290/public_html/staging/scripts/list_releases.sh --reverse --limit=10

ls -l /home/tscu0290/public_html/prod
ls -l /home/tscu0290/public_html/deploy_current
```

## Rollback to previous release

Dry run:

```bash
BASE_DIR=/home/tscu0290/public_html \
bash /home/tscu0290/public_html/staging/scripts/rollback_to_previous_release.sh --dry-run
```

Live rollback:

```bash
BASE_DIR=/home/tscu0290/public_html \
bash /home/tscu0290/public_html/staging/scripts/rollback_to_previous_release.sh --yes
```

## Reconciliation status for prod automation

Current environment signals:
- root `.htaccess` rewrites production traffic to `/prod`
- your FileZilla view shows `prod` behaving like a symlink to `releases/release_*`
- `grasp_deploy.sh` now targets `prod` and uses `releases + deploy_current` for production

Standardized model (active):

1. `prod` release model (recommended):
- keep Apache rewrite to `/prod`
- CI deploys direct from `main` using `grasp_deploy.sh prod main`
- manual promote path remains available via `deploy_staging_to_prod.sh --strategy=symlink`
- use `list_releases.sh` + `rollback_to_previous_release.sh` for ops

Do not run mixed `live` and `prod` models in parallel.
