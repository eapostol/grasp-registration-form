-- 003_create_consent_manual_field_event.sql
-- Consent tracking, parent-manual initials, flexible field values, and event audit.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS consent_record (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  submission_id BIGINT UNSIGNED NOT NULL,
  consent_code VARCHAR(120) NOT NULL,
  consent_value TEXT DEFAULT NULL,
  is_agreed TINYINT(1) DEFAULT NULL,
  signed_by_person_id BIGINT UNSIGNED DEFAULT NULL,
  signed_at DATETIME DEFAULT NULL,
  meta_json LONGTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_consent_submission_code (submission_id, consent_code),
  KEY idx_consent_signed_by (signed_by_person_id),
  KEY idx_consent_signed_at (signed_at),
  CONSTRAINT fk_consent_submission
    FOREIGN KEY (submission_id) REFERENCES form_submission(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_consent_signed_by_person
    FOREIGN KEY (signed_by_person_id) REFERENCES person(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manual_initial_ack (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  submission_id BIGINT UNSIGNED NOT NULL,
  ack_code VARCHAR(80) NOT NULL,
  initials_value VARCHAR(20) DEFAULT NULL,
  required_flag TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_manual_ack_submission_code (submission_id, ack_code),
  CONSTRAINT fk_manual_ack_submission
    FOREIGN KEY (submission_id) REFERENCES form_submission(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS submission_field_value (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  submission_id BIGINT UNSIGNED NOT NULL,
  field_name VARCHAR(120) NOT NULL,
  field_type VARCHAR(40) DEFAULT NULL,
  value_text LONGTEXT DEFAULT NULL,
  value_number DECIMAL(20,6) DEFAULT NULL,
  value_date DATE DEFAULT NULL,
  value_bool TINYINT(1) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_submission_field_name (submission_id, field_name),
  KEY idx_submission_field_name (field_name),
  KEY idx_submission_value_date (value_date),
  CONSTRAINT fk_submission_field_submission
    FOREIGN KEY (submission_id) REFERENCES form_submission(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS submission_event (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  submission_id BIGINT UNSIGNED NOT NULL,
  event_type ENUM(
    'draft_saved',
    'preview_generated',
    'submitted',
    'email_sent',
    'pdf_generated',
    'status_changed'
  ) NOT NULL,
  event_payload_json LONGTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_submission_event_submission_type_time (submission_id, event_type, created_at),
  CONSTRAINT fk_submission_event_submission
    FOREIGN KEY (submission_id) REFERENCES form_submission(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
