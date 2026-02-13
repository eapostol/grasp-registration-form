# AI_CONTEXT (GRASP Registration Forms)

This document gives ChatGPT (and future contributors) the minimum **repo map + workflow context** needed to answer questions and generate safe patches for this project.

---

## Project map (where to look first)

### Public pages / forms (static HTML)
- `index.html` — site home / entry.
- `enrollment-form/index.html` — Enrollment form page.
- `waitlist-form/index.html` — Waitlist form page.
- `parent-manual-form/index.html` — Parent Manual form page.

### Front-end JavaScript (form logic, validation, printing)
- `js/enrollment-app.js` — Enrollment form runtime (load config, render sections, validation, submit).
- `js/waitlist-app.js` — Waitlist form runtime.
- `js/parent-manual-app.js` — Parent Manual form runtime.
- `js/print-templates.js` — Client-side print rendering helpers/templates.
- `js/enrollment-debug.js` — Debug helpers/overlays used during layout/print parity work.
- `js/main.js` — Shared site behaviors (navigation, shared UI, site-wide helpers).

### Field configuration (drives the forms)
- `config/enrollment-fields.json`
- `config/waitlist-fields.json`
- `config/parent-manual-fields.json`

### CSS entry points (screen + print)
- `css/style.css` — Global/site styling used across pages.
- `css/enrollment.css` — Enrollment-specific styling.
- `css/parent-manual.css` — Parent Manual screen styling.
- `css/print.css` — Shared print rules for “Print / Save as PDF” from the browser.
- `css/parent-manual-print.css` — Parent Manual print rules (more specialized).

### Backend / API (submission, email, server-side PDF generation)
- `api/config.php` — Runtime config (endpoints, environment toggles, email settings, etc.).
- `api/submit_enrollment.php` — Handles enrollment submissions.
- `api/submit_waitlist.php` — Handles waitlist submissions.
- `api/submit_parent_manual.php` — Handles parent manual submissions.
- `api/templates/email/*` — Email HTML templates.
- `api/templates/pdf/*` — Server-side PDF templates.
- `api/lib/FormPdfGenerator.php` — TCPDF-driven generator for form PDFs.
- `api/lib/ParentManualPdfGenerator.php` — TCPDF-driven generator for parent manual PDFs.
- `api/lib/EmailPrintTemplate.php` — Shared HTML template helpers used by email/print flows.
- `api/vendor/tecnickcom/tcpdf/*` — TCPDF dependency (PDF rendering).

### Reference PDFs and originals
- `pdf/*` — Current/reference PDFs distributed on the site.
- `originals/*` — Source/reference artifacts used for parity comparisons.

---

## Development + branching workflow (how we ship)

### Branch flow (standard)
1. Create a **feature/fix branch** from `develop` (examples used in this project: `fix/content-fixes`, `dev/pdf-test`, etc.).
2. Open PR: **feature branch → develop** (review + QA on staging).
3. Open PR: **develop → main** (release to production).

### PR template
- A PR template exists in: `/.github/pull_request_template.md`  
  Use it for all PRs so releases are consistent and auditable:
  - Summary
  - Problem
  - Root Cause
  - Changes
  - Test Plan
  - Artifacts (Patch / Changed-files zip)

### Commit style
- Use **Conventional Commits** (e.g., `fix: ...`, `feat: ...`, `docs: ...`, `chore: ...`).

---

## “Gotchas” (things that have repeatedly mattered)

### 1) Subfolder deployments (`/staging`, etc.)
This repo is frequently deployed under a subdirectory (example: `https://…/staging/`).

**Rule of thumb:** Prefer *relative paths* for JS/CSS/config and avoid absolute `/...` paths.

Concrete examples already implemented:
- Form pages include scripts using `../js/...` paths (important for subfolders).
- `js/enrollment-app.js` loads config via a relative fetch:
  - `fetch("../config/enrollment-fields.json")`
  - (Same pattern should be kept for other forms if added/changed.)

If something “works locally but breaks on staging,” check:
- `<script src>` / `<link href>` paths
- `fetch()` URLs
- asset URLs in templates

---

### 2) Debug flags (`?debug=true`) and gated verbose output
During print/layout parity work, the pages were tested with:
- `?debug=true`

Keep debug-only overlays/markers **off by default** and **gated** via querystring and/or a config flag,
so production users don’t see dev-only UI.

If you add new debug helpers, implement them so they:
- do nothing unless `debug=true` is present (or a dedicated flag is enabled), and
- fail safely (no crashes when debug elements are missing).

---

### 3) Two PDF strategies exist (don’t mix them up)
This project has **two** ways PDFs are produced:

**A) Browser Print / Save-as-PDF (client-side)**
- Driven by: `css/print.css`, `css/parent-manual-print.css`, and `js/print-templates.js`
- Used when a user prints the web form from the browser.
- This is where most “layout polish” issues show up (margins, underlines, initials boxes, page breaks).

**B) Server-side PDF generation (TCPDF)**
- Driven by: `api/lib/*PdfGenerator.php` + `api/templates/pdf/*` using TCPDF.
- Produces attached PDFs from the backend.
- Changes here won’t automatically fix the browser-print output (and vice-versa).

When debugging a “PDF looks wrong” issue, first confirm:
- Is it the **browser print preview** PDF?
- Or the **backend-generated** TCPDF PDF?

---

### 4) Print-fidelity is a recurring requirement
Stakeholders care that:
- All content from original PDFs is present in the online forms and generated outputs.
- Filled values are visually distinct (often bold/underlined).
- Initials/signature placement matches the intended boxes.
- Page breaks are stable and consistent.

Expect frequent changes in:
- print CSS, spacing variables, and section templates
- label/value emphasis rules (e.g., adding `<strong>` to match label weight)

---

### 5) Email safety / contact info handling
There has been project work around:
- protecting/obfuscating contact email addresses from scraping (site-wide approach)
- consistent headings/numbering and content parity in documentation

If you touch contact details:
- keep the display user-friendly
- avoid exposing raw mailto links where possible
- verify pages still pass basic UX checks (copy/paste, mobile)

---

### 6) Patches and line endings (Windows/WSL)
This repo is frequently edited on **Windows 11 + WSL2**.
To avoid patch failures:
- keep patch hunks small,
- avoid reformatting unrelated lines,
- be mindful of CRLF/LF conversions (especially in CSS/HTML).

When sharing patches with the team:
- include a “changed-files zip” as a fallback if patch apply fails.

---

## Quick “where do I change X?” cheatsheet

- Field labels, sections, required flags: `config/*.json`
- Form rendering/validation: `js/*-app.js`
- Print layout issues: `css/print.css` and/or `css/parent-manual-print.css`
- Email template markup: `api/templates/email/*`
- Backend PDF markup: `api/templates/pdf/*`
- Backend PDF logic: `api/lib/*PdfGenerator.php`
- Shared site behaviors: `js/main.js` and `css/style.css`

---

## Baseline ZIP convention for AI-assisted work
When starting a new focused thread, attach a new baseline zip (like the current one) so:
- file versions are authoritative,
- patches are based on the exact current state,
- regressions are minimized.

