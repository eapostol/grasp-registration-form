# Changelog

All notable changes to this project will be documented in this file.

------------------------------------------------------------------------

## [v1.6.0] - 2026-02-25

### 🎯 Scope

Finalized **Phase 8E** spacing stabilization for:

- Safe Arrival, Dismissal & Sun Safety (PDF only)

Applied to:

- TCPDF Enrollment attachment

------------------------------------------------------------------------

### ✨ Added

- Controlled pt-based spacing system (6pt–7pt)
- PDF-only inline layout overrides replacing `<p>` blocks
- Bold restoration for “Thank you for your ongoing support and understanding.”

------------------------------------------------------------------------

### 🔁 Changed

- Replaced remaining TCPDF `<p>` blocks with:
  - Inline `<span>` structures
  - Tight `<div>` blocks with controlled line-height
- Tightened spacing before:
  - Thank You line
  - Shade section
  - Smog Alerts section
  - Sunscreen Arrangement table row
- Corrected typo:
  - “miss directed” → “misdirected”

------------------------------------------------------------------------

### 🛠 Fixed

- Overlapping text caused by insufficient spacer height
- Cascading paragraph spacing regressions
- Excess vertical whitespace (~2 line heights) across multiple boundaries
- Border displacement caused by paragraph bottom spacing

------------------------------------------------------------------------

### 📐 Layout Standardization

Established a repeatable spacing control pattern for TCPDF:

- Avoid `<p>` inside PDF policy blocks
- Use `<span>...</span><br>` for inline flow
- Use controlled pt-based spacer divs for predictable vertical rhythm

------------------------------------------------------------------------

### ✅ Verified

- No regression in:
  - Table borders
  - Signature blocks
  - Email body rendering
- Stable spacing across entire Sun Safety section
- Consistent 0.5–1 line height vertical rhythm

------------------------------------------------------------------------

### 🚀 Deployment

Merged via standard workflow:

working → develop → main

GitHub Actions deployment successful.
