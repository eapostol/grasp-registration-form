# Recovery Repeatable Runbook

## When To Use This
Use this runbook if submitted enrollment, waitlist, or parent manual emails were sent without PDF attachments and the original email body still exists or can be exported.

## Primary Script
- `scripts/recover_missing_attachments.php`

## Supporting Files
- Hosted checklist: [recovery-hosted-execution-checklist.md](/home/administrator/wsl-sites/2025/GRASP/reg-form-project/docs/internal/recovery-hosted-execution-checklist.md)
- Parser tests: [test_recovery_message_parsing.php](/home/administrator/wsl-sites/2025/GRASP/reg-form-project/scripts/test_recovery_message_parsing.php)
- Parser fixtures: `scripts/fixtures/recovery/`

## Repeatable Process Summary
1. Export the affected emails into a JSON batch file.
2. Deploy the recovery-capable branch to staging with `grasp_deploy.sh`.
3. Run a staging dry run with `--env=staging --no-db`.
4. Review the report and inspect regenerated PDFs.
5. Deploy the same branch to production.
6. Run a production dry run with `--env=production --no-db`.
7. Run a production DB-write pass with `--env=production`.
8. Run a production resend pass with `--env=production --send --no-db`.
9. Confirm receipt at the organization inbox and BCC inbox.
10. Clean the temporary input and report files from the host.

## Required Command Pattern
Always use the long-option form with equals signs for `env`:
```bash
--env=staging
--env=production
```
Do not use:
```bash
--env staging
--env production
```

## Server Paths Used In April 2026 Recovery
- Source checkout: `/home/tscu0290/deploy/grasp-src`
- Deploy script: `/home/tscu0290/deploy/bin/grasp_deploy.sh`
- Staging: `/home/tscu0290/public_html/staging`
- Production: `/home/tscu0290/public_html/prod`
- Temporary batch inputs: `/home/tscu0290/tmp/recovery-input`
- Temporary reports: `/home/tscu0290/tmp/recovery-reports`

## Expected Safe Outcomes
- Dry run: `dbSaved = 0`, `mailSent = 0`
- DB-write pass: `dbSaved = unique submission count`
- Send pass: `mailSent = unique submission count`
- Duplicate messages with the same `(formType, sessionId)` are skipped automatically.

## If This Happens Again
The repeatable operational entrypoint is still the same script:
```bash
php scripts/recover_missing_attachments.php
```
But do not run it directly on the host until:
- the correct branch is deployed to the target environment
- the batch JSON is uploaded into a private temporary directory
- the command uses `--env=...` with an equals sign

## Notes On Scope
This recovery flow is intended to be operationally isolated.
It rebuilds PDFs and resend emails without changing the core form submission path for normal users.
