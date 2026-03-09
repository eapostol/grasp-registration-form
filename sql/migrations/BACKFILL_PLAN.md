# Legacy Backfill Plan (`enrollments.data_json` -> normalized schema)

## Objective
Move legacy enrollment payloads from `enrollments.data_json` into the normalized schema in controlled stages, with QA checkpoints and rollback safety.

## Scope
- Source: `enrollments` (legacy table)
- Target (future insert stage): normalized tables created in `001` to `004` migrations
- Current stages delivered now:
  - `005`: staging + flattening + issue capture
  - `006`: idempotent inserts/upserts into normalized tables + reconciliation views

## Staged execution
1. Apply schema migrations with:
   - `sql/migrations/apply_staged.sh`
2. Run QA checks on stage views:
   - `SELECT * FROM v_legacy_backfill_batch_summary ORDER BY backfill_batch_id DESC;`
   - `SELECT * FROM v_legacy_backfill_top_keys WHERE backfill_batch_id = '<batch-id>';`
3. Review issues:
   - `SELECT * FROM legacy_enrollment_backfill_issue WHERE backfill_batch_id = '<batch-id>' ORDER BY severity, id;`
4. Approve field-mapping matrix (key -> destination table/column).
5. Apply `006_backfill_legacy_to_normalized.sql`.
6. Review reconciliation views and issue table.

## Mapping strategy (initial)
- Package-level identity:
  - `session_id` -> `submission_package` natural linkage key.
  - missing session fallback (planned): deterministic synthetic key from legacy id and created timestamp.
- Submission-level:
  - each legacy row -> one `form_submission`.
  - carry `form_id`, `status`, `submitted_at`, `created_at`, `updated_at`.
- Field-level:
  - consent-like fields -> `consent_response`.
  - free text / scalar entries -> `manual_field_response`.
  - people/address/child values -> typed tables from mapped keys.

## QA gates before normalized inserts
- Gate A: Row counts
  - `legacy_enrollment_backfill_stage` count equals valid JSON row count in `enrollments`.
- Gate B: Key coverage
  - Top keys align with current form JSON config fields.
- Gate C: Exception threshold
  - unresolved `error` issues must be zero.
- Gate D: Sample parity
  - random samples match legacy payload content.

## Rollback approach
- Current stage is non-destructive and additive.
- To rerun a batch:
  - remove staged rows by `backfill_batch_id` (cascade handles kv/issues), then rerun.
- For normalized rows written by `006`:
  - use `legacy_enrollment_submission_map` to identify inserted `submission_id` rows,
  - delete mapped normalized rows in reverse dependency order when needed.

## Reconciliation queries
- `SELECT * FROM v_backfill_reconciliation_by_batch ORDER BY backfill_batch_id DESC;`
- `SELECT * FROM v_backfill_field_reconciliation_by_batch ORDER BY backfill_batch_id DESC;`
- `SELECT * FROM legacy_enrollment_backfill_issue ORDER BY created_at DESC, id DESC LIMIT 200;`
