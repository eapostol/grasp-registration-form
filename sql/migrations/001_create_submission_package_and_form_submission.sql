-- 001_create_submission_package_and_form_submission.sql
-- Base package + form submission tables for normalized multi-form storage.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS submission_package (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id VARCHAR(64) NOT NULL,
  status ENUM(
    'draft',
    'waitlist_submitted',
    'enrollment_submitted',
    'manual_submitted',
    'completed'
  ) NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_submission_package_session_id (session_id),
  KEY idx_submission_package_status (status),
  KEY idx_submission_package_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_submission (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id BIGINT UNSIGNED NOT NULL,
  form_type ENUM('waitlist', 'enrollment', 'parent_manual') NOT NULL,
  form_version VARCHAR(64) DEFAULT NULL,
  submitted_at DATETIME DEFAULT NULL,
  status ENUM('draft', 'submitted', 'superseded', 'void') NOT NULL DEFAULT 'draft',
  payload_json LONGTEXT NOT NULL,
  email_html LONGTEXT DEFAULT NULL,
  pdf_html LONGTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_form_submission_package (package_id),
  KEY idx_form_submission_form_type (form_type),
  KEY idx_form_submission_status (status),
  KEY idx_form_submission_submitted_at (submitted_at),
  CONSTRAINT fk_form_submission_package
    FOREIGN KEY (package_id) REFERENCES submission_package(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
