# Changelog

All notable changes to this project will be documented in this file.

------------------------------------------------------------------------

## \[v1.5.0\] - 2026-02-20

### 🎯 Scope

Finalized layout, content, and rendering parity for the following
Enrollment sections:

-   **Arrival & Departure Procedure**
-   **Information Sharing, Travel & Photo / Media**

Applied consistently across:

-   Online form
-   Enrollment email body
-   TCPDF PDF attachment

------------------------------------------------------------------------

### ✨ Added

#### Online Form

-   Static policy paragraphs under:
    -   Arrival & Departure Procedure
    -   Information Sharing, Travel & Photo / Media
-   "Disclosure Of Information Policy" subheading
-   Travel Consent Parents Authorization heading + content
-   Photo / Media Release heading
-   Justified paragraph formatting
-   Required inline formatting:
    -   **Bold + underlined** phrases in Arrival section
    -   *Italicized* disclosure lead sentence

#### Email Body + PDF

-   Enrollment-specific layout overrides for:
    -   Arrival & Departure Procedure
    -   Information Sharing, Travel & Photo / Media
-   Static paragraph blocks rendered beneath section headings
-   Nested-table layout control for:
    -   75% / 25% label-value splits
    -   50% / 50% label-value splits
    -   3-column signature blocks
-   Signature rows added (Parent / Guardian, Witness, Date Signed)
-   Section subheadings:
    -   Disclosure Of Information Policy
    -   Travel Consent Parents Authorization
    -   Photo / Media Release

------------------------------------------------------------------------

### 🔁 Changed

-   Consent value mapping logic for improved legal clarity:

  -------------------------------------------------------------------------
  Field         Submitted Value                Displayed Output
  ------------- ------------------------------ ----------------------------
  Information   I consent                      **I consent and agree**
  Sharing                                      

  Travel        I consent                      **I acknowledge and agree**
  Consent                                      

  Travel        I do not consent               **I disagree and do not
  Consent                                      consent**

  Photo / Media I agree to full use as         **I have read, understood
  (full use)    described                      and agree to the above
                                               Release**

  Photo / Media Any non-full-use selection     Long-form legal disclaimer
  (limited / no                                text
  consent)                                     
  -------------------------------------------------------------------------

-   Improved static block handling in the online form:
    -   `type: "static"` now supports `html`
    -   Empty labels suppressed for static blocks
    -   Prevented unknown field types from rendering as empty input
        fields

------------------------------------------------------------------------

### 🛠 Fixed

-   Online wizard initialization crash caused by unintended variable
    reference in `createPostalRow()`
-   Patch drift issues affecting server-side template rendering
-   Regression where:
    -   Policy paragraphs disappeared from email/PDF
    -   Signature rows were missing
    -   Consent values reverted to raw radio selections
-   Ensured:
    -   No inner vertical borders
    -   No double-thick horizontal borders
    -   Layout consistency between email and PDF

------------------------------------------------------------------------

### ✅ Verified

-   Online form loads without JS errors
-   Static paragraphs render correctly (no input fields)
-   Email body matches approved layout reference
-   TCPDF attachment matches approved layout reference
-   Signature rows render consistently
-   Consent mappings display correct legal text
-   No impact to deployment or GitHub Actions

------------------------------------------------------------------------

### 🚀 Deployment

Merged to `main` via standard workflow:

    working branch → develop → main

Staging deployment triggered automatically via GitHub Actions.
