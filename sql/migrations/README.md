# Normalized Schema Migrations (First Pass)

This folder contains first-pass migrations for normalized storage of the three GRASP forms:

- Waitlist (`grasp_waitlist_2025`)
- Enrollment (`grasp_enrollment_2025`)
- Parent Manual (`grasp_parent_manual`)

## Migration Order

Apply in this order:

1. `001_create_submission_package_and_form_submission.sql`
2. `002_create_person_address_child_profile.sql`
3. `003_create_consent_manual_field_event.sql`
4. `004_create_compatibility_views.sql` (optional)

## Notes

- These migrations are additive and do **not** modify or drop legacy `enrollments`.
- They are intended for phased backend adoption:
  1. Create tables
  2. Add dual-write (legacy + normalized)
  3. Backfill historical data
  4. Switch reads/reporting to normalized tables

## Basic Apply Example (MySQL)

```bash
mysql -u <user> -p <db> < sql/migrations/001_create_submission_package_and_form_submission.sql
mysql -u <user> -p <db> < sql/migrations/002_create_person_address_child_profile.sql
mysql -u <user> -p <db> < sql/migrations/003_create_consent_manual_field_event.sql
mysql -u <user> -p <db> < sql/migrations/004_create_compatibility_views.sql
```

## Rollback Guidance

No down-migrations are included in this first pass. For safe rollout:

- apply on staging first,
- snapshot DB before applying,
- if rollback is required immediately, drop newly-created views/tables in reverse dependency order.
