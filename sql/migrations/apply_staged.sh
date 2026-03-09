#!/usr/bin/env bash
set -euo pipefail

# Staged migration runner for the normalized backend schema.
# Usage:
#   DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=db DB_USER=db DB_PASS=db \
#   ./sql/migrations/apply_staged.sh
#
# Notes:
# - Runs each migration in its own transaction.
# - Records applied files in schema_migration_log for idempotency.
# - Intended for MySQL 8+.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MIGRATIONS_DIR="$ROOT_DIR/sql/migrations"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-db}"
DB_USER="${DB_USER:-db}"
DB_PASS="${DB_PASS:-db}"

MYSQL_ARGS=(
  --host="$DB_HOST"
  --port="$DB_PORT"
  --user="$DB_USER"
  --password="$DB_PASS"
  --database="$DB_NAME"
  --default-character-set=utf8mb4
)

mysql "${MYSQL_ARGS[@]}" <<'SQL'
CREATE TABLE IF NOT EXISTS schema_migration_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  migration_file VARCHAR(255) NOT NULL,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_schema_migration_log_file (migration_file)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL

MIGRATIONS=(
  "001_create_submission_package_and_form_submission.sql"
  "002_create_person_address_child_profile.sql"
  "003_create_consent_manual_field_event.sql"
  "004_create_compatibility_views.sql"
)

legacy_enrollments_exists=$(
  mysql "${MYSQL_ARGS[@]}" --batch --skip-column-names -e "
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'enrollments';
  "
)

if [[ "$legacy_enrollments_exists" == "1" ]]; then
  MIGRATIONS+=(
    "005_stage_legacy_enrollments_backfill.sql"
    "006_backfill_legacy_to_normalized.sql"
  )
else
  echo "Legacy table 'enrollments' not found in '$DB_NAME'; skipping 005/006 backfill migrations."
fi

for file in "${MIGRATIONS[@]}"; do
  migration_path="$MIGRATIONS_DIR/$file"
  if [[ ! -f "$migration_path" ]]; then
    echo "Skipping missing migration: $file"
    continue
  fi

  already_applied=$(
    mysql "${MYSQL_ARGS[@]}" --batch --skip-column-names \
      -e "SELECT COUNT(*) FROM schema_migration_log WHERE migration_file = '$file';"
  )

  if [[ "$already_applied" != "0" ]]; then
    echo "Already applied: $file"
    continue
  fi

  echo "Applying: $file"
  if ! mysql "${MYSQL_ARGS[@]}" < "$migration_path"; then
    echo "ERROR: migration failed: $file" >&2
    exit 1
  fi
  mysql "${MYSQL_ARGS[@]}" -e \
    "INSERT INTO schema_migration_log (migration_file) VALUES ('$file');"

  echo "Applied: $file"
done

echo "Migration apply complete."
