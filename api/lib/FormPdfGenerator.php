<?php
// api/lib/FormPdfGenerator.php
//
// Generates a simple, high-quality PDF from the same "quality print" HTML we send in emails.
// This is used for Enrollment + Waitlist so staff can print an attachment that matches the email body.
//
// Dependency: TCPDF (installed via Composer in /api)

class FormPdfGenerator
{
    private const PAGE_BREAK_MARKER = '<!--GRASP_PAGEBREAK-->';
    private const KEEP_START_MARKER = '<!--GRASP_KEEP_TOGETHER_START-->';
    private const KEEP_END_MARKER = '<!--GRASP_KEEP_TOGETHER_END-->';
    private const PAGE1_FIT_START_MARKER = '<!--GRASP_PAGE1_FIT_START-->';
    private const PAGE1_FIT_END_MARKER = '<!--GRASP_PAGE1_FIT_END-->';
    private const PAGE1_MIN_FONT_PT = 8.2;

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

        $profile = isset($opts['profile']) ? (string)$opts['profile'] : '';

        // TCPDF prefers full HTML documents; wrap if caller passed a fragment
        $html = $htmlBody;
        if (stripos($html, '<html') === false) {
            $html = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $htmlBody . '</body></html>';
        }

        // Enrollment-only: adaptively compact page-one section typography until
        // content through "Emergency & Authorized Pickups" fits on page 1.
        if ($profile === 'enrollment') {
            $html = self::adaptEnrollmentFirstPageHtml($title, $html, $opts);
        } else {
            $html = self::stripPageOneFitMarkers($html);
        }

        [$pdf, $bottomMargin] = self::createConfiguredPdf($title, $profile);
        $pdf->AddPage();

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
        $pageBreak = self::PAGE_BREAK_MARKER;
        $keepStart = self::KEEP_START_MARKER;
        $keepEnd   = self::KEEP_END_MARKER;

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

