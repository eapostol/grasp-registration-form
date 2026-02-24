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
            $pdf->SetAutoPageBreak(true, 8);
            $pdf->SetFont('helvetica', '', 9.5);
        } else {
            $pdf->SetMargins(12, 12, 12);
            $pdf->SetAutoPageBreak(true, 12);
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
        $chunks = explode($pageBreak, $body);
        $chunkCount = count($chunks);
        for ($i = 0; $i < $chunkCount; $i++) {
            $chunk = $chunks[$i];
            if (trim($chunk) !== '') {
                $writeChunk($prefix . $chunk . $suffix);
            }
            if ($i < $chunkCount - 1) {
                $pdf->AddPage();
            }
        }

        $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filenameBase);
        $safeBase = trim($safeBase, '-');
        if ($safeBase === '') $safeBase = 'GRASP-Form';

        $filename = $safeBase . '.pdf';
        $tmpPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        $pdf->Output($tmpPath, 'F');

        if (!file_exists($tmpPath) || filesize($tmpPath) < 1000) {
            throw new RuntimeException('PDF generation failed (empty output).');
        }

        return ['path' => $tmpPath, 'filename' => $filename];
    }
}
