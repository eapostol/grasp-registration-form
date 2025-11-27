-- MySQL / MariaDB schema for storing GRASP enrollment submissions.
-- Run this once (e.g., via phpMyAdmin) to create the table.

CREATE TABLE IF NOT EXISTS enrollments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_id VARCHAR(100) NOT NULL,
  session_id VARCHAR(191) NOT NULL,
  submitted_at DATETIME NOT NULL,
  data_json LONGTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_form_session (form_id, session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;