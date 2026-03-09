-- 006_backfill_legacy_to_normalized.sql
-- Idempotent backfill from legacy staging tables into normalized tables.
--
-- Prereq:
--   001-005 migrations have been applied.
--
-- Design notes:
--   - Non-destructive: legacy source rows are untouched.
--   - Idempotent: mapping table prevents duplicate reprocessing.
--   - Generic: uses staged key/value rows for flexible field ingestion.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS legacy_enrollment_backfill_run (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  execution_id CHAR(36) NOT NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_legacy_backfill_run_execution (execution_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS legacy_enrollment_submission_map (
  legacy_id BIGINT UNSIGNED NOT NULL,
  backfill_batch_id CHAR(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  package_id BIGINT UNSIGNED NOT NULL,
  submission_id BIGINT UNSIGNED NOT NULL,
  session_id_effective VARCHAR(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  form_type_mapped ENUM('waitlist', 'enrollment', 'parent_manual') NOT NULL,
  submission_status_mapped ENUM('draft', 'submitted', 'superseded', 'void') NOT NULL,
  execution_id CHAR(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (legacy_id, backfill_batch_id),
  UNIQUE KEY uq_legacy_submission_map_submission_id (submission_id),
  KEY idx_legacy_submission_map_batch (backfill_batch_id),
  CONSTRAINT fk_legacy_submission_map_stage
    FOREIGN KEY (legacy_id, backfill_batch_id)
    REFERENCES legacy_enrollment_backfill_stage (legacy_id, backfill_batch_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_legacy_submission_map_package
    FOREIGN KEY (package_id) REFERENCES submission_package(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_legacy_submission_map_submission
    FOREIGN KEY (submission_id) REFERENCES form_submission(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @execution_id = UUID();

INSERT INTO legacy_enrollment_backfill_run (execution_id, notes)
VALUES (@execution_id, 'Migration 006 normalized backfill');

DROP TEMPORARY TABLE IF EXISTS tmp_legacy_to_process;
CREATE TEMPORARY TABLE tmp_legacy_to_process (
  legacy_id BIGINT UNSIGNED NOT NULL,
  backfill_batch_id CHAR(36) NOT NULL,
  form_id VARCHAR(120) NULL,
  source_status VARCHAR(40) NULL,
  submitted_at DATETIME NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL,
  data_json JSON NULL,
  session_id_effective VARCHAR(191) NOT NULL,
  form_type_mapped ENUM('waitlist', 'enrollment', 'parent_manual') NOT NULL,
  submission_status_mapped ENUM('draft', 'submitted', 'superseded', 'void') NOT NULL,
  package_status_mapped ENUM('draft', 'waitlist_submitted', 'enrollment_submitted', 'manual_submitted', 'completed') NOT NULL,
  created_at_effective DATETIME NOT NULL,
  updated_at_effective DATETIME NOT NULL,
  payload_text LONGTEXT NOT NULL,
  PRIMARY KEY (legacy_id, backfill_batch_id),
  KEY idx_tmp_ltp_session (session_id_effective),
  KEY idx_tmp_ltp_form (form_type_mapped),
  KEY idx_tmp_ltp_status (submission_status_mapped)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_legacy_to_process (
  legacy_id,
  backfill_batch_id,
  form_id,
  source_status,
  submitted_at,
  created_at,
  updated_at,
  data_json,
  session_id_effective,
  form_type_mapped,
  submission_status_mapped,
  package_status_mapped,
  created_at_effective,
  updated_at_effective,
  payload_text
)
SELECT
  s.legacy_id,
  s.backfill_batch_id,
  s.form_id,
  s.status AS source_status,
  s.submitted_at,
  s.created_at,
  s.updated_at,
  s.data_json,
  COALESCE(NULLIF(TRIM(s.session_id), ''), CONCAT('legacy-', s.legacy_id)) AS session_id_effective,
  CASE
    WHEN LOWER(COALESCE(s.form_id, '')) LIKE '%wait%' THEN 'waitlist'
    WHEN LOWER(COALESCE(s.form_id, '')) LIKE '%manual%' THEN 'parent_manual'
    WHEN LOWER(COALESCE(s.form_id, '')) LIKE '%parent%' THEN 'parent_manual'
    ELSE 'enrollment'
  END AS form_type_mapped,
  CASE
    WHEN LOWER(COALESCE(s.status, '')) = 'submitted' THEN 'submitted'
    WHEN LOWER(COALESCE(s.status, '')) = 'void' THEN 'void'
    WHEN LOWER(COALESCE(s.status, '')) = 'superseded' THEN 'superseded'
    ELSE 'draft'
  END AS submission_status_mapped,
  CASE
    WHEN LOWER(COALESCE(s.status, '')) = 'submitted'
         AND (LOWER(COALESCE(s.form_id, '')) LIKE '%wait%') THEN 'waitlist_submitted'
    WHEN LOWER(COALESCE(s.status, '')) = 'submitted'
         AND (LOWER(COALESCE(s.form_id, '')) LIKE '%manual%' OR LOWER(COALESCE(s.form_id, '')) LIKE '%parent%') THEN 'manual_submitted'
    WHEN LOWER(COALESCE(s.status, '')) = 'submitted' THEN 'enrollment_submitted'
    ELSE 'draft'
  END AS package_status_mapped,
  COALESCE(s.created_at, s.submitted_at, NOW()) AS created_at_effective,
  COALESCE(s.updated_at, s.submitted_at, s.created_at, NOW()) AS updated_at_effective,
  CAST(s.data_json AS CHAR) AS payload_text
FROM legacy_enrollment_backfill_stage s
LEFT JOIN legacy_enrollment_submission_map m
  ON m.legacy_id = s.legacy_id
 AND m.backfill_batch_id = s.backfill_batch_id
WHERE m.legacy_id IS NULL;

-- Upsert package rows first.
INSERT INTO submission_package (session_id, status, created_at, updated_at)
SELECT DISTINCT
  t.session_id_effective,
  t.package_status_mapped,
  MIN(t.created_at_effective) AS created_at_effective,
  MAX(t.updated_at_effective) AS updated_at_effective
FROM tmp_legacy_to_process t
GROUP BY t.session_id_effective, t.package_status_mapped
ON DUPLICATE KEY UPDATE
  status = CASE
    WHEN submission_package.status = 'completed' OR VALUES(status) = 'completed' THEN 'completed'
    WHEN submission_package.status = 'manual_submitted' OR VALUES(status) = 'manual_submitted' THEN 'manual_submitted'
    WHEN submission_package.status = 'enrollment_submitted' OR VALUES(status) = 'enrollment_submitted' THEN 'enrollment_submitted'
    WHEN submission_package.status = 'waitlist_submitted' OR VALUES(status) = 'waitlist_submitted' THEN 'waitlist_submitted'
    ELSE 'draft'
  END,
  created_at = LEAST(submission_package.created_at, VALUES(created_at)),
  updated_at = GREATEST(submission_package.updated_at, VALUES(updated_at));

-- Insert form submission rows when equivalent row does not already exist.
INSERT INTO form_submission (
  package_id,
  form_type,
  form_version,
  submitted_at,
  status,
  payload_json,
  email_html,
  pdf_html,
  created_at,
  updated_at
)
SELECT
  sp.id AS package_id,
  t.form_type_mapped,
  NULL AS form_version,
  t.submitted_at,
  t.submission_status_mapped,
  t.payload_text,
  NULL AS email_html,
  NULL AS pdf_html,
  t.created_at_effective,
  t.updated_at_effective
FROM tmp_legacy_to_process t
JOIN submission_package sp
  ON sp.session_id = t.session_id_effective COLLATE utf8mb4_unicode_ci
WHERE NOT EXISTS (
  SELECT 1
  FROM form_submission fs
  WHERE fs.package_id = sp.id
    AND fs.form_type = t.form_type_mapped
    AND fs.status = t.submission_status_mapped
    AND fs.created_at = t.created_at_effective
    AND (fs.submitted_at <=> t.submitted_at)
    AND fs.payload_json = t.payload_text COLLATE utf8mb4_unicode_ci
);

-- Map each staged legacy row to the normalized submission row.
INSERT INTO legacy_enrollment_submission_map (
  legacy_id,
  backfill_batch_id,
  package_id,
  submission_id,
  session_id_effective,
  form_type_mapped,
  submission_status_mapped,
  execution_id
)
SELECT
  t.legacy_id,
  t.backfill_batch_id,
  fs.package_id,
  MIN(fs.id) AS submission_id,
  t.session_id_effective,
  t.form_type_mapped,
  t.submission_status_mapped,
  @execution_id
FROM tmp_legacy_to_process t
JOIN submission_package sp
  ON sp.session_id = t.session_id_effective COLLATE utf8mb4_unicode_ci
JOIN form_submission fs
  ON fs.package_id = sp.id
 AND fs.form_type = t.form_type_mapped
 AND fs.status = t.submission_status_mapped
 AND fs.created_at = t.created_at_effective
 AND (fs.submitted_at <=> t.submitted_at)
 AND fs.payload_json = t.payload_text COLLATE utf8mb4_unicode_ci
GROUP BY
  t.legacy_id,
  t.backfill_batch_id,
  fs.package_id,
  t.session_id_effective,
  t.form_type_mapped,
  t.submission_status_mapped
ON DUPLICATE KEY UPDATE
  package_id = VALUES(package_id),
  submission_id = VALUES(submission_id),
  session_id_effective = VALUES(session_id_effective),
  form_type_mapped = VALUES(form_type_mapped),
  submission_status_mapped = VALUES(submission_status_mapped),
  execution_id = VALUES(execution_id),
  processed_at = CURRENT_TIMESTAMP;

-- Generic field ingestion from flattened KV rows.
INSERT INTO submission_field_value (
  submission_id,
  field_name,
  field_type,
  value_text,
  value_number,
  value_date,
  value_bool
)
SELECT
  m.submission_id,
  kv.field_key,
  LOWER(kv.value_type) AS field_type,
  kv.field_value_long AS value_text,
  CASE
    WHEN kv.value_type IN ('INTEGER', 'DOUBLE', 'DECIMAL')
      AND kv.field_value_long REGEXP '^-?[0-9]+(\\.[0-9]+)?$'
      THEN CAST(kv.field_value_long AS DECIMAL(20,6))
    ELSE NULL
  END AS value_number,
  CASE
    WHEN kv.field_value_long REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
      THEN CAST(kv.field_value_long AS DATE)
    ELSE NULL
  END AS value_date,
  CASE
    WHEN LOWER(COALESCE(kv.field_value_long, '')) IN ('true', '1', 'yes', 'y', 'on', 'checked', 'agree', 'agreed', 'i agree', 'i acknowledge and agree')
      THEN 1
    WHEN LOWER(COALESCE(kv.field_value_long, '')) IN ('false', '0', 'no', 'n', 'off', 'unchecked', 'decline', 'disagree')
      THEN 0
    ELSE NULL
  END AS value_bool
FROM legacy_enrollment_kv_stage kv
JOIN legacy_enrollment_submission_map m
  ON m.legacy_id = kv.legacy_id
 AND m.backfill_batch_id = kv.backfill_batch_id
ON DUPLICATE KEY UPDATE
  field_type = VALUES(field_type),
  value_text = VALUES(value_text),
  value_number = VALUES(value_number),
  value_date = VALUES(value_date),
  value_bool = VALUES(value_bool),
  updated_at = CURRENT_TIMESTAMP;

-- Consent extraction heuristics from flat fields.
INSERT INTO consent_record (
  submission_id,
  consent_code,
  consent_value,
  is_agreed,
  signed_by_person_id,
  signed_at,
  meta_json
)
SELECT
  m.submission_id,
  kv.field_key AS consent_code,
  kv.field_value_long AS consent_value,
  CASE
    WHEN LOWER(COALESCE(kv.field_value_long, '')) REGEXP '(^|[^a-z])(agree|agreed|consent|consented|acknowledge|acknowledged|yes)([^a-z]|$)' THEN 1
    WHEN LOWER(COALESCE(kv.field_value_long, '')) REGEXP '(^|[^a-z])(no|decline|disagree|refuse)([^a-z]|$)' THEN 0
    ELSE NULL
  END AS is_agreed,
  NULL AS signed_by_person_id,
  NULL AS signed_at,
  JSON_OBJECT(
    'source', 'legacy_backfill',
    'legacy_id', m.legacy_id,
    'backfill_batch_id', m.backfill_batch_id,
    'execution_id', @execution_id
  ) AS meta_json
FROM legacy_enrollment_kv_stage kv
JOIN legacy_enrollment_submission_map m
  ON m.legacy_id = kv.legacy_id
 AND m.backfill_batch_id = kv.backfill_batch_id
WHERE kv.field_key NOT LIKE '%_text'
  AND LOWER(kv.field_key) REGEXP '(consent|agree|acknowledge|release|policy)'
ON DUPLICATE KEY UPDATE
  consent_value = VALUES(consent_value),
  is_agreed = VALUES(is_agreed),
  meta_json = VALUES(meta_json),
  updated_at = CURRENT_TIMESTAMP;

-- Parent manual initials extraction heuristics.
INSERT INTO manual_initial_ack (
  submission_id,
  ack_code,
  initials_value,
  required_flag
)
SELECT
  m.submission_id,
  kv.field_key AS ack_code,
  LEFT(TRIM(kv.field_value_long), 20) AS initials_value,
  1 AS required_flag
FROM legacy_enrollment_kv_stage kv
JOIN legacy_enrollment_submission_map m
  ON m.legacy_id = kv.legacy_id
 AND m.backfill_batch_id = kv.backfill_batch_id
WHERE LOWER(kv.field_key) REGEXP '(initial|initials)'
  AND COALESCE(TRIM(kv.field_value_long), '') <> ''
ON DUPLICATE KEY UPDATE
  initials_value = VALUES(initials_value),
  updated_at = CURRENT_TIMESTAMP;

-- Seed one normalized event per backfilled submission (once).
INSERT INTO submission_event (
  submission_id,
  event_type,
  event_payload_json,
  created_at
)
SELECT
  m.submission_id,
  CASE
    WHEN m.submission_status_mapped = 'submitted' THEN 'submitted'
    ELSE 'draft_saved'
  END AS event_type,
  JSON_OBJECT(
    'source', 'legacy_backfill',
    'legacy_id', m.legacy_id,
    'backfill_batch_id', m.backfill_batch_id,
    'execution_id', @execution_id
  ) AS event_payload_json,
  COALESCE(s.submitted_at, s.created_at, NOW()) AS created_at
FROM legacy_enrollment_submission_map m
JOIN legacy_enrollment_backfill_stage s
  ON s.legacy_id = m.legacy_id
 AND s.backfill_batch_id = m.backfill_batch_id
LEFT JOIN submission_event se
  ON se.submission_id = m.submission_id
 AND se.event_type = CASE
   WHEN m.submission_status_mapped = 'submitted' THEN 'submitted'
   ELSE 'draft_saved'
 END
 AND JSON_UNQUOTE(JSON_EXTRACT(se.event_payload_json, '$.source')) = 'legacy_backfill'
WHERE se.id IS NULL;

-- Flag rows that still did not map after processing.
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
  'error',
  'UNMAPPED_STAGE_ROW',
  'Staged legacy row did not map to normalized submission during migration 006.'
FROM legacy_enrollment_backfill_stage s
LEFT JOIN legacy_enrollment_submission_map m
  ON m.legacy_id = s.legacy_id
 AND m.backfill_batch_id = s.backfill_batch_id
WHERE m.legacy_id IS NULL
  AND NOT EXISTS (
    SELECT 1
    FROM legacy_enrollment_backfill_issue i
    WHERE i.legacy_id = s.legacy_id
      AND i.backfill_batch_id = s.backfill_batch_id
      AND i.issue_code = 'UNMAPPED_STAGE_ROW'
  );

-- Reconciliation views.
CREATE OR REPLACE VIEW v_backfill_reconciliation_by_batch AS
SELECT
  s.backfill_batch_id,
  COUNT(*) AS staged_rows,
  COUNT(m.legacy_id) AS mapped_rows,
  (COUNT(*) - COUNT(m.legacy_id)) AS unmapped_rows,
  COUNT(DISTINCT m.package_id) AS package_rows,
  COUNT(DISTINCT m.submission_id) AS submission_rows
FROM legacy_enrollment_backfill_stage s
LEFT JOIN legacy_enrollment_submission_map m
  ON m.legacy_id = s.legacy_id
 AND m.backfill_batch_id = s.backfill_batch_id
GROUP BY s.backfill_batch_id;

CREATE OR REPLACE VIEW v_backfill_field_reconciliation_by_batch AS
SELECT
  m.backfill_batch_id,
  COUNT(*) AS staged_kv_rows,
  SUM(CASE WHEN sfv.id IS NOT NULL THEN 1 ELSE 0 END) AS normalized_field_rows,
  SUM(CASE WHEN sfv.id IS NULL THEN 1 ELSE 0 END) AS missing_field_rows
FROM legacy_enrollment_kv_stage kv
JOIN legacy_enrollment_submission_map m
  ON m.legacy_id = kv.legacy_id
 AND m.backfill_batch_id = kv.backfill_batch_id
LEFT JOIN submission_field_value sfv
  ON sfv.submission_id = m.submission_id
 AND sfv.field_name = kv.field_key COLLATE utf8mb4_unicode_ci
GROUP BY m.backfill_batch_id;

UPDATE legacy_enrollment_backfill_run
SET completed_at = CURRENT_TIMESTAMP
WHERE execution_id = @execution_id;

COMMIT;
