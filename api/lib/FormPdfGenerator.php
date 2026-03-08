<?php
// api/lib/FormPdfGenerator.php
//
// Generates a simple, high-quality PDF from the same "quality print" HTML we send in emails.
// This is used for Enrollment + Waitlist so staff can print an attachment that matches the email body.
//
// Dependency: TCPDF (installed via Composer in /api)

class FormPdfGenerator
{
    /**
     * Generate a PDF from HTML.
     *
     * @param string $title
     * @param string $htmlBody (HTML fragment or full HTML)
     * @param string $filenameBase (no extension)
     * @return array{path:string, filename:string}
     */
    public static function generateFromHtml(string $title, string $htmlBody, string $filenameBase, array $opts = []): array
    {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($autoload)) {
            throw new RuntimeException('Missing Composer dependencies. Run: cd api && composer install');
        }
        require_once $autoload;

        if (!class_exists('TCPDF')) {
            throw new RuntimeException('TCPDF is not available. Ensure composer install completed successfully.');
        }

        $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->SetCreator('GRASP Forms');
        $pdf->SetAuthor('GRASP');
        $pdf->SetTitle($title);
        $pdf->SetSubject($title);

        // No default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Comfortable margins similar to print preview
        // Waitlist-only compaction profile: keep output to one page where possible
        $profile = isset($opts['profile']) ? (string)$opts['profile'] : '';
        if ($profile === 'waitlist') {
            $pdf->SetMargins(10, 8, 10);
            $bottomMargin = 8;
            $pdf->SetAutoPageBreak(true, $bottomMargin);
            $pdf->SetFont('helvetica', '', 9.5);
        } else {
            $pdf->SetMargins(12, 12, 12);
            $bottomMargin = 12;
            $pdf->SetAutoPageBreak(true, $bottomMargin);
            $pdf->SetFont('helvetica', '', 10);
        }

        $pdf->AddPage();

        // TCPDF prefers full HTML documents; wrap if caller passed a fragment
        $html = $htmlBody;
        if (stripos($html, '<html') === false) {
            $html = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $htmlBody . '</body></html>';
        }

        // writeHTML handles simple tables + inline styles well
        // -----------------------------------------------------------------
        // Phase 8: localized PDF pagination helpers
        //
        // We support two explicit HTML markers:
        //
        // 1) Forced page break:
        //    <!--GRASP_PAGEBREAK-->
        //
        // 2) Keep-together blocks (rendered inside a TCPDF transaction):
        //    <!--GRASP_KEEP_TOGETHER_START--> ... <!--GRASP_KEEP_TOGETHER_END-->
        //
        // These markers are intended for PDF-only output in EmailPrintTemplate.
        // They avoid global layout changes and help keep sections readable.
        // -----------------------------------------------------------------
        $pageBreak = '<!--GRASP_PAGEBREAK-->';
        $keepStart = '<!--GRASP_KEEP_TOGETHER_START-->';
        $keepEnd   = '<!--GRASP_KEEP_TOGETHER_END-->';

        // Split into: prefix (<html>..<body>), body, suffix (</body>..</html>)
        $prefix = '';
        $body   = $html;
        $suffix = '';
        if (preg_match('/\A(.*?<body\b[^>]*>)(.*?)(<\/body>.*)\z/is', $html, $m)) {
            $prefix = $m[1];
            $body   = $m[2];
            $suffix = $m[3];
        }

        $writeChunk = function (string $chunkHtml) use ($pdf, $keepStart, $keepEnd) : void {
            // If no keep markers exist, render as-is.
            if (strpos($chunkHtml, $keepStart) === false || strpos($chunkHtml, $keepEnd) === false) {
                $pdf->writeHTML($chunkHtml, true, false, true, false, '');
                return;
            }

            // Render chunk in parts, applying transaction-based keep-together.
            $parts = [];
            $cursor = 0;
            $len = strlen($chunkHtml);
            while ($cursor < $len) {
                $s = strpos($chunkHtml, $keepStart, $cursor);
                if ($s === false) {
                    $parts[] = ['type' => 'normal', 'html' => substr($chunkHtml, $cursor)];
                    break;
                }

                if ($s > $cursor) {
                    $parts[] = ['type' => 'normal', 'html' => substr($chunkHtml, $cursor, $s - $cursor)];
                }

                $e = strpos($chunkHtml, $keepEnd, $s);
                if ($e === false) {
                    // Unbalanced marker: render remainder normally.
                    $parts[] = ['type' => 'normal', 'html' => substr($chunkHtml, $s)];
                    break;
                }

                $block = substr($chunkHtml, $s + strlen($keepStart), $e - ($s + strlen($keepStart)));
                $parts[] = ['type' => 'keep', 'html' => $block];
                $cursor = $e + strlen($keepEnd);
            }

            foreach ($parts as $p) {
                if ($p['type'] === 'normal') {
                    if (trim($p['html']) !== '') {
                        $pdf->writeHTML($p['html'], true, false, true, false, '');
                    }
                    continue;
                }

                // Keep-together render in a transaction.
                $pdf->startTransaction();
                $startPage = $pdf->getPage();
                $startY = $pdf->GetY();

                $pdf->writeHTML($p['html'], true, false, true, false, '');

                $endPage = $pdf->getPage();
                if ($endPage > $startPage) {
                    // It overflowed; rollback and re-render on a new page.
                    $pdf->rollbackTransaction(true);
                    if ($pdf->getPage() !== $startPage) {
                        $pdf->setPage($startPage);
                    }
                    $pdf->SetY($startY);
                    $pdf->AddPage();
                    $pdf->writeHTML($p['html'], true, false, true, false, '');
                } else {
                    $pdf->commitTransaction();
                }
            }
        };

