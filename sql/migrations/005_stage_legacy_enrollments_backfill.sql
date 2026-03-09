-- Initial backfill staging from legacy enrollments.data_json.
-- This migration is intentionally non-destructive:
--   1) It does not modify legacy enrollments rows.
--   2) It does not write directly into normalized domain tables yet.
--   3) It prepares a validated staging layer for controlled backfill.

-- Phase 1: Snapshot candidate legacy rows to be migrated.
CREATE TABLE IF NOT EXISTS legacy_enrollment_backfill_stage (
  legacy_id BIGINT UNSIGNED NOT NULL,
  form_id VARCHAR(120) NULL,
  session_id VARCHAR(191) NULL,
  status VARCHAR(40) NULL,
  submitted_at DATETIME NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  data_json JSON NULL,
  backfill_batch_id CHAR(36) NOT NULL,
  staged_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (legacy_id, backfill_batch_id),
  KEY idx_legacy_enrollment_backfill_session (session_id),
  KEY idx_legacy_enrollment_backfill_form (form_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 2: Flatten JSON payload into key/value rows for mapping and QA.
CREATE TABLE IF NOT EXISTS legacy_enrollment_kv_stage (
  legacy_id BIGINT UNSIGNED NOT NULL,
  backfill_batch_id CHAR(36) NOT NULL,
  field_key VARCHAR(191) NOT NULL,
  field_value_long LONGTEXT NULL,
  value_type VARCHAR(20) NOT NULL,
  staged_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (legacy_id, backfill_batch_id, field_key),
  KEY idx_legacy_enrollment_kv_key (field_key),
  CONSTRAINT fk_legacy_enrollment_kv_stage_parent
    FOREIGN KEY (legacy_id, backfill_batch_id)
    REFERENCES legacy_enrollment_backfill_stage (legacy_id, backfill_batch_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 3: Track mapping/exceptions discovered during QA and dry-runs.
CREATE TABLE IF NOT EXISTS legacy_enrollment_backfill_issue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  legacy_id BIGINT UNSIGNED NOT NULL,
  backfill_batch_id CHAR(36) NOT NULL,
  severity ENUM('info', 'warning', 'error') NOT NULL DEFAULT 'warning',
  issue_code VARCHAR(120) NOT NULL,
  issue_message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_legacy_enrollment_issue_lookup (legacy_id, backfill_batch_id, severity),
  CONSTRAINT fk_legacy_enrollment_issue_parent
    FOREIGN KEY (legacy_id, backfill_batch_id)
    REFERENCES legacy_enrollment_backfill_stage (legacy_id, backfill_batch_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill batch identifier for this execution.
SET @backfill_batch_id = UUID();

-- Stage all legacy rows that contain JSON payloads.
INSERT INTO legacy_enrollment_backfill_stage (
  legacy_id,
  form_id,
  session_id,
  status,
  submitted_at,
  created_at,
  updated_at,
  data_json,
  backfill_batch_id
)
SELECT
  e.id,
  e.form_id,
  e.session_id,
  e.status,
  e.submitted_at,
  e.created_at,
  e.updated_at,
  e.data_json,
  @backfill_batch_id
FROM enrollments e
WHERE e.data_json IS NOT NULL
  AND JSON_VALID(e.data_json);

-- Flatten top-level JSON fields.
-- This assumes legacy payloads are object-like maps of "field_key": value.
INSERT INTO legacy_enrollment_kv_stage (
  legacy_id,
  backfill_batch_id,
  field_key,
  field_value_long,
  value_type
)
SELECT
  s.legacy_id,
  s.backfill_batch_id,
  jt.field_key,
  JSON_UNQUOTE(JSON_EXTRACT(s.data_json, CONCAT('$.', jt.field_key))) AS field_value_long,
  JSON_TYPE(JSON_EXTRACT(s.data_json, CONCAT('$.', jt.field_key))) AS value_type
FROM legacy_enrollment_backfill_stage s
JOIN JSON_TABLE(
  JSON_KEYS(s.data_json),
  '$[*]' COLUMNS (
    field_key VARCHAR(191) PATH '$'
  )
) AS jt
WHERE s.backfill_batch_id = @backfill_batch_id;

-- Flag obvious anomalies for review before normalized inserts.
INSERT INTO legacy_enrollment_backfill_issue (
  legacy_id,
  backfill_batch_id,
  severity,
  issue_code,
  issue_message
)
SELECT
  s.legacy_id,
  s.backfill_batch_id,
  'warning',
  'MISSING_SESSION_ID',
  'Legacy row has null/blank session_id; package-level linkage may need deterministic fallback.'
FROM legacy_enrollment_backfill_stage s
WHERE s.backfill_batch_id = @backfill_batch_id
  AND (s.session_id IS NULL OR TRIM(s.session_id) = '');

INSERT INTO legacy_enrollment_backfill_issue (
  legacy_id,
  backfill_batch_id,
  severity,
  issue_code,
  issue_message
)
SELECT
  s.legacy_id,
  s.backfill_batch_id,
  'warning',
  'NON_OBJECT_JSON',
  'data_json is valid but not an object; key/value flattening may be incomplete.'
FROM legacy_enrollment_backfill_stage s
WHERE s.backfill_batch_id = @backfill_batch_id
  AND JSON_TYPE(s.data_json) <> 'OBJECT';

-- Helpful validation views for dry-run analysis.
CREATE OR REPLACE VIEW v_legacy_backfill_batch_summary AS
SELECT
  backfill_batch_id,
  COUNT(*) AS staged_rows,
  SUM(CASE WHEN session_id IS NULL OR TRIM(session_id) = '' THEN 1 ELSE 0 END) AS missing_session_rows,
  MIN(created_at) AS min_created_at,
  MAX(created_at) AS max_created_at
FROM legacy_enrollment_backfill_stage
GROUP BY backfill_batch_id;

CREATE OR REPLACE VIEW v_legacy_backfill_top_keys AS
SELECT
  backfill_batch_id,
  field_key,
  COUNT(*) AS occurrences
FROM legacy_enrollment_kv_stage
GROUP BY backfill_batch_id, field_key
ORDER BY backfill_batch_id, occurrences DESC, field_key ASC;

-- Next step (planned in follow-up migration):
--   Insert from these stage tables into normalized entities:
--   submission_package, form_submission, person, address, child_profile,
--   consent_response, manual_field_response, submission_event.
