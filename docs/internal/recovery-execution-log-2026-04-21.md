# Recovery Execution Log - 2026-04-21

## Objective
Recover April 16, 2026 through April 20, 2026 submissions that were sent without PDF attachments, rebuild normalized DB records where needed, and resend the emails with regenerated PDF attachments.

## Branch Deployed
- Branch: `spike/email-to-pdf-attachment`
- Recovery commits on branch:
  - `def726c` `feat(recovery): add missing-attachment replay tooling`
  - `1c9528e` `docs(recovery): clarify hosted resend sequencing`

## Host Information
- Host: `72.251.7.108`
- Port: `27`
- User: `tscu0290`
- Server hostname: `srv38.swhc.ca`

## Relevant Hosted Paths
- Source checkout: `/home/tscu0290/deploy/grasp-src`
- Deploy script: `/home/tscu0290/deploy/bin/grasp_deploy.sh`
- Staging root: `/home/tscu0290/public_html/staging`
- Production root: `/home/tscu0290/public_html/prod`
- New production release created during this run:
  - `/home/tscu0290/public_html/releases/release_20260421_105704`

## Exact Executed Commands
### Connectivity and discovery
```bash
ssh -p 27 tscu0290@72.251.7.108
bash /home/tscu0290/deploy/bin/grasp_deploy.sh status
```

### Staging deploy and rehearsal
```bash
bash /home/tscu0290/deploy/bin/grasp_deploy.sh staging spike/email-to-pdf-attachment --dry-run
bash /home/tscu0290/deploy/bin/grasp_deploy.sh staging spike/email-to-pdf-attachment
mkdir -p /home/tscu0290/tmp/recovery-input /home/tscu0290/tmp/recovery-reports
chmod 700 /home/tscu0290/tmp/recovery-input /home/tscu0290/tmp/recovery-reports
```

Batch upload:
```bash
scp -P 27 <local batch file> tscu0290@72.251.7.108:/home/tscu0290/tmp/recovery-input/
```

Initial staging invocation that exposed CLI parsing nuance:
```bash
php scripts/recover_missing_attachments.php \
  --input /home/tscu0290/tmp/recovery-input/recovery-live-batch-2026-04-16_to_2026-04-20-no-attachment.json \
  --report /home/tscu0290/tmp/recovery-reports/recovery-live-staging-dryrun-report.json \
  --env staging \
  --no-db
```

Corrected staging dry run:
```bash
cd /home/tscu0290/public_html/staging
php scripts/recover_missing_attachments.php \
  --input /home/tscu0290/tmp/recovery-input/recovery-live-batch-2026-04-16_to_2026-04-20-no-attachment.json \
  --report /home/tscu0290/tmp/recovery-reports/recovery-live-staging-dryrun-report-2.json \
  --env=staging \
  --no-db
```

### Production deploy and execution
```bash
bash /home/tscu0290/deploy/bin/grasp_deploy.sh prod spike/email-to-pdf-attachment --dry-run
bash /home/tscu0290/deploy/bin/grasp_deploy.sh prod spike/email-to-pdf-attachment
```

Production dry run:
```bash
cd /home/tscu0290/public_html/prod
php scripts/recover_missing_attachments.php \
  --input /home/tscu0290/tmp/recovery-input/recovery-live-batch-2026-04-16_to_2026-04-20-no-attachment.json \
  --report /home/tscu0290/tmp/recovery-reports/recovery-live-prod-dryrun-report.json \
  --env=production \
  --no-db
```

Production DB write:
```bash
cd /home/tscu0290/public_html/prod
php scripts/recover_missing_attachments.php \
  --input /home/tscu0290/tmp/recovery-input/recovery-live-batch-2026-04-16_to_2026-04-20-no-attachment.json \
  --report /home/tscu0290/tmp/recovery-reports/recovery-live-prod-db-report.json \
  --env=production
```

Production resend:
```bash
cd /home/tscu0290/public_html/prod
php scripts/recover_missing_attachments.php \
  --input /home/tscu0290/tmp/recovery-input/recovery-live-batch-2026-04-16_to_2026-04-20-no-attachment.json \
  --report /home/tscu0290/tmp/recovery-reports/recovery-live-prod-send-report.json \
  --env=production \
  --send \
  --no-db
```

### Cleanup
```bash
rm -f /home/tscu0290/tmp/recovery-input/recovery-live-batch-2026-04-16_to_2026-04-20-no-attachment.json
rm -f /home/tscu0290/tmp/recovery-reports/recovery-live-staging-dryrun-report.json
rm -f /home/tscu0290/tmp/recovery-reports/recovery-live-staging-dryrun-report-2.json
rm -f /home/tscu0290/tmp/recovery-reports/recovery-live-prod-dryrun-report.json
rm -f /home/tscu0290/tmp/recovery-reports/recovery-live-prod-db-report.json
rm -f /home/tscu0290/tmp/recovery-reports/recovery-live-prod-send-report.json
```

## Outcomes
### Corrected staging dry run
- `processed: 5`
- `recovered: 4`
- `skipped: 1`
- `dbSaved: 0`
- `mailSent: 0`
- `failed: 0`

### Production dry run
- `processed: 5`
- `recovered: 4`
- `skipped: 1`
- `dbSaved: 0`
- `mailSent: 0`
- `failed: 0`

### Production DB write
- `processed: 5`
- `recovered: 4`
- `skipped: 1`
- `dbSaved: 4`
- `mailSent: 0`
- `failed: 0`

Created submission IDs:
- enrollment `41cedc6d68b65b91539692819710aacd` -> `46`
- enrollment `f9b86aac122f6a30e42eb30c82a3208d` -> `47`
- enrollment `93be39a85c9014cfb625cf77ed14e8ea` -> `48`
- waitlist `dc0d021c-77a0-46aa-aeb3-f15c961516a4` -> `49`

### Production resend
- `processed: 5`
- `recovered: 4`
- `skipped: 1`
- `dbSaved: 0`
- `mailSent: 4`
- `failed: 0`

Resends targeted:
- `info@greenlandrecreational.com`
- BCC `edward.apostol@gmail.com`

## Important Lesson Learned
Use `--env=staging` and `--env=production` with an equals sign.
The space-separated form (`--env staging`) caused the first staging rehearsal to ignore `--no-db` and write 4 staging DB rows.
