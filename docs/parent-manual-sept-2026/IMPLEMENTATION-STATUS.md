## Drag-to-pan local candidate - 2026-10-09

User authorized backup -> local implementation -> automated testing -> manual review, with commits and staging deferred. Before editing, current tracked changes were saved as a binary patch, all untracked files were archived, and exact copies of the affected JS/CSS/test/docs files were saved under `/home/administrator/grasp-preflight-backups/2026-10-09-drag-pan-9IXEHb`. The two pre-existing recovery documentation files are protected by a SHA-256 manifest in that directory.

Above 100% zoom, left-mouse dragging on document background now scrolls the viewer in both axes. A four-pixel threshold separates clicks from dragging. Pointer capture retains tracking outside the viewer; release, cancellation, lost capture, window blur or any zoom change stops movement immediately. Native scrollbars are excluded from capture. Initials displays, inputs and other interactive controls are excluded from pan starts. Drag-generated clicks are suppressed, preserving normal subsequent initials editing. Touch keeps native scrolling. Grab/grabbing cursors indicate the mode; drag-to-pan is disabled at 100% and below.

Extended `scripts/test_parent_manual_zoom.py` passed horizontal/vertical/diagonal/reverse movement, release inside/outside, drag ending over an initials field, initials editing and restoration, boundaries, zoom during drag, pointer cancellation, lost capture, blur, and disabling at 75%/100%, alongside existing zoom checks. The complete local workflow through Mailpit was also rerun. Evidence: `/tmp/grasp-parent-manual-zoom-test` and `/home/administrator/grasp-preflight-backups/2026-10-09-drag-pan-workflow-test`. The user should now manually review the feel of dragging on the local page before commits/staging.

Rollback only this candidate by restoring the affected files from the backup's `files/` directory after checking for subsequent edits. Do not reset the working tree or apply the full baseline patch over current files; those contain the wider manual update and unrelated protected documentation edits.

## PDF viewer zoom fix - 2026-10-09

The user authorized the previously deferred zoom repair. CSS zoom on an auto-width wrapper was cancelling page enlargement: both 100% and 110% rendered the first page at 567 pixels wide. The wrapper now has an explicit unzoomed width derived from the viewer, then scales the image and coordinate overlays together. Zoom preserves the source point at the top-left of the viewer, supports horizontal scrolling, updates on resize, disables controls at the existing 75%/175% bounds, and retains Reset and saved zoom. Letter-page aspect ratio reserves space for lazy images to prevent loading shifts.

`python3 scripts/test_parent_manual_zoom.py` passed actual rendered-size growth/shrinkage, reading-position preservation, overlay alignment/editing, horizontal pan, both limits, Reset, persistence and resizing in an isolated browser. Recorded first-page widths at 100/110/120/130%: 567, 623.89, 680.80, 737.69 pixels. Evidence: `/tmp/grasp-parent-manual-zoom-test`. The full local workflow regression through Mailpit also passed with evidence under `/home/administrator/grasp-preflight-backups/2026-10-09-parent-manual-zoom-workflow-test`. No PDF source, placement coordinate or deployment changes were made for this fix.

## Revision notice UI - 2026-10-09

Replaced the narrow toolbar notice with a centered native modal and OK button. It appears only for a saved draft requiring renewed acknowledgements, restores focus to Save Progress on dismissal, and remembers dismissal for the active manual revision. Future revision migration clears that dismissal. Fresh initials/signature/date/scroll requirements remain independent of dismissal. The DDEV-only regression passed, including fresh-browser absence, saved-draft display, desktop/mobile sizing and screenshots, dismissal across reload, and the complete workflow through Mailpit. Evidence: `/home/administrator/grasp-preflight-backups/2026-10-09-parent-manual-modal-test`. No zoom, deployment or historical-submission changes.

## Draft-transition and automated workflow update

The user approved fresh acknowledgements on revision changes. Implemented encrypted draft archival, revision-tagged saves, name preservation, clearing of initials/signatures/dates/scroll completion, a parent-facing explanation, and server rejection of missing/stale revision submissions before database/email actions. See `LOCAL-WORKFLOW-TEST.md` for the repeatable DDEV-only regression and its limits. The isolated automated run passed local migration/restoration, preview, print-frame composition without a print dialog, real submission, and Mailpit PDF attachment checks. Browser-only historical drafts and submitted server agreements remain untouched. No commit, push, PR or deployment occurred; zoom controls remain outside scope.

