<?php

/**
 * ParentManualPdfGenerator
 *
 * Generates a filled-in PDF copy of the Parent Manual by:
 *  - Rendering each manual page JPG as a PDF page background
 *  - Overlaying field values (initials, printed name, signature, date) using config/parent-manual-fields.json
 *
 * Dependency: TCPDF (installed via Composer in /api)
 */

class ParentManualPdfGenerator
{

    /**
     * Extract fields from config in a backwards/forwards compatible way.
     * Supported shapes:
     *  - cfg['fields'] (flat array)
     *  - cfg['steps'][].fields
     *  - cfg['steps'][].groups[].fields
     */
    private static function extractFields(array $cfg): array
    {
        $out = [];

        // Flat cfg['fields'] (future/alternate shape)
        if (isset($cfg['fields']) && is_array($cfg['fields'])) {
            foreach ($cfg['fields'] as $f) {
                if (is_array($f)) {
                    $out[] = $f;
                }
            }
        }

        // Steps/groups (current project shape)
        if (isset($cfg['steps']) && is_array($cfg['steps'])) {
            foreach ($cfg['steps'] as $step) {
                if (!is_array($step)) {
                    continue;
                }

                if (isset($step['fields']) && is_array($step['fields'])) {
                    foreach ($step['fields'] as $f) {
                        if (is_array($f)) {
                            $out[] = $f;
                        }
                    }
                }

                if (isset($step['groups']) && is_array($step['groups'])) {
                    foreach ($step['groups'] as $group) {
                        if (!is_array($group)) {
                            continue;
                        }
                        if (isset($group['fields']) && is_array($group['fields'])) {
                            foreach ($group['fields'] as $f) {
                                if (is_array($f)) {
                                    $out[] = $f;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param array $submission The decoded JSON submission (expects ['fields'] associative array).
     * @param string $sessionId A stable identifier used for temp file naming.
     * @return array{path:string, filename:string}
     */
    public static function generate(array $submission, string $sessionId): array
    {
        $fields = $submission['fields'] ?? [];
        if (!is_array($fields)) {
            $fields = [];
        }

        $cfgPath = realpath(__DIR__ . '/../../config/parent-manual-fields.json');
        if (!$cfgPath || !file_exists($cfgPath)) {
            throw new RuntimeException('parent-manual-fields.json not found');
        }

        $cfg = json_decode(file_get_contents($cfgPath), true);
        if (!is_array($cfg)) {
            throw new RuntimeException('parent-manual-fields.json is invalid JSON');
        }

        $pageCount = (int)($cfg['manual']['pageCount'] ?? 0);
        if ($pageCount <= 0) {
            throw new RuntimeException('manual.pageCount is missing/invalid');
        }

        $pagesDir = realpath(__DIR__ . '/../../parent-manual-form/assets/pages');
        if (!$pagesDir || !is_dir($pagesDir)) {
            throw new RuntimeException('parent-manual page images folder not found');
        }

        // Collect fields from config.
        // The parent-manual-fields.json in this project stores fields under steps[].groups[].fields,
        // but we also support a flat cfg['fields'] shape for forward compatibility.
        $allFields = self::extractFields($cfg);

        // Build a quick lookup: page => placements[]
        $placementsByPage = [];
        foreach ($allFields as $field) {
            $name = $field['name'] ?? ($field['id'] ?? null);
            if (!$name) {
                continue;
            }

            // Support both { placement: {page, rect} } and { placements: [{page, rect}, ...] }
            $placements = [];
            if (isset($field['placements']) && is_array($field['placements'])) {
                $placements = $field['placements'];
            } elseif (isset($field['placement']) && is_array($field['placement'])) {
                $placements = [ $field['placement'] ];
            }

            foreach ($placements as $pl) {
                if (!is_array($pl)) {
                    continue;
                }
                $p = (int)($pl['page'] ?? 0);
                $rect = $pl['rect'] ?? null;
                if ($p <= 0 || !is_array($rect)) {
                    continue;
                }

                $placementsByPage[$p][] = [
                    'name' => $name,
                    'kind' => ($field['kind'] ?? ($field['type'] ?? 'text')),
                    'officeOnly' => (bool)($field['officeOnly'] ?? false),
                    'rect' => $rect,
                ];
            }
        }

        // Create PDF// Create PDF
        $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->SetCreator('GRASP');
        $pdf->SetAuthor('GRASP');
        $pdf->SetTitle('GRASP Parent Manual (Completed)');
        $pdf->SetSubject('Parent Manual');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetCompression(true);

        // Use a standard font
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(10, 10, 10);

        for ($page = 1; $page <= $pageCount; $page++) {
            $pageFile = sprintf('%s/page-%02d.jpg', $pagesDir, $page);
            if (!file_exists($pageFile)) {
                // If a page image is missing, fail loudly so we don't send a partial manual.
                throw new RuntimeException('Missing manual page image: ' . basename($pageFile));
            }

            $pdf->AddPage('P', 'LETTER');
            $pageW = $pdf->getPageWidth();
            $pageH = $pdf->getPageHeight();

            // Background page image
            $pdf->Image($pageFile, 0, 0, $pageW, $pageH, 'JPG', '', '', false, 300, '', false, false, 0, false, false, false);

            $placements = $placementsByPage[$page] ?? [];
            foreach ($placements as $pl) {
                if (!empty($pl['officeOnly'])) {
                    continue;
                }

                $name = $pl['name'];
                $rect = $pl['rect'];
                if (!is_array($rect)) {
                    continue;
                }
                $val = $fields[$name] ?? '';
                if ($val === null) {
                    $val = '';
                }
                $val = trim((string)$val);
                if ($val === '') {
                    continue;
                }

                // Normalize dates if present
                if (($pl['kind'] ?? '') === 'date') {
                    $val = self::formatDate($val);
                }

                $x = (float)($rect['x'] ?? 0) * $pageW;
                $y = (float)($rect['y'] ?? 0) * $pageH;
                $w = (float)($rect['w'] ?? 0) * $pageW;
                $h = (float)($rect['h'] ?? 0) * $pageH;

                if ($w <= 0 || $h <= 0) {
                    continue;
                }

                // Estimate a font size based on the field box height.
                // TCPDF font size is in points; we derive from mm height.
                $fontSize = max(10.0, min(24.0, $h * 1.6));
                $pdf->SetFont('helvetica', '', $fontSize);

                $align = (($pl['kind'] ?? '') === 'initials') ? 'C' : 'L';
                $paddingX = max(0.8, $w * 0.03);

                if ($align === 'C') {
                    $pdf->MultiCell($w, $h, $val, 0, 'C', false, 1, $x, $y, true, 0, false, true, $h, 'M');
                } else {
                    $pdf->MultiCell($w, $h, $val, 0, 'L', false, 1, $x + $paddingX, $y, true, 0, false, true, $h, 'M');
                }
            }
        }

        $safeSession = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sessionId);
        $filename = 'GRASP-Parent-Manual-' . $safeSession . '.pdf';
        $tmpPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        $pdf->Output($tmpPath, 'F');

        return [
            'path' => $tmpPath,
            'filename' => $filename,
        ];
    }

    /**
     * Attempt to format dates into YYYY-MM-DD (or leave as-is).
     */
    private static function formatDate(string $dateStr): string
    {
        // Common input: YYYY-MM-DD from <input type="date">
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return $dateStr;
        }

        $ts = strtotime($dateStr);
        if ($ts) {
            return date('Y-m-d', $ts);
        }
        return $dateStr;
    }
}
