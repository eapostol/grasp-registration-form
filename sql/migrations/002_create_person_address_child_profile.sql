-- 002_create_person_address_child_profile.sql
-- Person/address normalization plus child profile table.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS person (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id BIGINT UNSIGNED NOT NULL,
  role ENUM(
    'child',
    'parent_guardian_1',
    'parent_guardian_2',
    'emergency_contact',
    'doctor',
    'witness',
    'manual_exec_director'
  ) NOT NULL,
  first_name VARCHAR(120) DEFAULT NULL,
  middle_name VARCHAR(120) DEFAULT NULL,
  last_name VARCHAR(120) DEFAULT NULL,
  full_name VARCHAR(255) DEFAULT NULL,
  email VARCHAR(255) DEFAULT NULL,
  phone_primary VARCHAR(64) DEFAULT NULL,
  phone_secondary VARCHAR(64) DEFAULT NULL,
  phone_work VARCHAR(64) DEFAULT NULL,
  relationship_to_child VARCHAR(120) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_person_package_role (package_id, role),
  KEY idx_person_email (email),
  CONSTRAINT fk_person_package
    FOREIGN KEY (package_id) REFERENCES submission_package(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS address (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  person_id BIGINT UNSIGNED NOT NULL,
  address_type ENUM('home', 'work_school', 'doctor', 'emergency_contact') NOT NULL,
  street VARCHAR(255) DEFAULT NULL,
  unit VARCHAR(64) DEFAULT NULL,
  city VARCHAR(120) DEFAULT NULL,
  province VARCHAR(64) DEFAULT NULL,
  postal_code VARCHAR(32) DEFAULT NULL,
  full_address TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_address_person_type (person_id, address_type),
  KEY idx_address_city (city),
  CONSTRAINT fk_address_person
    FOREIGN KEY (person_id) REFERENCES person(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS child_profile (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  package_id BIGINT UNSIGNED NOT NULL,
  child_person_id BIGINT UNSIGNED NOT NULL,
  birth_date DATE DEFAULT NULL,
  gender VARCHAR(32) DEFAULT NULL,
  subsidy_file_number VARCHAR(120) DEFAULT NULL,
  allergies_notes TEXT DEFAULT NULL,
  medical_notes TEXT DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_child_profile_package (package_id),
  UNIQUE KEY uq_child_profile_person (child_person_id),
  CONSTRAINT fk_child_profile_package
    FOREIGN KEY (package_id) REFERENCES submission_package(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_child_profile_person
    FOREIGN KEY (child_person_id) REFERENCES person(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
