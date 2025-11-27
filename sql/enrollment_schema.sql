-- MySQL / MariaDB schema for storing GRASP enrollment submissions.
-- Run this once (e.g., via phpMyAdmin or the mysql CLI) on a NEW installation
-- to create the table in a way that matches api/save_draft.php and
-- api/submit_enrollment.php.

CREATE TABLE IF NOT EXISTS enrollments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_id VARCHAR(100) NOT NULL,
  session_id VARCHAR(191) NOT NULL,
  submitted_at DATETIME NOT NULL,
  data_json LONGTEXT NOT NULL,

  -- Tracks whether this row is only a draft or a final submitted enrollment.
  -- Values currently used by the PHP code are 'draft' and 'submitted'.
  status VARCHAR(20) NOT NULL DEFAULT 'draft',

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Last time this row was touched. It will also be updated explicitly
  -- by the PHP code in ON DUPLICATE KEY UPDATE clauses.
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  -- Used for upsert behaviour in the PHP code via ON DUPLICATE KEY UPDATE.
  UNIQUE KEY uq_form_session (form_id, session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If you previously created `enrollments` with the earlier schema
-- (without `status` / `updated_at` or the UNIQUE KEY), you can migrate
-- it roughly like this (run these manually in a MySQL client):
--
--   ALTER TABLE enrollments
--     ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'draft' AFTER data_json,
--     ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
--         ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
--     DROP INDEX idx_form_session,
--     ADD UNIQUE KEY uq_form_session (form_id, session_id);
--
-- Adjust the ALTER TABLE as needed if your existing schema already
-- has similar columns or indexes.
