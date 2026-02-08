<?php
// api/lib/EmailPrintTemplate.php
//
// Gmail-safe, "PDF-like" HTML email renderer for GRASP forms.
// Uses table-based layout + inline CSS so recipients can Ctrl/Cmd-P from Gmail.
//
// 2026-02-07

class EmailPrintTemplate
{
    /**
     * Render a form email using a config JSON (config/*-fields.json) and submitted data.
     *
     * @param string $configPath Absolute path to the JSON config file
     * @param array  $data       Submitted data (keyed by field id)
     * @param array  $meta       Optional metadata: ['submittedAt' => ..., 'formTitle' => ..., 'subjectPrefix' => ...]
     * @return string HTML string (no <html> wrapper; caller may wrap)
     */
    public static function renderFromConfig(string $configPath, array $data, array $meta = []): string
    {
        return self::renderInternal($configPath, $data, $meta, 'email');
    }

    /**
     * Render a PDF-friendly HTML variant intended for TCPDF::writeHTML().
     * Same content as the email body, but with styles that TCPDF renders more faithfully.
     */
    public static function renderPdfFromConfig(string $configPath, array $data, array $meta = []): string
    {
        return self::renderInternal($configPath, $data, $meta, 'pdf');
    }

    /**
     * Shared renderer (target = 'email' | 'pdf').
     */
    private static function renderInternal(string $configPath, array $data, array $meta, string $target): string
    {
        $cfg = self::loadJson($configPath);

        $title = (string)($meta['formTitle'] ?? ($cfg['title'] ?? 'GRASP Form Submission'));
        $submittedAt = (string)($meta['submittedAt'] ?? '');
        if ($submittedAt === '') {
            // Use server time as a fallback (Toronto timezone is handled by host PHP config)
            $submittedAt = date('Y-m-d H:i:s');
        }

        $isPdf = ($target === 'pdf');

        // NOTE: Gmail and TCPDF have different HTML/CSS support.
        // - Email: table layout + inline CSS, optimized for Gmail printing.
        // - PDF (TCPDF): prefers cellpadding/width attributes and thin borders in points.
        $containerStyle = $isPdf
            ? 'margin:0 auto;max-width:760px;font-family:Arial,Helvetica,sans-serif;color:#111;font-size:11pt;line-height:1.35;'
            : 'margin:0 auto;max-width:760px;font-family:Arial,Helvetica,sans-serif;color:#111;';
        $h1Style = $isPdf
            ? 'margin:0 0 6px 0;font-size:16pt;line-height:20pt;'
            : 'margin:0 0 6px 0;font-size:18px;line-height:22px;';
        $smallStyle = $isPdf
            ? 'margin:0 0 12px 0;font-size:10.5pt;line-height:14.5pt;color:#444;'
            : 'margin:0 0 14px 0;font-size:12px;line-height:16px;color:#444;';
        $sectionTitleStyle = $isPdf
            ? 'margin:14px 0 6px 0;padding:7px 9px;background:#f2f2f2;border:0.5pt solid #333;font-weight:bold;font-size:10.5pt;'
            : 'margin:16px 0 6px 0;padding:8px 10px;background:#f2f2f2;border:1px solid #333;font-weight:bold;font-size:13px;';
        $tableStyle = $isPdf
            ? 'width:100%;border-collapse:collapse;border:0.5pt solid #333;'
            : 'width:100%;border-collapse:collapse;border:1px solid #333;';
        $thStyle = $isPdf
            ? 'text-align:left;vertical-align:top;width:38%;border:0.5pt solid #333;background:#fafafa;font-size:10.5pt;'
            : 'text-align:left;vertical-align:top;width:38%;padding:7px 8px;border:1px solid #333;background:#fafafa;font-size:13px;';
        $tdStyle = $isPdf
            ? 'text-align:left;vertical-align:top;width:62%;border:0.5pt solid #333;font-size:10.5pt;'
            : 'text-align:left;vertical-align:top;width:62%;padding:7px 8px;border:1px solid #333;font-size:13px;';

        $html = '';
        $html .= '<div style="' . $containerStyle . '">';
        $html .= '<div style="' . ($isPdf ? 'border:0.5pt solid #333;' : 'border:1px solid #333;') . 'padding:12px 12px 6px 12px;">';
        $html .= '<div style="' . $h1Style . '">' . self::esc($title) . '</div>';
        $html .= '<div style="' . $smallStyle . '">Submitted: ' . self::esc($submittedAt) . '</div>';
        $html .= '</div>';

        $steps = $cfg['steps'] ?? [];
        if (!is_array($steps) || count($steps) === 0) {
            // Fallback: dump the data in a readable way
            $html .= self::renderFallbackDataTable($data, $isPdf, $sectionTitleStyle, $tableStyle, $thStyle, $tdStyle);
            $html .= '</div>';
            return $html;
        }

        foreach ($steps as $step) {
            if (!is_array($step)) continue;

            $groups = $step['groups'] ?? [];
            if (!is_array($groups)) continue;

            foreach ($groups as $group) {
                if (!is_array($group)) continue;

                $groupTitle = (string)($group['title'] ?? 'Section');
                $fields = $group['fields'] ?? [];
                if (!is_array($fields) || count($fields) === 0) continue;

                $html .= '<div style="' . $sectionTitleStyle . '">' . self::esc($groupTitle) . '</div>';
                $html .= '<table role="presentation" cellpadding="' . ($isPdf ? '6' : '0') . '" cellspacing="0" style="' . $tableStyle . '">';

                // Pre-scan group fields for display rules (postal-code parts, etc.)
                $skipKeys = [];
                $fullPostalKeys = [];
                foreach ($fields as $f) {
                    if (!is_array($f)) continue;
                    $k = (string)($f['name'] ?? ($f['id'] ?? ($f['key'] ?? '')));
                    if ($k === '') continue;

                    // If any "full postal code" field is present and populated, hide the two-part postal fields.
                    if (preg_match('/_postal_code$/', $k)) {
                        $v = $data[$k] ?? '';
                        $v = is_scalar($v) ? trim((string)$v) : '';
                        if ($v !== '') $fullPostalKeys[] = $k;
                    }
                }
                if (count($fullPostalKeys) > 0) {
                    foreach ($fields as $f) {
                        if (!is_array($f)) continue;
                        $k = (string)($f['name'] ?? ($f['id'] ?? ($f['key'] ?? '')));
                        if ($k === '') continue;
                        if (preg_match('/_postal1$/', $k) || preg_match('/_postal2$/', $k)) {
                            $skipKeys[$k] = true;
                        }
                    }
                }

                foreach ($fields as $field) {
                    if (!is_array($field)) continue;

                    $key = (string)($field['name'] ?? ($field['id'] ?? ($field['key'] ?? '')));
                    if ($key === '' || isset($skipKeys[$key])) continue;

                    $label = (string)($field['label'] ?? $key);

                    // Improve labels for derived fields
                    $label = preg_replace('/\s*\(full, derived\)\s*/i', '', $label);
                    $label = preg_replace('/\s*\(second part: 1A1\)\s*/i', '', $label);
                    $label = preg_replace('/\s*\(first part: A1A\)\s*/i', '', $label);
                    $label = preg_replace('/\s*\(optional\)\s*/i', ' (optional)', $label);

                    // Parent Manual initials: include the handbook section title so initials are meaningful.
                    if (($field['kind'] ?? '') === 'initials' && isset($field['sectionTitle'])) {
                        $page = (int)(($field['placement']['page'] ?? 0));
                        $section = trim((string)$field['sectionTitle']);
                        if ($section !== '') {
                            $prefix = ($page > 0) ? ('p' . str_pad((string)$page, 2, '0', STR_PAD_LEFT) . ' — ') : '';
                            $label = $prefix . $section;
                        }
                    }

                    $rawValue = $data[$key] ?? '';
                    $value = self::formatValue($rawValue, $field);

                    $display = ($value === '') ? '&nbsp;' : self::esc($value);

                    $html .= '<tr>';
                    $html .= '<td ' . ($isPdf ? 'width="38%"' : '') . ' style="' . $thStyle . '">' . self::esc($label) . '</td>';
                    $html .= '<td ' . ($isPdf ? 'width="62%"' : '') . ' style="' . $tdStyle . '">' . $display . '</td>';
                    $html .= '</tr>';
                }

                $html .= '</table>';
            }
        }

        if (!$isPdf) {
            $html .= '<div style="margin:16px 0 0 0;font-size:11px;line-height:15px;color:#555;">';
            $html .= 'Printed from Gmail: open the email and use Ctrl/Cmd-P. If a PDF is attached, you may print that instead for exact formatting.';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }
/**
     * Render a Parent Manual email body (concise, but still "PDF-like") and mention the attached PDF.
     */
    public static function renderParentManualWithAttachmentNotice(string $configPath, array $data, array $meta = []): string
    {
        $title = (string)($meta['formTitle'] ?? 'GRASP Parent Manual Agreement');
        $submittedAt = (string)($meta['submittedAt'] ?? '');
        if ($submittedAt === '') $submittedAt = date('Y-m-d H:i:s');

        $intro = '<div style="margin:10px 0 14px 0;font-size:13px;line-height:18px;">'
            . 'A filled PDF copy of the Parent Manual is attached to this email for record-keeping.'
            . '</div>';

        $body = self::renderFromConfig($configPath, $data, ['formTitle' => $title, 'submittedAt' => $submittedAt]);

        // Insert intro after the header box (first closing </div> after header container)
        $pos = strpos($body, '</div>', 0);
        if ($pos !== false) {
            // header wrapper ends at first </div> (header box). Add intro right after.
            $pos2 = strpos($body, '</div>', $pos + 6);
            if ($pos2 !== false) {
                $insertAt = $pos2 + 6;
                $body = substr($body, 0, $insertAt) . $intro . substr($body, $insertAt);
            }
        }

        return $body;
    }

    private static function loadJson(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) return [];
        $cfg = json_decode($raw, true);
        return is_array($cfg) ? $cfg : [];
    }