    private static function createConfiguredPdf(string $title, string $profile): array
    {
        $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->SetCreator('GRASP Forms');
        $pdf->SetAuthor('GRASP');
        $pdf->SetTitle($title);
        $pdf->SetSubject($title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

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

        return [$pdf, $bottomMargin];
    }

    private static function splitHtmlBody(string $html): array
    {
        $prefix = '';
        $body = $html;
        $suffix = '';
        if (preg_match('/\A(.*?<body\b[^>]*>)(.*?)(<\/body>.*)\z/is', $html, $m)) {
            $prefix = $m[1];
            $body = $m[2];
            $suffix = $m[3];
        }
        return [$prefix, $body, $suffix];
    }

    private static function stripPageOneFitMarkers(string $html): string
    {
        return str_replace(
            [self::PAGE1_FIT_START_MARKER, self::PAGE1_FIT_END_MARKER],
            '',
            $html
        );
    }

    private static function adaptEnrollmentFirstPageHtml(string $title, string $html, array $opts): string
    {
        if (strpos($html, self::PAGE1_FIT_START_MARKER) === false || strpos($html, self::PAGE1_FIT_END_MARKER) === false) {
            return self::stripPageOneFitMarkers($html);
        }

        $scaleCandidates = self::buildScaleCandidates($opts);
        $best = self::stripPageOneFitMarkers($html);

        foreach ($scaleCandidates as $scale) {
            $candidate = self::applyScaleToPageOneSegment($html, $scale);
            $best = self::stripPageOneFitMarkers($candidate);

            if (self::fitsCandidateOnFirstPage($title, $candidate)) {
                return $best;
            }
        }

        return $best;
    }

    private static function buildScaleCandidates(array $opts): array
    {
        $minScale = 0.90;
        if (isset($opts['enrollmentPageOneMinScale']) && is_numeric($opts['enrollmentPageOneMinScale'])) {
            $minScale = (float)$opts['enrollmentPageOneMinScale'];
        }
        $minScale = max(0.85, min(1.0, $minScale));

        $step = 0.02;
        if (isset($opts['enrollmentPageOneScaleStep']) && is_numeric($opts['enrollmentPageOneScaleStep'])) {
            $step = (float)$opts['enrollmentPageOneScaleStep'];
        }
        $step = max(0.01, min(0.1, $step));

        $scales = [1.0];
        for ($s = 1.0 - $step; $s >= ($minScale - 1e-6); $s -= $step) {
            $scales[] = round($s, 4);
        }
        if (end($scales) > $minScale) {
            $scales[] = $minScale;
        }

        $scales = array_values(array_unique($scales));
        rsort($scales, SORT_NUMERIC);
        return $scales;
    }

    private static function fitsCandidateOnFirstPage(string $title, string $candidateHtml): bool
    {
        [$prefix, $body, $suffix] = self::splitHtmlBody($candidateHtml);
        $endPos = strpos($body, self::PAGE1_FIT_END_MARKER);
        if ($endPos === false) {
            return true;
        }

        $probeBody = substr($body, 0, $endPos);
        $probeBody = str_replace([self::PAGE1_FIT_START_MARKER, self::PAGE_BREAK_MARKER], '', $probeBody);
        if (trim($probeBody) === '') {
            return true;
        }

        $probeHtml = $prefix . $probeBody . $suffix;

        try {
            [$probePdf, ] = self::createConfiguredPdf($title, 'enrollment');
            $probePdf->AddPage();
            $probePdf->writeHTML($probeHtml, true, false, true, false, '');

            $pageNum = (int)$probePdf->getPage();
            $pageCount = method_exists($probePdf, 'getNumPages')
                ? (int)$probePdf->getNumPages()
                : $pageNum;

            return $pageNum <= 1 && $pageCount <= 1;
        } catch (Throwable $e) {
            // Fail-open: if probing errors in restricted environments, keep default layout.
            return true;
        }
    }

    private static function applyScaleToPageOneSegment(string $html, float $scale): string
    {
        if ($scale >= 0.999) {
            return $html;
        }

        [$prefix, $body, $suffix] = self::splitHtmlBody($html);
        $startPos = strpos($body, self::PAGE1_FIT_START_MARKER);
        $endPos = strpos($body, self::PAGE1_FIT_END_MARKER);
        if ($startPos === false || $endPos === false || $endPos <= $startPos) {
            return $html;
        }

        $segmentStart = $startPos + strlen(self::PAGE1_FIT_START_MARKER);
        $before = substr($body, 0, $segmentStart);
        $segment = substr($body, $segmentStart, $endPos - $segmentStart);
        $after = substr($body, $endPos);

        $scaledSegment = self::scaleStyleAttributes($segment, $scale);
        $scaledSegment = preg_replace_callback(
            '/\bcellpadding="([0-9.]+)"/i',
            function (array $m) use ($scale): string {
                $newVal = max(1.0, ((float)$m[1]) * $scale);
                return 'cellpadding="' . self::formatNumber($newVal, 2) . '"';
            },
            $scaledSegment
        );
        if (!is_string($scaledSegment)) {
            $scaledSegment = $segment;
        }

        $scaledSegment = '<div style="font-size:' . self::formatNumber($scale * 100.0, 2) . '%;">'
            . $scaledSegment
            . '</div>';

        return $prefix . $before . $scaledSegment . $after . $suffix;
    }

    private static function scaleStyleAttributes(string $html, float $scale): string
    {
        $scaledHtml = preg_replace_callback(
            '/style="([^"]*)"/i',
            function (array $m) use ($scale): string {
                $style = $m[1];

                $style = preg_replace_callback(
                    '/font-size\s*:\s*([0-9.]+)\s*(pt|px)\b/i',
                    function (array $fm) use ($scale): string {
                        $value = (float)$fm[1];
                        $unit = strtolower($fm[2]);
                        if ($unit === 'pt') {
                            $scaled = max(self::PAGE1_MIN_FONT_PT, $value * $scale);
                        } else {
                            $minPx = self::PAGE1_MIN_FONT_PT * (96.0 / 72.0);
                            $scaled = max($minPx, $value * $scale);
                        }
                        return 'font-size:' . self::formatNumber($scaled, 2) . $unit;
                    },
                    $style
                );

                $style = preg_replace_callback(
                    '/font-size\s*:\s*([0-9.]+)\s*%\b/i',
                    function (array $fm) use ($scale): string {
                        $value = (float)$fm[1];
                        // Percentage font sizes already scale via the wrapper div.
                        // Only raise too-small percentages to preserve the minimum readable floor.
                        $safeScale = max(0.01, $scale);
                        $minPctForScale = (self::PAGE1_MIN_FONT_PT / (10.5 * $safeScale)) * 100.0;
                        $adjusted = max($value, $minPctForScale);
                        return 'font-size:' . self::formatNumber($adjusted, 2) . '%';
                    },
                    $style
                );

                $style = preg_replace_callback(
                    '/\b(padding(?:-(?:top|right|bottom|left))?|margin(?:-(?:top|right|bottom|left))?|height|line-height)\s*:\s*([^;]+)/i',
                    function (array $pm) use ($scale): string {
                        $prop = $pm[1];
                        $value = $pm[2];
                        $scaledValue = self::scaleNumericUnitValues($value, $scale);

                        if (strtolower($prop) === 'line-height' && preg_match('/^\s*([0-9.]+)\s*$/', $scaledValue, $lm)) {
                            $lineHeight = max(1.0, (float)$lm[1] * $scale);
                            $scaledValue = self::formatNumber($lineHeight, 2);
                        }

                        return $prop . ':' . $scaledValue;
                    },
                    $style
                );

                return 'style="' . $style . '"';
            },
            $html
        );
        return is_string($scaledHtml) ? $scaledHtml : $html;
    }

    private static function scaleNumericUnitValues(string $value, float $scale): string
    {
        $scaledValue = preg_replace_callback(
            '/(-?[0-9]+(?:\.[0-9]+)?)(pt|px)\b/i',
            function (array $m) use ($scale): string {
                $scaled = ((float)$m[1]) * $scale;
                return self::formatNumber($scaled, 2) . strtolower($m[2]);
            },
            $value
        );
        return is_string($scaledValue) ? $scaledValue : $value;
    }

    private static function formatNumber(float $value, int $decimals = 2): string
    {
        $formatted = number_format($value, $decimals, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }
}
