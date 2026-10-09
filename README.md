# GRASP Registration Form
Last reviewed: October 9, 2026

## Changes

High-level changes since March 3, 2026, newest first. Dates reflect the commits in [the `main` branch history](https://github.com/eapostol/grasp-registration-form/commits/main/), rather than verified deployment dates. Related fixes are grouped together; earlier entries are retained below.

- **2026-10-09 — September Parent Manual and signing workflow:** Updated the online and downloadable manual to the approved 33-page September edition, realigned initials and signature fields, and added a Safe Arrival acknowledgement. When the manual revision changes, saved names are retained and the previous encrypted draft is archived on the device, while fresh initials, signature, date, and review are required. Submissions from outdated manual tabs are rejected. Improved zoom and reading-position preservation, added mouse drag-to-pan above 100%, and introduced a dismissible revision notice. Hardened manual page generation to validate complete output and restore previous assets if publication fails.
- **2026-06-16 — Consistent waitlist printing:** Changed the waitlist preview's Print action to use a server-generated PDF matching the emailed attachment's layout and metadata, with an HTML print fallback if PDF generation fails.
- **2026-06-15 — Clearer waitlist support information:** Updated the support question and default response, and preserved multiline wording consistently across the online form, preview, print, email, and PDF outputs.
- **2026-06-01 — Downloadable 2026 Parent Manual:** Added the 2026 manual PDF and updated the Forms page's download links and naming to replace the 2024 handbook references.
- **2026-04-21 — Recovery of missing submission attachments:** Added administrative tools to reconstruct missing PDFs from exported enrollment, waitlist, and Parent Manual emails, with optional database recovery and email resend. Included legacy-email support, duplicate-session checks, dry-run and staged recovery instructions, and safeguards against ambiguous command options and duplicate database writes during resends.
- **2026-04-20 — Reliable enrollment output and hosted PDF generation:** Restored the combined parent/guardian layout in enrollment emails and PDFs so heading changes no longer break it. Updated staging and production deployments to install the PHP dependencies required for PDF attachments, and added renderer regression checks.
- **2026-04-16 — Enrollment length limits and predictable PDF pages:** Added text-length limits enforced in the browser and by the preview and submission APIs. Stabilized the enrollment PDF so Emergency & Authorized Pickups ends on page 1 and Medical Release & Medication starts on page 2, with adaptive spacing and a minimum font size.
- **2026-04-15 — More reliable enrollment data capture:** Preserved browser-filled Parent/Guardian 2 work details in preview and submission data, kept copied parent details synchronized when Parent/Guardian 1 changes, and supplied a consistent not-applicable value for a blank work-unit field. Added browser regression coverage for these submission behaviors and refreshed page footer dates across the site.
- **2026-04-15 — Separate staging and production deployments:** Established branch-based deployment workflows (`develop` to staging and `main` to production), versioned release directories, and documented deployment and rollback procedures.
- **2026-04-14 — Single-parent enrollment and clearer guidance:** Added a single-parent/guardian option that marks Parent/Guardian 2 details as not applicable, skips their required validation, and restores entered details when switched back. Improved guardian headings, copied-contact behavior, and postal-code error placement. Added guided interview placeholders and a consistent response for optional interview answers left blank.
- **2026-04-13 — Easier waitlist parent/guardian entry:** Grouped contact fields by guardian, clarified labels and required phone fields, and added visual separation between guardians. Added a single-parent/guardian option that fills Parent/Guardian 2 fields as not applicable, makes them read-only, and adjusts validation.
- **2026-03-09 — Parent Manual delivery and enrollment signatures:** Improved hosted Parent Manual email delivery and reduced rendered page-image sizes to make PDF attachments smaller. Changed Medical Release consent to a required checkbox and corrected fallback enrollment print signatures to use the submitted witness.
- **2026-03-09 — Clearer registration instructions and site content:** Reworked the Forms page to explain the waitlist-to-enrollment sequence and emphasize waiting for GRASP to confirm an available space before submitting enrollment and Parent Manual forms. Clarified online preview and PDF/email alternatives, corrected wording and formatting on informational pages, and added release-listing and rollback scripts.
- **2026-03-08 — Structured submission records:** Added normalized database storage for enrollment, waitlist, and Parent Manual submissions, including people, addresses, child profiles, consents, and manual acknowledgements. Introduced best-effort writes alongside existing submission behavior, plus staged migrations and tools to reconcile and backfill legacy records.
- **2026-03-07 to 2026-03-08 — Enrollment preview and print consistency:** Switched the enrollment preview to server-rendered email content and its Print action to the same PDF-generation path used for attachments, with fallback printing. Corrected static policy rendering so previews and printouts show policy text rather than internal keys or debug placeholders.
- **2026-03-06 to 2026-03-07 — Clearer enrollment consent choices:** Simplified photo/media release to two choices and aligned their display across the form, preview, print, email, and PDF. Converted applicable policy consents, including water play and hand sanitizer, to required acknowledgement checkboxes, and corrected sunscreen selections to display readable labels in previews.

### Earlier changes

- 2026-03-01: Restored and stabilized `contentBlocks` rendering in online form; fixed regression where Medical Release static paragraphs disappeared after JS overwrite; aligned styling to match other static policy blocks.
- 2026-02-28: Implemented online rendering support for static policy `contentBlocks` (Medical Release & Medication) using existing `type: "static"` mechanism for UI consistency.
- 2026-02-27: Removed unintended blank/debug rows (“Test value”) from Email and PDF outputs; added defensive PDF-only cleanup to prevent empty `<div>` emission in Medical Release immunization split logic.
- 2026-02-27: Fixed excessive paragraph spacing in TCPDF for Medical Release immunization section by tightening PDF-only rendering logic (no impact to email or UI).
- 2026-02-26: Added documentation regarding static policy block architecture and cross-layer parity TODO
- 2026-02-15: Updated README with comprehensive project documentation
- 2026-02-10: Implemented client-side validation with error messaging
- 2026-02-05: Added responsive form layout and accessibility features
- 2026-01-28: Initial project setup with tech stack configuration
- 2026-01-20: Established backend API integration structure

---

## Overview
A web-based registration form for GRASP that captures participant details, validates inputs, and submits data to a backend service. The project aims to provide a clean user experience with accessible form components and robust client-side validation.

The system also generates:
- An enrollment confirmation email
- A TCPDF-generated PDF attachment mirroring the enrollment content

---

## Features
- Responsive registration form layout
- Client-side validation with clear error messaging
- Accessible labels and input controls
- Structured data submission to backend API
- Environment-based configuration for endpoints
- Basic form state management and success/failure feedback
- Email confirmation rendering
- TCPDF PDF attachment rendering for enrollment records

---

## Tech Stack
- Frontend: HTML, CSS, JavaScript
- Backend: PHP (API + Email + TCPDF generation)
- PDF Engine: TCPDF
- Build/Tooling: NPM scripts (if present)
- Backend/API: HTTP JSON endpoint (POST)

---

## Getting Started
### Prerequisites
- Node.js LTS and npm installed
- PHP environment (local dev via DDEV or equivalent)

### Installation
- Clone repository
- Run `npm install` (if applicable)

### Configuration
- Set API endpoint and environment variables in a `.env` file or config section
- Ensure PHP mail and TCPDF dependencies are available

### Run
- `npm start` (if front-end dev server is configured)
- Access via configured local development URL
- Submit a test enrollment to verify:
  - Online rendering
  - Email output
  - PDF attachment generation

---

## Usage
1. Fill in all required fields
2. Submit the form to send a JSON payload to the backend
3. Review:
   - Success confirmation
   - Enrollment email
   - PDF attachment
4. Retry or correct invalid inputs as prompted

---

## Development

### Code Style
- Prettier / ESLint (if configured)
- Maintain consistent formatting across JS and PHP

### Folder Structure (simplified)
- `config/` → field definitions (e.g., `enrollment-fields.json`)
- `api/lib/` → Email + PDF generation logic (`EmailPrintTemplate.php`)
- `src/` → frontend assets
- `public/` → static files

---

## Testing
- Unit tests for validation utilities (if present)
- Integration tests for form submission
- Manual verification of:
  - Email layout
  - PDF layout
  - Section parity across UI/email/PDF
- Run tests: `npm test`

---

## Deployment
- Build static assets with `npm run build`
- Serve via static hosting or proxy to backend API
- Ensure environment variables are set for production endpoints
- Configure caching for static files and HTTPS

---

## Security & Privacy
- Validate and sanitize user inputs
- Use HTTPS for all requests
- Avoid logging sensitive data
- Comply with data protection guidelines for registrant information
- Ensure PDF/email outputs do not expose unintended fields

---

# Architecture Notes – Static Policy Blocks

## Rendering Layers

The system renders content in three distinct layers:

1. **Online Form (Frontend)**
   - Driven primarily by `config/enrollment-fields.json`
   - Static policy blocks must be explicitly defined here to appear in the UI.

2. **Email Body (Backend PHP)**
   - Generated by `api/lib/EmailPrintTemplate.php`
   - Some policy blocks are hardcoded in the template.

3. **PDF Attachment (TCPDF via PHP)**
   - Also generated by `api/lib/EmailPrintTemplate.php`
   - May contain hardcoded static HTML sections independent of frontend config.

---

## Known Historical Issue (Feb 2026)

The “TRAVEL CONSENT PARENTS AUTHORIZATION” paragraph:

- Appeared in the Email body
- Appeared in the PDF attachment
- Did NOT appear in the Online form

Reason:
- Email/PDF contained hardcoded static HTML in `EmailPrintTemplate.php`
- Online form rendering is config-driven and did not include that static block

This resulted in cross-layer drift.

The issue was resolved by:
- Adding the missing static policy block to the Online form
- Leaving Email/PDF unchanged (since they were already correct)

---

# TODO – Static Policy Source Unification

To prevent future cross-layer inconsistencies:

## Goal
Create a single source of truth for static policy blocks so that:

- Online form
- Email body
- PDF attachment

all consume the same policy definitions.

## Proposed Approach
Option A:
- Create `config/policies.json`
- Render policy blocks in frontend from this file
- Have PHP load the same definitions for email/PDF

Option B:
- Centralize policy definitions in PHP (`policies.php`)
- Expose to frontend as JSON
- Ensure consistent rendering across layers

---

## Required Parity Checklist (For Future Policy Additions)

Whenever a static policy block is added or updated:

- [ ] Appears in Online Form
- [ ] Appears in Email Body
- [ ] Appears in PDF Attachment
- [ ] Ordering consistent across all three
- [ ] Styling consistent (heading, justification, boxed layout)
- [ ] No duplication introduced
- [ ] No schema changes required (unless intentional)

---

## Contributing
- Fork, create a feature branch, and open a pull request
- Include tests and update documentation for changes
- Follow code review guidelines
- Verify UI/Email/PDF parity before requesting review

---

## License
MIT License unless otherwise specified in the repository

---

## Contact
For questions or support, contact work at edapostol.com