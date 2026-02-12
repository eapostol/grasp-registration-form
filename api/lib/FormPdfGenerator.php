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
            $pdf->SetMargins(10, 10, 10);
            $pdf->SetAutoPageBreak(true, 10);
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
        $pdf->writeHTML($html, true, false, true, false, '');

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
