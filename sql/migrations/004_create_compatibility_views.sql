-- 004_create_compatibility_views.sql
-- Optional compatibility views to support reporting / gradual migration from legacy JSON-first storage.

START TRANSACTION;

-- Latest submission per package + form type (by submitted_at then id)
CREATE OR REPLACE VIEW vw_latest_form_submission AS
SELECT fs.*
FROM form_submission fs
JOIN (
  SELECT package_id, form_type,
         MAX(COALESCE(submitted_at, '1970-01-01 00:00:00')) AS max_submitted_at,
         MAX(id) AS max_id
  FROM form_submission
  GROUP BY package_id, form_type
) t
  ON fs.package_id = t.package_id
 AND fs.form_type = t.form_type
 AND fs.id = t.max_id;

-- Package summary with latest statuses per form
CREATE OR REPLACE VIEW vw_package_summary AS
SELECT
  sp.id AS package_id,
  sp.session_id,
  sp.status AS package_status,
  sp.created_at,
  sp.updated_at,
  MAX(CASE WHEN fs.form_type = 'waitlist' THEN fs.submitted_at END) AS waitlist_submitted_at,
  MAX(CASE WHEN fs.form_type = 'enrollment' THEN fs.submitted_at END) AS enrollment_submitted_at,
  MAX(CASE WHEN fs.form_type = 'parent_manual' THEN fs.submitted_at END) AS parent_manual_submitted_at
FROM submission_package sp
LEFT JOIN form_submission fs ON fs.package_id = sp.id AND fs.status = 'submitted'
GROUP BY sp.id, sp.session_id, sp.status, sp.created_at, sp.updated_at;

COMMIT;
