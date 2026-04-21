# Recovery Hosted Execution Checklist

## Purpose
- Recover April 16, 2026 through April 20, 2026 submissions that were emailed without PDF attachments.
- Rebuild normalized database records first.
- Re-send corrected email bodies and regenerated PDFs only after spot-checking the dry-run and no-send phases.

## Prepared Batch Inputs
- Live batch export: `test-results/recovery-live-batch-2026-04-16_to_2026-04-20-no-attachment.json`
- Dry-run report target: `test-results/recovery-live-dryrun-report.json`
- DB-write report target: `test-results/recovery-live-db-report.json`
- Send report target: `test-results/recovery-live-send-report.json`

## Important Safety Notes
- Do not commit the live batch export or any generated reports. They contain real submission content and PII.
- Run the commands from the deployed project root on the hosted environment so `api/config.php` resolves production credentials.
- Keep mail sending off until the dry-run report and regenerated PDFs look correct.
- The CLI now skips duplicate `(formType, sessionId)` pairs within the same batch so repeated Gmail messages do not resend twice.

## Phase 1: Dry Run, No DB Writes, No Mail
```bash
php scripts/recover_missing_attachments.php \
  --input test-results/recovery-live-batch-2026-04-16_to_2026-04-20-no-attachment.json \
  --report test-results/recovery-live-dryrun-report.json \
  --no-db
```

Review after Phase 1:
- Confirm `failed` is `0`, or review each failed entry before continuing.
- Confirm `skipped` entries are only expected duplicates or already-attached messages.
- Confirm `recovered` matches the number of unique submissions expected from the Gmail export.
- Open the generated PDFs referenced by the report and spot-check layout, names, dates, and page structure.

## Phase 2: Real DB Write, Mail Still Off
```bash
php scripts/recover_missing_attachments.php \
  --input test-results/recovery-live-batch-2026-04-16_to_2026-04-20-no-attachment.json \
  --report test-results/recovery-live-db-report.json
```

Review after Phase 2:
- Confirm `dbSaved` matches the expected unique recovered submissions.
- Verify the new rows in normalized tables and, for enrollment, the legacy `enrollments` row update path.
- Spot-check a regenerated PDF again after the DB write pass.

## Phase 3: Resend Recovered Mail With Attachments
```bash
php scripts/recover_missing_attachments.php \
  --input test-results/recovery-live-batch-2026-04-16_to_2026-04-20-no-attachment.json \
  --report test-results/recovery-live-send-report.json \
  --send \
  --no-db
```

Review after Phase 3:
- Confirm `mailSent` matches the expected unique recovered submissions.
- Verify emails arrive at `info@greenlandrecreational.com`.
- Verify BCC copies arrive at `edward.apostol@gmail.com`.
- Check one enrollment, one waitlist, and one parent manual resend for body layout plus PDF attachment integrity.
- Note: `--no-db` keeps the resend phase from creating duplicate normalized submission rows after the DB-write phase.

## Alternative: Single Live Pass After Review
- If you prefer one live pass instead of a DB-only phase followed by a send phase, you can run:
```bash
php scripts/recover_missing_attachments.php \
  --input test-results/recovery-live-batch-2026-04-16_to_2026-04-20-no-attachment.json \
  --report test-results/recovery-live-livepass-report.json \
  --send
```
- Use this only after reviewing the dry-run results and spot-checking generated PDFs.

## Batch-Specific Notes
- Current live batch contains 5 Gmail messages.
- One waitlist submission is duplicated in Gmail and should be skipped automatically by session ID.
- Parent manual did not appear in the urgent April 16 through April 20 no-attachment window.

## Commit Scope Guidance
- Safe to commit:
  - `scripts/recover_missing_attachments.php`
  - `scripts/lib/RecoveryMessageParser.php`
  - `scripts/lib/RecoveryRepository.php`
  - `scripts/lib/RecoverySubmissionService.php`
  - `scripts/test_recovery_message_parsing.php`
  - `scripts/fixtures/recovery/*`
  - `docs/internal/recovery-hosted-execution-checklist.md`
- Do not commit:
  - `test-results/recovery-live-batch-2026-04-16_to_2026-04-20-no-attachment.json`
  - `test-results/recovery-live-dryrun-report.json`
  - `test-results/recovery-live-db-report.json`
  - `test-results/recovery-live-send-report.json`
  - any other artifacts containing real submission content or generated PDFs
