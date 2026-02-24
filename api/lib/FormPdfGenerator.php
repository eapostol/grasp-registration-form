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
        // Phase 8: PDF keep-together blocks (localized, PDF-only)
        //
        // Some sections contain long policy paragraphs. If a section header or the
        // first content block lands at the end of a page, TCPDF can split the block
        // awkwardly. We support explicit keep-together markers in the HTML:
        //
        //   <!--GRASP_KEEP_TOGETHER_START--> ... <!--GRASP_KEEP_TOGETHER_END-->
        //
        // For marked blocks, we render within a TCPDF transaction. If the block
        // would overflow onto a new page, we roll back, add a page, and re-render
        // the block so it starts cleanly on the next page.
        // -----------------------------------------------------------------
        $keepStart = '<!--GRASP_KEEP_TOGETHER_START-->';
        $keepEnd   = '<!--GRASP_KEEP_TOGETHER_END-->';
        
        // If no markers exist, keep existing behavior.
        if (strpos($html, $keepStart) === false || strpos($html, $keepEnd) === false) {
            $pdf->writeHTML($html, true, false, true, false, '');
        } else {
            // Split into: prefix (<html>..<body>), body, suffix (</body>..</html>)
            $prefix = '';
            $body   = $html;
            $suffix = '';
            if (preg_match('/\A(.*?<body\b[^>]*>)(.*?)(<\/body>.*)\z/is', $html, $m)) {
                $prefix = $m[1];
                $body   = $m[2];
                $suffix = $m[3];
            }
        
            $segments = [];
            $cursor = 0;
            $len = strlen($body);
        
            while ($cursor < $len) {
                $startPos = strpos($body, $keepStart, $cursor);
                if ($startPos === false) {
                    // remainder is normal
                    $segments[] = ['keep' => false, 'html' => substr($body, $cursor)];
                    break;
                }
        
                // normal chunk before keep block
                if ($startPos > $cursor) {
                    $segments[] = ['keep' => false, 'html' => substr($body, $cursor, $startPos - $cursor)];
                }
        
                $endPos = strpos($body, $keepEnd, $startPos + strlen($keepStart));
                if ($endPos === false) {
                    // malformed; treat the rest as normal
                    $segments[] = ['keep' => false, 'html' => substr($body, $startPos)];
                    break;
                }
        
                $innerStart = $startPos + strlen($keepStart);
                $innerLen   = $endPos - $innerStart;
                $keepHtml   = substr($body, $innerStart, $innerLen);
        
                $segments[] = ['keep' => true, 'html' => $keepHtml];
        
                $cursor = $endPos + strlen($keepEnd);
            }
        
            // Render segments
            foreach ($segments as $seg) {
                $segHtml = (string)($seg['html'] ?? '');
                if (trim($segHtml) === '') continue;
        
                $doc = ($prefix !== '' || $suffix !== '')
                    ? ($prefix . $segHtml . $suffix)
                    : $segHtml;
        
                if (!empty($seg['keep'])) {
                    // Use transactions only if available.
                    if (method_exists($pdf, 'startTransaction') && method_exists($pdf, 'rollbackTransaction')) {
                        $startPage = $pdf->getPage();
                        $startY    = $pdf->GetY();
        
                        $pdf->startTransaction();
                        $pdf->writeHTML($doc, true, false, true, false, '');
        
                        $endPage = $pdf->getPage();
                        if ($endPage > $startPage) {
                            // It overflowed -> roll back and re-render from a fresh page.
                            $pdf->rollbackTransaction(true);
        
                            // If we're already near the top, avoid adding a blank page.
                            if ($startY > 15) {
                                $pdf->AddPage();
                            }
                            $pdf->writeHTML($doc, true, false, true, false, '');
                        } else {
                            // Keep the rendered content.
                            if (method_exists($pdf, 'commitTransaction')) {
                                $pdf->commitTransaction();
                            }
                        }
                    } else {
                        // Fallback: render without keep-together.
                        $pdf->writeHTML($doc, true, false, true, false, '');
                    }
                } else {
                    $pdf->writeHTML($doc, true, false, true, false, '');
                }
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
