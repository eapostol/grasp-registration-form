# Recovery Hosted Execution Checklist

## Purpose
- Recover April 16, 2026 through April 20, 2026 submissions that were emailed without PDF attachments.
- Rebuild normalized database records first.
- Re-send corrected email bodies and regenerated PDFs only after spot-checking the dry-run and no-send phases.

## Script
- Recovery CLI: `scripts/recover_missing_attachments.php`
- Important: use `--env=staging` or `--env=production` with an equals sign.
- Do not rely on `--env staging` or `--env production`; PHP `getopt` parsing treated that form incorrectly during hosted testing.

## Hosted Paths
- SSH host: `72.251.7.108`
- SSH port: `27`
- SSH user: `tscu0290`
- Source checkout: `/home/tscu0290/deploy/grasp-src`
- Deploy script: `/home/tscu0290/deploy/bin/grasp_deploy.sh`
- Staging app root: `/home/tscu0290/public_html/staging`
- Production app root: `/home/tscu0290/public_html/prod`
- Temporary input directory: `/home/tscu0290/tmp/recovery-input`
- Temporary report directory: `/home/tscu0290/tmp/recovery-reports`

## Important Safety Notes
- Do not commit the live batch export or any generated reports. They contain real submission content and PII.
- Run the commands from the deployed project root on the hosted environment so `api/config.php` resolves the correct environment configuration.
- Keep mail sending off until the dry-run report and regenerated PDFs look correct.
- The CLI skips duplicate `(formType, sessionId)` pairs within the same batch so repeated Gmail messages do not resend twice.
- On this host, deploy code through `~/deploy/bin/grasp_deploy.sh` instead of editing `public_html/staging` or `public_html/prod` by hand.

## Staging Rehearsal
### Deploy branch to staging
```bash
ssh -p 27 tscu0290@72.251.7.108
bash /home/tscu0290/deploy/bin/grasp_deploy.sh staging spike/email-to-pdf-attachment --dry-run
bash /home/tscu0290/deploy/bin/grasp_deploy.sh staging spike/email-to-pdf-attachment
```

### Prepare temporary directories
```bash
mkdir -p /home/tscu0290/tmp/recovery-input /home/tscu0290/tmp/recovery-reports
chmod 700 /home/tscu0290/tmp/recovery-input /home/tscu0290/tmp/recovery-reports
```

### Upload live batch file
```bash
scp -P 27 <local-batch-json> \
  tscu0290@72.251.7.108:/home/tscu0290/tmp/recovery-input/
```

### Run staging dry run
```bash
cd /home/tscu0290/public_html/staging

php scripts/recover_missing_attachments.php \
  --input /home/tscu0290/tmp/recovery-input/recovery-live-batch-2026-04-16_to_2026-04-20-no-attachment.json \
  --report /home/tscu0290/tmp/recovery-reports/recovery-live-staging-dryrun-report.json \
  --env=staging \
  --no-db
```

## Production Execution
### Deploy branch to production
```bash
ssh -p 27 tscu0290@72.251.7.108
bash /home/tscu0290/deploy/bin/grasp_deploy.sh prod spike/email-to-pdf-attachment --dry-run
bash /home/tscu0290/deploy/bin/grasp_deploy.sh prod spike/email-to-pdf-attachment
```

### Production dry run
```bash
cd /home/tscu0290/public_html/prod

php scripts/recover_missing_attachments.php \
  --input /home/tscu0290/tmp/recovery-input/recovery-live-batch-2026-04-16_to_2026-04-20-no-attachment.json \
  --report /home/tscu0290/tmp/recovery-reports/recovery-live-prod-dryrun-report.json \
  --env=production \
  --no-db
```

### Production DB write, no mail
```bash
cd /home/tscu0290/public_html/prod

php scripts/recover_missing_attachments.php \
  --input /home/tscu0290/tmp/recovery-input/recovery-live-batch-2026-04-16_to_2026-04-20-no-attachment.json \
  --report /home/tscu0290/tmp/recovery-reports/recovery-live-prod-db-report.json \
  --env=production
```

### Production resend, no additional DB write
```bash
cd /home/tscu0290/public_html/prod

php scripts/recover_missing_attachments.php \
  --input /home/tscu0290/tmp/recovery-input/recovery-live-batch-2026-04-16_to_2026-04-20-no-attachment.json \
  --report /home/tscu0290/tmp/recovery-reports/recovery-live-prod-send-report.json \
  --env=production \
  --send \
  --no-db
```

## Validation Expectations
- Dry run should show `dbSaved: 0` and `mailSent: 0`.
- DB-write pass should show `dbSaved` equal to the number of unique recovered submissions.
- Send pass should show `mailSent` equal to the number of unique recovered submissions.
- Duplicate Gmail copies of the same session should appear as `duplicate-session-in-batch` and be skipped.

## Cleanup
After validation, remove the live input and report files from the host:
```bash
rm -f /home/tscu0290/tmp/recovery-input/recovery-live-batch-2026-04-16_to_2026-04-20-no-attachment.json
rm -f /home/tscu0290/tmp/recovery-reports/recovery-live-staging-dryrun-report.json
rm -f /home/tscu0290/tmp/recovery-reports/recovery-live-staging-dryrun-report-2.json
rm -f /home/tscu0290/tmp/recovery-reports/recovery-live-prod-dryrun-report.json
rm -f /home/tscu0290/tmp/recovery-reports/recovery-live-prod-db-report.json
rm -f /home/tscu0290/tmp/recovery-reports/recovery-live-prod-send-report.json
```