# Current decisions and draft inventory - 2026-10-08

This section supersedes the historical local-review notes below.

The user approved moving the lower page-16 initials box from Wait List deposit text to the right content margin beside the Safe Arrival & Dismissal heading on page 17, and removing blank page 34. Both website PDFs now use this approved 33-page derivative. The supplied Word/PDF originals remain untouched.

Derived PDF SHA-256: `f7023a990a691dff8595f7aba6e91a6a7107fc192c2485923f1f5742a837e214`.

The moved label retains its embedded original font. All policy words were compared on all retained pages: the sole text changes are removal of one Initials label from page 16 and its addition to page 17. Sixteen initials are now configured, including new `pm_initials_safe_arrival` on page 17; all six signature/acknowledgement fields remain on page 33. Source and completed-proof pages 16/17 were rendered for inspection. The annotated and actual TCPDF proofs were refreshed, with 33 pages and 16 synthetic QA initials. Source/config/image counts and all 22 rectangles were checked.

Read-only draft inventory:

- Local DDEV database: zero tables; no server draft records there.
- WHC production and staging: each has 19 tables. Aggregation of legacy enrollments and normalized form_submission records found only submitted statuses; no rows marked draft in the inspected form tables. Each environment returned 29 submitted Parent Manual records, 46 submitted waitlist records, 33 normalized enrollment submissions, and 32 legacy enrollment submissions. Matching counts do not establish that the two environments have independent databases.
- No names, field contents, session identifiers or credentials were printed. No database data, drafts, deployment or rollback commands were changed/executed.
- Browser-only drafts cannot be enumerated across parents' devices. The previous local UI restored an older date. Direct browser storage inventory was unavailable through the browser tool; no saved browser data was edited or cleared.

Absence of server draft rows does not establish absence of browser drafts. Decide revision-transition behavior before release; the application still restores unversioned draft acknowledgements. This remains the only open item from the three decision questions, plus the remaining interaction/print/submission checks. All changes remain local, uncommitted and undeployed.

## Historical local-review notes (superseded where inconsistent above)

# Parent Manual September 2026: cleaned-source local review

Updated 2026-10-08. This supersedes the earlier source assumptions and selected-section proof. Local review only; deployment is not authorized.

## Source of truth

The user supplied and approved the cleaned Word document and its printed PDF as the content and layout source of truth. Do not reconstruct paragraphs, move source boxes, or reuse the earlier layout corrections.

- DOCX: `D:/projects/2025/GRASP/docs-TODOS/Parent-Manual-WD-2026-Sept-Cleaned.docx`; SHA-256 `a1fabef026e007074f11c42f8a3c8875d089818ed8ba731137a5db4c3ed85ae4`.
- PDF: corresponding `Parent-Manual-WD-2026-Sept-Cleaned.pdf`; SHA-256 `1e1f98fbc2663b7f9410cd0e450b6da0b920305532738d2d32fa7acd0428eaca`.
- Both website PDF copies match that hash exactly. The original editable document remains at the supplied path.
- PDF contains 34 Letter pages, with a blank final page. Cover is separate, Prohibited Practices is present on pages 24-25 without the previous yellow heading fill, Code of Conduct chart has clear spacing on page 31, and all acknowledgement and executive signature lines are together on page 33.

## Repository and recovery

Working branch remains `codex/parent-manual-sept-2026` based on `origin/develop` at `ae5e48d49d7f104a090d00deca9a0f8610d485a5`. Remote heads were refreshed and checked during this update. Local main remains `46b7e5cb13b799a97fa72bd27a2f9735f9ba5fff`; application trees agree. No commit, push, PR, staging or production change occurred.

