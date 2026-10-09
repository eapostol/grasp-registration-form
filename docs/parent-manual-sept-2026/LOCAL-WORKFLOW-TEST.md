# Parent Manual local workflow regression

Run from this repository inside WSL Ubuntu-D, with DDEV running:

```bash
python3 scripts/test_parent_manual_workflow.py
```

Optional evidence directory:

```bash
python3 scripts/test_parent_manual_workflow.py --output /tmp/grasp-parent-manual-review
```

Requirements: Python Playwright, `/usr/bin/google-chrome`, Poppler `pdfinfo`/`pdftotext`, and DDEV. These were available for the verified run. No global browser profile is used. Each run creates a new disposable browser context, generates synthetic data, and leaves a clearly named test message in local Mailpit. No existing messages or user browser drafts are deleted. The local endpoint may create a synthetic database submission if the normalized schema exists; the current DDEV database has no tables. No staging/production requests are permitted by the test.

The site and Mailpit URLs are fixed to `reg-form-project.ddev.site` and port `8026`. Before submission, the script verifies PHP's sendmail command uses Mailpit at `127.0.0.1:1025`. It does not expose a production URL option.

## Checks

- Empty initials show two dashes.
- A fresh browser sees no revision notice. Saved drafts needing acknowledgement trigger a centered native modal with an OK button and keyboard focus. Desktop/mobile screenshots verify its layout; dismissal persists for the active revision without removing the fresh-date requirement.
- Legacy and changed-revision encrypted drafts preserve their originals in encrypted localStorage archives and retain printed names.
- All initials, parent and office signatures/dates, scroll completion and scroll position reset when the manual changes.
- Migrated date stays blank across reload until entered by the parent.
- Initials entered as `q1a` normalize to `QA`; all 16 restore after save/reload against the same manual revision.
- Scrolling remains required, then restores after review and save.
- Preview includes 33 pages and 16 initials; submission remains disabled until complete.
- Browser print button builds the actual print iframe with its styles, pages and initials. `window.print` is intercepted in the disposable context: no print dialog or printer is invoked. This checks print-document composition, not physical printer or browser pagination.
- Server rejects missing/stale revision identifiers with HTTP 409 before writing submissions or sending mail.
- Real local submission succeeds, mail appears in Mailpit, and its PDF attachment contains 33 pages, 16 QA initials and the synthetic parent's name.
- Unhandled browser errors fail the run.

Evidence includes `results.json`, `preview.png`, `browser-print.html`, and the actual attached `completed-manual.pdf`. The verified run used `/home/administrator/grasp-preflight-backups/2026-10-08-parent-manual-workflow-test`. Open `https://reg-form-project.ddev.site:8026/` to inspect the named test message.

## Draft behavior implemented

Draft envelopes now include `manualRevision`. Missing/different revisions trigger migration after the encrypted original is archived. If archiving fails, initialization aborts before the active draft is overwritten. Printed names remain editable. Signatures, dates, initials and scroll flags are cleared; shared enrollment/waitlist drafts and historical server submissions are untouched. The page explains the manual change in a modal. Clicking OK (or dismissing with Escape) saves the dismissed revision; the modal does not recur on reload for that revision. A future manual revision triggers it again when migrating a saved draft. New submissions carry the revision, and the server compares it to the active configuration.

Prior drafts are archived under `graspParentManualArchivedDraft:<sessionId>:<previousRevision>`. They remain encrypted on that device. This implementation does not add an archive-restoration UI or modify previously submitted agreements. Existing tabs running older JavaScript must reload before submitting.

## SSH host identity verification

A read-only scan on 2026-10-08 returned:

`SHA256:B3Ek1JwG1WbeImcmDFRfI2pomY26aKWRQLg7mryu8mA` (ED25519), for `148.113.170.101:27`.

A repeated network scan is not independent identity verification. Ask WHC support through the authenticated client portal to confirm the server's ED25519 SHA-256 SSH host-key fingerprint for this IP/port. Compare the full string exactly. Once WHC confirms it, save the matching public host key in WSL known_hosts and use strict host checking for subsequent SSH. Do not send WHC your private key. Independent verification and pinning remain pending.

## Viewer zoom regression

```bash
python3 scripts/test_parent_manual_zoom.py
```

This separate DDEV-only test uses an isolated browser and sends no email. It checks actual image dimensions after each zoom step, shrinkage, reading anchor, initials overlay alignment and editing, horizontal scrolling, 75%/175% bounds, Reset, reload persistence and responsive resizing. Evidence is saved under `/tmp/grasp-parent-manual-zoom-test`. The full workflow test was also rerun after the zoom fix.

## Drag-to-pan review

The zoom regression now also checks dragging in both axes and diagonally above 100%, stopping on mouse release inside/outside the viewer, and pointer cancellation/lost capture/window blur. It checks document boundaries, disabling at 75%/100%, cancelling on zoom changes, background drag ending over an initials box without activating it, and normal initials editing/save/restoration afterward. A fresh isolated browser is used; no user draft is modified by the tests.

Manual review: zoom above 100%, drag the document background, release and move the mouse to confirm it stops; then click and edit an initials box normally. At 100% or below, mouse dragging should not pan. Scrollbars, wheel/trackpad scrolling and native touch scrolling remain available.
