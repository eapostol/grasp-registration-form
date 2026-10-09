# Parent Manual / Handbook Agreement

This form displays the Parent Manual as a continuous-scroll document using **page images** generated from the source PDF.

## Editing the manual text (e.g., fixing “2019” -> “2026”)

Because the document is rendered from images, you **edit the PDF**, then re-render the page images.

1. Update the source PDF at:
   `parent-manual-form/assets/GRASP-parent-manual-2026.pdf`

2. Re-render the page images (WSL/Linux):
   ```bash
   sudo apt-get update && sudo apt-get install -y poppler-utils
   bash tools/render-parent-manual-pages.sh parent-manual-form/assets/GRASP-parent-manual-2026.pdf 110 82
   ```

That script outputs `page-01.jpg`, `page-02.jpg`, ... into `parent-manual-form/assets/pages/` and updates `config/parent-manual-fields.json` with the correct `manual.pageCount`.

## Why not store the manual text in JSON?

To generate a PDF-like document from JSON/Markdown, you would need a full **layout engine** (pagination, fonts, line breaks, headers/footers). That is possible, but it becomes a different “HTML manual” product rather than a faithful rendering of the PDF.

If we want a more readable, mobile-friendly version later, we can add an optional **Readable Mode** that renders the content from Markdown/JSON — while keeping the PDF-fidelity view for printing.

## September 2026 cleaned-source review

Current local candidate uses the user-supplied cleaned PDF with approved Safe Arrival relocation and blank-page removal (33 pages) and normalized fields measured from that layout. Update both the source above and `pdf/GRASP-parent-manual-2026.pdf`. The renderer changes page count, not placement coordinates; recalibrate those independently and remove obsolete CSS nudges.

See `docs/parent-manual-sept-2026/IMPLEMENTATION-STATUS.md` and `field-mapping-cleaned.csv` for source hashes, recovery, proof generation, completed checks and open decisions. The Safe Arrival box and blank final page decisions are implemented. Saved-draft revision handling and remaining interaction tests must be resolved before release.