    private static function formatValue($rawValue, array $field): string
    {
        if ($rawValue === null) return '';

        // If a field is a checkbox list, value may be an array
        if (is_array($rawValue)) {
            // Some configs store checkbox results as { optionId: true/false } objects
            $isAssoc = array_keys($rawValue) !== range(0, count($rawValue) - 1);
            if ($isAssoc) {
                $picked = [];
                foreach ($rawValue as $k => $v) {
                    if ($v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on' || $v === 'yes') {
                        $picked[] = (string)$k;
                    }
                }
                return trim(implode(', ', $picked));
            }
            $parts = array_map(function ($v) {
                if (is_scalar($v)) return (string)$v;
                return json_encode($v);
            }, $rawValue);
            return trim(implode(', ', $parts));
        }

        if (is_bool($rawValue)) return $rawValue ? 'Yes' : 'No';

        // Numbers / strings
        $s = (string)$rawValue;
        // Normalize whitespace
        $s = preg_replace('/\s+/', ' ', trim($s));

        // Date formatting: prefer unambiguous, human-friendly dates
        // If value is ISO date (YYYY-MM-DD), render as "Month D, YYYY".
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            try {
                $dt = new DateTime($s);
                $s = $dt->format('F j, Y');
            } catch (Exception $e) {
                // leave as-is
            }
        }

        return $s;
    }

    private static function renderFallbackDataTable(array $data, bool $isPdf, string $sectionTitleStyle, string $tableStyle, string $thStyle, string $tdStyle): string
    {
        $html = '<div style="' . $sectionTitleStyle . '">Submitted Data</div>';
        $html .= '<table role="presentation" cellpadding="' . ($isPdf ? '6' : '0') . '" cellspacing="0" style="' . $tableStyle . '">';
        foreach ($data as $k => $v) {
            $val = self::formatValue($v, []);
            $display = ($val === '') ? '&nbsp;' : self::esc($val);
            $html .= '<tr><td ' . ($isPdf ? 'width="38%"' : '') . ' style="' . $thStyle . '">' . self::esc((string)$k) . '</td><td ' . ($isPdf ? 'width="62%"' : '') . ' style="' . $tdStyle . '">' . $display . '</td></tr>';
        }
        $html .= '</table>';
        return $html;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
