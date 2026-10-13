# September 2026 Parent Manual v22 release

The October 13, 2026 release uses the user-reviewed v22 PDF without modifying
its wording or layout. Both `pdf/GRASP-parent-manual-2026.pdf` and
`parent-manual-form/assets/GRASP-parent-manual-2026.pdf` contain the same source.

Source SHA-256: `3be31968196aa616ea08a7b279a1e04db2256abbd4418c7d80bcdb5c10a8a534`.

## Pagination and signing

- 35 physical pages: three unnumbered front-matter pages and manual pages 1–32.
- All 56 saved Word contents references match their bookmark destinations after
  read-only native Word repagination. PDF contents numbers and destinations agree.
- The acknowledgement is physical page 35; there is no trailing blank page.
- All 35 JPG backgrounds were regenerated at 110 dpi and JPEG quality 82.
- Seventeen initials are required: all 16 printed v22 boxes plus the existing
  Safe Arrival acknowledgement at its heading, as explicitly approved by the user.
- Existing field IDs are preserved. The extra wait-list deposit/withdrawal box
  uses `pm_initials_waitlist_deposit`.
- The existing `pm_initials_p22_01` description now identifies telephone/address/
  employment changes, matching its printed policy rather than the former
  inaccurate “Accidents” description.
- Parent and office-use fields are aligned with the acknowledgement on page 35.
  Office-use fields remain disabled for parents.
- The new revision requires fresh initials/signature/date and review. Existing
  names are retained and previous encrypted drafts archived by the existing flow.
- Revision query parameters refresh images and both linked PDF downloads.

## Validation before publication

The existing DDEV-only workflow regression passed draft migration, stale-tab
rejection, editing, save/reload, scroll requirements, preview, print composition,
actual submission, and a Mailpit-captured 35-page PDF with all 17 initials.
The completed PDF's initials and signature positions were visually checked.
The zoom/pan regression and page-renderer publication/rollback regression passed.
Both downloadable PDFs match the authoritative source hash. Source Word and PDF
files were not edited. This is not a complete proofread or policy compliance review.

Deploy through the existing `develop` staging and `main` production workflows,
which preserve hosted backups. Verify both PDF hashes, the live configuration,
all 35 page images, and the rendered form after each deployment.