The two pre-existing recovery documentation edits remain unchanged and uncommitted, verified by their original hashes. Before replacing application assets, the PDFs, page images, config, CSS, JavaScript and PDF generator were archived under `/home/administrator/grasp-preflight-backups/2026-10-08-cleaned-manual-review/baseline/application-before-update.tgz`. The pre-existing patch is saved alongside it. Recovery should selectively restore only this manual update's files from that archive; never reset the whole dirty repository.

## Local implementation

- Replaced both public/application PDFs with the cleaned source, without modifying its pages.
- Rendered all 34 JPEG backgrounds at 110 DPI, quality 82, with the repaired atomic renderer.
- Recalibrated 15 existing initials inputs to the writing area above each printed Initials label, plus all six acknowledgement/signature fields on page 33.
- Coordinates use PDF top-left points divided by page width/height (612/792); initials inset 2 points, reserve the printed label, and acknowledgement writing areas sit immediately above the printed lines.
- Removed previous CSS transforms and large padding nudges from screen and print. Viewer, preview and TCPDF use the same configured rectangles.
- JSON includes explicit manual revision `2026-sept-cleaned-1e1f98fbc266`. The existing draft loader does not enforce it yet.

`field-mapping-cleaned.csv` is the current old/new coordinate inventory. `field-mapping-preliminary.csv` is historical and MUST NOT be used for implementation. Existing IDs remain stable, but page 9 now acknowledges Participation & Communication and page 14 Meal Allergies and Anaphylaxis. These policy changes are not simple pagination shifts. Reversed registration/refund descriptions were corrected to the printed policies. Prior stored initials must not be silently treated as acceptance of the newly associated policies.

## Decisions still pending

1. The PDF has 16 printed initials boxes. Page 16 has emergency communications and TWO Wait List boxes. The lower Wait List box sits beside deposit/placement terms; Safe Arrival starts on page 17 without a box. The previous user interpretation associated the extra box with Safe Arrival. Await confirmation of its policy identity before adding a sixteenth required field. `pending-field.json` records its printed rectangle; it is excluded from runtime and proofs' sample overlays.
2. Retain the authoritative 34-page PDF unchanged for this proof while confirming whether the earlier instruction to remove the blank final page still applies to this new source.
3. Before release, decide saved-draft transition behavior. Drafts lack revision enforcement and can restore old dates, initials and scroll completion. Recommended: explain the changed manual and require fresh acknowledgement/signature/date and scrolling; preserve useful names and the old draft until the user accepts. Shared enrollment/waitlist drafts and historical submissions must remain untouched. No draft migration has been implemented.

## Proofs and validation

Proofs are outside the application under `/home/administrator/grasp-preflight-backups/2026-10-08-cleaned-manual-review/`:

- `GRASP-cleaned-layout-proof.pdf`: complete authoritative vector PDF with synthetic sample overlays and blue field boundaries. Executive fields are outlined but left blank.
- `TCPDF-completed-proof.pdf`: actual existing application generator output using synthetic QA initials and Review Sample values. Executive office-only values remain blank. No submission, email or database mutation was used.

`tools/prepare-parent-manual-layout-proof.py SOURCE CONFIG OUTPUT` generates the annotated proof from source/config, without rebuilding source content. Requires pypdf and reportlab.

Completed checks: renderer regression and rollback checks; 34 sequential nonempty page images; exact PDF source hashes; normalized rectangles inside page bounds; local browser loads all 34 images and preview pages, 15 unshifted initials overlays, parent fields on page 33, office-only fields disabled, and Submit disabled for incomplete fields; actual TCPDF generation succeeds with 34 Letter pages. All completed-PDF pages were visually scanned in contact sheets and representative initials/signature pages were inspected at full size. Old draft restoration was observed, but its values were not edited or cleared. Diff whitespace checks passed.

Still required before release: resolve the three decisions above, verify initials edit/restore and long names across desktop/mobile/zoom, browser print pagination, safe draft migration, Mailpit submission, and waitlist/enrollment smoke checks. Current proof does not claim those checks passed.

## Deployment gate

Continue local review first. Normal route remains working branch -> develop -> GitHub Actions WHC staging -> user's review and approval -> main -> production. Independent WHC SSH fingerprint verification remains pending before deployment. Do not run server deployment/rollback scripts, including dry runs, without later authorization.