        // Render body, honoring page break markers between complete HTML segments.
        // IMPORTANT: Only add a new page if there's actually more content ahead.
        // Trailing/leading pagebreak markers can otherwise create blank pages and shift numbering.
        $chunks = explode($pageBreak, $body);
        $chunkCount = count($chunks);
        for ($i = 0; $i < $chunkCount; $i++) {
            $chunk = $chunks[$i];
            if (trim($chunk) !== '') {
                $writeChunk($prefix . $chunk . $suffix);
            }

            if ($i < $chunkCount - 1) {
                $hasMoreContent = false;
                for ($j = $i + 1; $j < $chunkCount; $j++) {
                    if (trim($chunks[$j]) !== '') {
                        $hasMoreContent = true;
                        break;
                    }
                }
                if ($hasMoreContent) {
                    $pdf->AddPage();
                }
            }
        }

        // Phase 9F (Global): stable page numbering without enabling TCPDF's footer.
        // We stamp numbers AFTER all pages are generated to avoid footer-related attachment regressions.
        // Placement: bottom-right on every page.
        try {
            $pageCount = method_exists($pdf, 'getNumPages') ? (int)$pdf->getNumPages() : 0;
            if ($pageCount > 0) {
                $currentPage = method_exists($pdf, 'getPage') ? (int)$pdf->getPage() : 1;
                $margins = method_exists($pdf, 'getMargins')
                    ? (array)$pdf->getMargins()
                    : ['left' => 0, 'right' => 0, 'top' => 0, 'bottom' => 0];
                $pageHeight = method_exists($pdf, 'getPageHeight') ? (float)$pdf->getPageHeight() : 0.0;

                // Place within the printable area, just above the bottom margin.
                // Important: if Y is >= (pageHeight - bottomMargin), TCPDF may auto page-break and
                // the number will appear on the NEXT page (which is why it looked like numbering started on page 2).
                $cellH = 6.0;
                $y = ($pageHeight > 0)
                    ? max(0.0, $pageHeight - (float)$bottomMargin - $cellH)
                    : 0.0;

                // Stamp without triggering automatic page breaks.
                $prevAuto = method_exists($pdf, 'getAutoPageBreak') ? (bool)$pdf->getAutoPageBreak() : true;
                $prevBMargin = method_exists($pdf, 'getBreakMargin') ? (float)$pdf->getBreakMargin() : (float)$bottomMargin;
                $pdf->SetAutoPageBreak(false, 0);

                // Add a small inset from the right border (~2 characters).
                $rightInsetMm = 4.0;
                $prevRightMargin = isset($margins['right']) ? (float)$margins['right'] : 0.0;
                if (method_exists($pdf, 'SetRightMargin')) {
                    $pdf->SetRightMargin($prevRightMargin + $rightInsetMm);
                }

                for ($p = 1; $p <= $pageCount; $p++) {
                    $pdf->setPage($p);
                    // Consistent 75–80% of body text (~10pt): use 8pt
                    $pdf->SetFont('helvetica', '', 8);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->SetXY((float)$margins['left'], $y);
                    $pdf->Cell(0, $cellH, 'Page ' . $p . ' of ' . $pageCount, 0, 0, 'R', false, '', 0, false, 'T', 'M');
                }

                // Restore margins / auto page break.
                if (method_exists($pdf, 'SetRightMargin')) {
                    $pdf->SetRightMargin($prevRightMargin);
                }
                $pdf->SetAutoPageBreak($prevAuto, $prevBMargin);

                // Restore current page (non-fatal if it fails).
                if ($currentPage > 0 && $currentPage <= $pageCount) {
                    $pdf->setPage($currentPage);
                }
            }
        } catch (Throwable $e) {
            // Non-fatal: never block attachment generation for page numbering.
        }

        $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filenameBase);
        $safeBase = trim($safeBase, '-');
        if ($safeBase === '') $safeBase = 'GRASP-Form';

        $filename = $safeBase . '.pdf';

        // Allow caller to override temp output directory (useful in restricted hosting environments).
        $tmpDir = '';
        if (!empty($opts['tmpDir']) && is_string($opts['tmpDir'])) {
            $tmpDir = trim($opts['tmpDir']);
        }
        if ($tmpDir === '') {
            $tmpDir = sys_get_temp_dir();
        }
        $tmpPath = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        $pdf->Output($tmpPath, 'F');

        if (!file_exists($tmpPath) || filesize($tmpPath) < 1000) {
            throw new RuntimeException('PDF generation failed (empty output).');
        }

        return ['path' => $tmpPath, 'filename' => $filename];
    }
}
