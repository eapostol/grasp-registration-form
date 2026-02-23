<?php
// api/lib/EmailPrintTemplate.php
//
// Template-driven, Gmail-safe ("PDF-like") renderer for GRASP form emails + TCPDF HTML.
// Loads markup from api/templates/email/*.html and api/templates/pdf/*.html
//
// 2026-02-08

class EmailPrintTemplate
{
    private static $templateCache = [];

    private static function tplPath(string $kind, string $name): string
    {
        // kind: 'email' or 'pdf'
        return __DIR__ . '/../templates/' . $kind . '/' . $name . '.html';
    }

    private static function loadTemplate(string $kind, string $name): string
    {
        $key = $kind . ':' . $name;
        if (isset(self::$templateCache[$key])) {
            return self::$templateCache[$key];
        }
        $path = self::tplPath($kind, $name);
        if (!file_exists($path)) {
            // Hard fail is better than silently sending empty emails
            throw new Exception("Missing template file: " . $path);
        }
        $content = file_get_contents($path);
        self::$templateCache[$key] = $content;
        return $content;
    }

    private static function h(string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function normalizeWhitespace(string $value): string
    {
        // Keep line breaks but normalize weird spacing
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        return trim($value);
    }

    private static function formatIsoDate(string $value): string
    {
        // Convert YYYY-MM-DD to "Month D, YYYY"
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $dt = DateTime::createFromFormat('Y-m-d', $value);
            if ($dt) {
                return $dt->format('F j, Y');
            }
        }
        return $value;
    }

    private static function displayValue($value): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map('strval', $value)));
        }
        $value = self::normalizeWhitespace((string)$value);
        $value = self::formatIsoDate($value);
        if ($value === '') {
            return '<span style="color:#888;">(blank)</span>';
        }
        // Preserve newlines in long text fields
        return nl2br(self::h($value));
    }


    private static function truthy($value): bool
    {
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return ((int)$value) !== 0;
        $s = strtolower(trim((string)$value));
        return in_array($s, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    private static function optionLabel(array $field, $value): string
    {
        $options = $field['options'] ?? [];
        if (!is_array($options) || count($options) === 0) {
            return (string)$value;
        }
        foreach ($options as $opt) {
            if (!is_array($opt)) continue;
            $ov = $opt['value'] ?? null;
            if ((string)$ov === (string)$value) {
                return (string)($opt['label'] ?? ($opt['value'] ?? $value));
            }
        }
        return (string)$value;
    }

    private static function normalizeFieldValue(array $field, $value)
    {
        $type = $field['type'] ?? '';

        // Map radio/select values to their option label (for readability in emails/PDFs)
        if (($type === 'radio' || $type === 'select') && isset($field['options'])) {
            if (is_array($value)) {
                $mapped = array_map(function ($v) use ($field) {
                    return self::optionLabel($field, $v);
                }, $value);
                return implode(', ', array_filter($mapped, function ($v) { return trim((string)$v) !== ''; }));
            }
            return self::optionLabel($field, $value);
        }

        // Checkbox: display Yes/No
        if ($type === 'checkbox') {
            return self::truthy($value) ? 'Yes' : 'No';
        }

        return $value;
    }
    private static function inlineFill(string $value, string $kind = 'email', int $minWidthPx = 180): string
    {
        $v = trim($value);
        $safe = self::h($v);
        if ($safe === '') {
            $safe = '&nbsp;';
        }

        // TCPDF doesn't reliably render CSS border-bottom; use semantic underline/bold.
        if ($kind === 'pdf') {
            return '<b><u>' . $safe . '</u></b>';
        }

        return '<span style="border-bottom:1px solid #111; display:inline-block; min-width:' . (int)$minWidthPx . 'px; padding:0 6px; line-height:1.2;">' . $safe . '</span>';
    }

    private static function replaceTokens(string $html, array $data, string $kind = 'email'): string
    {
        if ($html === '') return $html;

        // {{fill:key}} -> underlined fill with escaped data
        $html = preg_replace_callback('/\{\{\s*fill:([a-zA-Z0-9_\-]+)\s*\}\}/', function ($m) use ($data, $kind) {
            $key = $m[1];
            $val = isset($data[$key]) ? (string)$data[$key] : '';
            return self::inlineFill($val, $kind);
        }, $html);

        // {{key}} -> escaped data
        $html = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\-]+)\s*\}\}/', function ($m) use ($data, $kind) {
            $key = $m[1];
            $val = isset($data[$key]) ? (string)$data[$key] : '';
            return self::h($val);
        }, $html);

        return $html;
    }

    private static function fieldKey(array $field): string
    {
        // Your configs primarily use "name"
        if (!empty($field['name'])) return (string)$field['name'];
        if (!empty($field['id'])) return (string)$field['id'];
        if (!empty($field['key'])) return (string)$field['key'];
        return '';
    }

    /**
     * Create derived/normalized values for display.
     *
     * - If *_postal_code is missing but *_postal1 and *_postal2 exist, synthesize *_postal_code.
     */
    private static function preprocessData(array $data): array
    {
        // Synthesize "*_postal_code" values from "*_postal1" + "*_postal2" when needed.
        foreach ($data as $k => $v) {
            if (!is_string($k)) continue;
            if (!preg_match('/_postal1$/', $k)) continue;

            $base = preg_replace('/_postal1$/', '', $k);
            $k1 = $base . '_postal1';
            $k2 = $base . '_postal2';
            $kc = $base . '_postal_code';

            if (!empty($data[$kc])) {
                continue;
            }

            $p1 = isset($data[$k1]) ? trim((string)$data[$k1]) : '';
            $p2 = isset($data[$k2]) ? trim((string)$data[$k2]) : '';
            if ($p1 !== '' && $p2 !== '') {
                $data[$kc] = strtoupper($p1 . ' ' . $p2);
            }
        }


        // Derived display helpers for content blocks (ensures placeholders can be filled server-side)
        $childName = trim((string)($data['child_name'] ?? ''));
        if ($childName === '') {
            $first = trim((string)($data['child_first_name'] ?? ''));
            $middle = trim((string)($data['child_middle_name_or_initial'] ?? ''));
            $last = trim((string)($data['child_last_name'] ?? ''));
            $parts = array_filter([$first, $middle, $last], function ($p) { return $p !== ''; });
            $childName = trim(implode(' ', $parts));
            if ($childName !== '') {
                $data['child_name'] = $childName;
            }
        }
        if ($childName !== '') {
            $data['child_full_name'] = $childName;
        }

        // parent_signature: prefer explicit signature field, else Parent/Guardian 1 name
        $parentSig = trim((string)($data['parent_full_name_signature'] ?? ''));
        if ($parentSig === '') {
            $parentSig = trim((string)($data['parent1_name'] ?? ''));
        }
        if ($parentSig === '') {
            $p1First = trim((string)($data['parent1_first_name'] ?? ''));
            $p1Last  = trim((string)($data['parent1_last_name'] ?? ''));
            $parentSig = trim($p1First . ' ' . $p1Last);
        }
        if ($parentSig !== '') {
            $data['parent_signature'] = $parentSig;
        }

        return $data;
    }

    private static function rowLabel(array $field): string
    {
        // Parent Manual initials: associate to section title
        $kind = $field['kind'] ?? '';
        if ($kind === 'initials' && !empty($field['sectionTitle'])) {
            $page = $field['page'] ?? '';
            $prefix = $page !== '' ? ('p' . self::h((string)$page) . ' — ') : '';
            return $prefix . self::h((string)$field['sectionTitle']);
        }
        // Enrollment postal code: give a clearer label than "full, derived".
        $key = self::fieldKey($field);
        if ($key !== '') {
            $postalMap = [
                'parent1_postal_code' => 'Home Postal Code (Parent / Guardian 1)',
                'parent1_work_postal_code' => 'Work/School Postal Code (Parent / Guardian 1)',
                'parent2_postal_code' => 'Home Postal Code (Parent / Guardian 2)',
                'parent2_work_postal_code' => 'Work/School Postal Code (Parent / Guardian 2)',
                'doctor_postal_code' => 'Doctor/Clinic Postal Code',
            ];
            if (isset($postalMap[$key])) {
                return self::h($postalMap[$key]);
            }
        }

        $label = $field['label'] ?? '';
        // Strip "(first part...)" / "(second part...)" if it leaks into a label.
        $label = preg_replace('/\s*\((first|second) part[^\)]*\)\s*/i', ' ', (string)$label);
        $label = trim(preg_replace('/\s+/', ' ', $label));
        return self::h($label);
    }

    private static function shouldSkipField(array $field, array $data): bool
    {
        // Hide postal1/postal2 when full postal_code exists and is populated
        $name = self::fieldKey($field);
        if ($name === '') return true;

        // Skip hidden/computed helper fields (keeps email/PDF output compact)
        $type = isset($field['type']) ? strtolower(trim((string)$field['type'])) : '';
        if ($type === 'hidden') {
            return true;
        }

        // Suppress redundant name fragments when a full-name field exists
        if (preg_match('/^child_(first_name|middle_name|last_name)$/', $name)) {
            if (!empty($data['child_name'])) {
                return true;
            }
        }
        if (preg_match('/^parent([12])_(first_name|last_name)$/', $name, $mm)) {
            $fullKey = 'parent' . $mm[1] . '_name';
            if (!empty($data[$fullKey])) {
                return true;
            }
        }

        // Suppress computed address aggregates when component fields exist
        if (str_ends_with($name, '_address')) {
            $base = substr($name, 0, -strlen('_address'));
            $components = [
                $base . '_street',
                $base . '_unit',
                $base . '_city',
                $base . '_province',
                $base . '_postal_code',
                $base . '_postal1',
                $base . '_postal2',
            ];
            foreach ($components as $ck) {
                if (!empty($data[$ck])) {
                    return true;
                }
            }
        }

        if (preg_match('/_postal1$|_postal2$/', $name)) {
            $full = preg_replace('/_postal[12]$/', '_postal_code', $name);
            if (!empty($data[$full])) {
                return true;
            }
        }
        return false;
    }

    private static function splitWaitlistGuardians(array $fields): array
    {
        // Split parent1_* and parent2_* into two readable blocks.
        $p1 = [];
        $p2 = [];

        $labelBySuffix = [
            '_name' => 'Name',
            '_email' => 'Email Address',
            '_work_phone' => 'Work Phone #',
            '_cell_phone' => 'Cell Phone #',
        ];

        foreach ($fields as $f) {
            if (!is_array($f)) continue;
            $k = self::fieldKey($f);
            if ($k === '') continue;

            $target = null;
            if (strpos($k, 'parent1_') === 0) $target = 'p1';
            if (strpos($k, 'parent2_') === 0) $target = 'p2';
            if ($target === null) continue;

            // Normalize label
            $new = $f;
            foreach ($labelBySuffix as $suffix => $pretty) {
                if (str_ends_with($k, $suffix)) {
                    $new['label'] = $pretty;
                    break;
                }
            }

            if ($target === 'p1') $p1[] = $new;
            if ($target === 'p2') $p2[] = $new;
        }

        $out = [];
        if (count($p1)) $out[] = ['title' => 'Parent / Guardian 1 Info', 'fields' => $p1];
        if (count($p2)) $out[] = ['title' => 'Parent / Guardian 2 Info', 'fields' => $p2];
        return $out;
    }

    private static function renderWaitlistGuardiansTwoColPdf(array $split, array $data): string
    {
        // Expected $split: [ ['title'=>..., 'fields'=>...], ['title'=>..., 'fields'=>...] ]
        $leftFields = $split[0]['fields'] ?? [];
        $rightFields = $split[1]['fields'] ?? [];

        $leftRows = self::renderRows('pdf', is_array($leftFields) ? $leftFields : [], $data);
        $rightRows = self::renderRows('pdf', is_array($rightFields) ? $rightFields : [], $data);

        if (trim($leftRows) === '' && trim($rightRows) === '') return '';

        $subHeaderStyle = 'background-color:#f3f3f3; font-weight:bold; border-bottom:0.5pt solid #333;';
        $colTableStyle = 'border-collapse:collapse;';

        $leftTitle = 'Parent / Guardian 1';
        $rightTitle = 'Parent / Guardian 2';

        $leftTable = '<table width="100%" cellpadding="4" cellspacing="0" style="' . $colTableStyle . '">' .
            '<tr><td colspan="2" style="' . $subHeaderStyle . '">' . self::h($leftTitle) . '</td></tr>' .
            $leftRows .
            '</table>';

        $rightTable = '<table width="100%" cellpadding="4" cellspacing="0" style="' . $colTableStyle . '">' .
            '<tr><td colspan="2" style="' . $subHeaderStyle . '">' . self::h($rightTitle) . '</td></tr>' .
            $rightRows .
            '</table>';

        $nested = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">' .
            '<tr>' .
            '<td width="50%" style="vertical-align:top; padding:0 6px 0 0;">' . $leftTable . '</td>' .
            '<td width="50%" style="vertical-align:top; padding:0 0 0 6px;">' . $rightTable . '</td>' .
            '</tr>' .
            '</table>';

        $rowFullTpl = self::loadTemplate('pdf', 'row_full');
        return str_replace(
            ['{{BGCOLOR_ATTR}}', '{{STYLE}}', '{{CONTENT}}'],
            ['', 'padding:4px 6px;', $nested],
            $rowFullTpl
        );
    }


    

    private static function renderWaitlistGuardiansTwoCol(string $kind, array $split, array $data): string
    {
        if ($kind === 'pdf') {
            return self::renderWaitlistGuardiansTwoColPdf($split, $data);
        }

        // Email: mimic the PDF two-column layout (for print parity)
        $leftFields = $split[0]['fields'] ?? [];
        $rightFields = $split[1]['fields'] ?? [];

        $leftRows = self::renderRows('email', is_array($leftFields) ? $leftFields : [], $data);
        $rightRows = self::renderRows('email', is_array($rightFields) ? $rightFields : [], $data);

        if (trim($leftRows) === '' && trim($rightRows) === '') return '';

        $subHeaderStyle = 'background:#f3f3f3; font-weight:bold; border-bottom:1px solid #333;';
        $colTableStyle = 'border-collapse:collapse;';

        $leftTitle = 'Parent / Guardian 1';
        $rightTitle = 'Parent / Guardian 2';

        $leftTable = '<table width="100%" cellpadding="0" cellspacing="0" style="' . $colTableStyle . '">'
            . '<tr><td colspan="2" style="' . $subHeaderStyle . ' padding:7px 10px;">' . self::h($leftTitle) . '</td></tr>'
            . $leftRows
            . '</table>';

        $rightTable = '<table width="100%" cellpadding="0" cellspacing="0" style="' . $colTableStyle . '">'
            . '<tr><td colspan="2" style="' . $subHeaderStyle . ' padding:7px 10px;">' . self::h($rightTitle) . '</td></tr>'
            . $rightRows
            . '</table>';

        $nested = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
            . '<tr>'
            . '<td width="50%" style="vertical-align:top; padding:0 6px 0 0;">' . $leftTable . '</td>'
            . '<td width="50%" style="vertical-align:top; padding:0 0 0 6px;">' . $rightTable . '</td>'
            . '</tr>'
            . '</table>';

        $rowFullTpl = self::loadTemplate('email', 'row_full');
        return str_replace(
            ['{{BGCOLOR_ATTR}}', '{{STYLE}}', '{{CONTENT}}'],
            ['', 'padding:4px 6px;', $nested],
            $rowFullTpl
        );
    }
private static function renderRows(string $kind, array $fields, array $data): string
    {
        $rowTpl = self::loadTemplate($kind, 'row');
        $out = [];
        foreach ($fields as $field) {
            if (!is_array($field)) continue;

            if (self::shouldSkipField($field, $data)) {
                continue;
            }

            $key = self::fieldKey($field);
            if ($key === '') continue;

            $value = $data[$key] ?? '';
            $value = self::normalizeFieldValue($field, $value);
            // For checkboxes/radios, some forms send string/array; handled in displayValue()
            $label = self::rowLabel($field);

            $row = str_replace(
                ['{{LABEL}}', '{{VALUE}}'],
                [$label, self::displayValue($value)],
                $rowTpl
            );
            $out[] = $row;
        }
        return implode("\n", $out);
    }


    
    /**
     * Enrollment-only: render rows using a 60/40 label/value split.
     * Intended for "Medical Release & Medication" last rows in PDF/email parity.
     */
    private static function renderRows60_40(string $kind, array $fields, array $data): string
    {
        $b = self::borderTop($kind);
        $out = [];

        foreach ($fields as $field) {
            if (!is_array($field)) continue;

            if (self::shouldSkipField($field, $data)) {
                continue;
            }

            $key = self::fieldKey($field);
            if ($key === '') continue;

            $value = $data[$key] ?? '';
            $value = self::normalizeFieldValue($field, $value);
            $label = self::rowLabel($field);

            if ($kind === 'pdf') {
                $out[] = '<tr>'
                    . '<td width="60%" style="border-top:' . $b . '; font-weight:bold; vertical-align:top;">' . $label . '</td>'
                    . '<td width="40%" style="border-top:' . $b . '; vertical-align:top;">' . self::displayValue($value) . '</td>'
                    . '</tr>';
            } else {
                $out[] = '<tr>'
                    . '<td style="width:60%; padding:7px 10px; border-top:' . $b . '; vertical-align:top; font-weight:bold;">' . $label . '</td>'
                    . '<td style="width:40%; padding:7px 10px; border-top:' . $b . '; vertical-align:top;">' . self::displayValue($value) . '</td>'
                    . '</tr>';
            }
        }

        return implode("\n", $out);
    }

    /**
     * Enrollment-only: render a single consent row using a 75/25 label/value split.
     * Used for Water Play & Hand Sanitizer section parity.
     */
    private static function renderEnrollmentRow75_25(string $kind, array $field, array $data): string
    {
        $b = self::borderTop($kind);
        $key = self::fieldKey($field);
        if ($key === '') return '';

        $value = $data[$key] ?? '';
        $value = self::normalizeFieldValue($field, $value);
        $label = self::rowLabel($field);

        if ($kind === 'pdf') {
            return '<tr>'
                . '<td width="75%" style="border-top:' . $b . '; font-weight:bold; vertical-align:top;">' . $label . '</td>'
                . '<td width="25%" style="border-top:' . $b . '; vertical-align:top;">' . self::displayValue($value) . '</td>'
                . '</tr>';
        }

        return '<tr>'
            . '<td style="width:75%; padding:7px 10px; border-top:' . $b . '; vertical-align:top; font-weight:bold;">' . $label . '</td>'
            . '<td style="width:25%; padding:7px 10px; border-top:' . $b . '; vertical-align:top;">' . self::displayValue($value) . '</td>'
            . '</tr>';
    }



private static function renderContentBlocks(string $kind, array $blocks, array $data): string
    {
        if (!is_array($blocks) || count($blocks) === 0) return '';

        $rowTpl = self::loadTemplate($kind, 'row_full');
        $out = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) continue;

            $style = isset($block['style']) ? trim((string)$block['style']) : '';
            if ($style !== '' && substr($style, -1) !== ';') {
                $style .= ';';
            }

            $bgcolorAttr = '';
            if ($kind === 'pdf') {
                // TCPDF renders bgcolor reliably; CSS background may not.
                if (preg_match('/(?:background|background-color)\s*:\s*(#[0-9a-fA-F]{3,6})/i', $style, $mm)) {
                    $bg = strtoupper($mm[1]);
                    $bgcolorAttr = 'bgcolor="' . $bg . '"';
                }
            }

            $title = trim((string)($block['title'] ?? ''));
            $html = '';

            if (!empty($block['html'])) {
                $html = (string)$block['html'];
            } elseif (!empty($block['text'])) {
                $html = nl2br(self::h(self::normalizeWhitespace((string)$block['text'])));
            }

            $html = self::replaceTokens($html, $data, $kind);

            // Enrollment: reduce excessive blank lines after key headings (TCPDF can render multiple <br> as extra vertical space)
            $html = preg_replace('/(<\s*(?:b|strong)\s*>\s*MEDICATION\s*<\s*\/\s*(?:b|strong)\s*>\s*<br\s*\/?>)\s*(?:<br\s*\/?>\s*)+/i', '$1', $html);
            $html = preg_replace('/(<\s*(?:b|strong)\s*>\s*\(\s*MEDICAL\s*RELEASE\s*\)\s*PARENTS\s*CONSENT\s*FOR\s*MEDICAL\s*TREATMENT\s*<\s*\/\s*(?:b|strong)\s*>\s*<br\s*\/?>)\s*(?:<br\s*\/?>\s*)+/i', '$1', $html);

            // Enrollment: render the Immunization tail as its own left-aligned paragraph (without introducing large gaps)
            // Keep the main paragraph justified; move the "The immunization form is available at..." sentence(s) to a new left-aligned block.
            // Use an explicit blank line (<br /><br />) so TCPDF renders a clean paragraph break.
            if ($kind === 'pdf' && stripos($html, 'Immunization Form.') !== false && stripos($html, 'The immunization form is available at') !== false) {
                $didWrap = false;
                $html = preg_replace_callback(
                    '/Immunization\s+Form\.(?:\s*<br\s*\/?>\s*)+(The\s+immunization\s+form\s+is\s+available\s+at)/i',
                    function ($m) use (&$didWrap) {
                        $didWrap = true;
                        return 'Immunization Form.<br /><br /><div style="text-align:left; margin:0; padding:0;">' . $m[1];
                    },
                    $html,
                    1
                );

                if ($didWrap) {
                    // Normalize accidental double period after the URL if present.
                    $html = str_replace('medical-2016.pdf..', 'medical-2016.pdf.', $html);
                    $html .= '</div>';
                }
            }

            // Enrollment PDF: tighten vertical rhythm around specific Medical section headings only
            // (MEDICATION and (MEDICAL RELEASE)...) without impacting global row_full rendering.
            // TCPDF can add extra vertical space around block elements; for these two headings, render inline + single <br />.
            $isTightMedicalHeading = false;
            if ($kind === 'pdf' && $title !== '') {
                $t = strtoupper(trim($title));
                $isTightMedicalHeading = ($t === 'MEDICATION' || (strpos($t, '(MEDICAL RELEASE)') === 0));
            }

            if ($title !== '') {
                if ($isTightMedicalHeading) {
                    $html = '<span style="font-weight:bold; line-height:1.0;">' . self::h($title) . '</span><br />' . $html;
                } else {
                    $titleStyle = 'font-weight:bold; margin:0 0 4px 0;';
                    $html = '<div style="' . $titleStyle . '">' . self::h($title) . '</div>' . $html;
                }
            }

            if (trim($html) === '') continue;

            $row = str_replace(
                ['{{STYLE}}', '{{CONTENT}}', '{{BGCOLOR_ATTR}}'],
                [self::h($style), $html, $bgcolorAttr],
                $rowTpl
            );

            // PDF-only: tighten vertical spacing around specific Medical section headings only.
            // Do NOT change global row_full rendering; only override padding for these specific blocks.
            if ($kind === 'pdf') {
                $t = strtoupper(trim((string)$title));
                $isMedicationHeading = ($t === 'MEDICATION')
                    || (bool)preg_match('/<\s*(?:b|strong)\s*>\s*MEDICATION\s*<\s*\/\s*(?:b|strong)\s*>/i', $html);

                $isMedicalReleaseHeading = (strpos($t, '(MEDICAL RELEASE)') === 0)
                    || (bool)preg_match('/\(\s*MEDICAL\s*RELEASE\s*\)\s*PARENTS\s*CONSENT\s*FOR\s*MEDICAL\s*TREATMENT/i', $html);

                if ($isMedicationHeading) {
                    // Reduce perceived "double line-height" gap above the MEDICATION heading.
                    $row = str_replace('padding:7px 10px;', 'padding:1px 10px 3px 10px;', $row);
                } elseif ($isMedicalReleaseHeading) {
                    // Tighten above/below the Medical Release heading and reduce excess space after the immunization paragraph.
                    $row = str_replace('padding:7px 10px;', 'padding:1px 10px 1px 10px;', $row);
                }
            }


            $out[] = $row;
        }

        return implode("\n", $out);
    }

    private static function renderSections(string $kind, array $sections, array $data, array $meta = []): string
    {
        $profile = isset($meta['templateProfile']) ? (string)$meta['templateProfile'] : '';

        $sectionTplName = 'section';
        if ($kind === 'pdf') {
            if ($profile === 'waitlist') {
                $sectionTplName = 'section_waitlist';
            } elseif ($profile === 'enrollment') {
                $sectionTplName = 'section_enrollment';
            }
        }

        $sectionTpl = self::loadTemplate($kind, $sectionTplName);
        $out = [];

        $n = count($sections);
        for ($i = 0; $i < $n; $i++) {
            $section = $sections[$i];
            if (!is_array($section)) continue;

            $title = $section['title'] ?? ($section['sectionTitle'] ?? 'Section');
            $fields = $section['fields'] ?? [];
            if (!is_array($fields) || count($fields) === 0) continue;

            $titleTrim = is_string($title) ? trim($title) : '';

            // -----------------------------------------------------------------
            // Enrollment PDF pagebreak control (Phase 7):
            // Keep Emergency & Authorized Pickups on Page 1 by forcing clean section starts.
            // -----------------------------------------------------------------
            if ($kind === 'pdf' && $profile === 'enrollment' && $titleTrim !== '') {
                if ($titleTrim === 'Medical Release & Medication' || $titleTrim === 'Water Play & Hand Sanitizer') {
                    $out[] = '<tcpdf method="AddPage" />';
                }
            }


            // -----------------------------------------------------------------
            // Waitlist-only layout compaction (email + PDF):
            // 1) Child Information + Address -> single 2-column block
            // 2) Subsidy/Fee + Sibling + Allergies -> single 3-column block
            // -----------------------------------------------------------------
            if ($profile === 'waitlist' && $titleTrim !== '') {
                // (1) Child Information + Address
                if ($titleTrim === 'Child Information' && ($i + 1) < $n) {
                    $next = $sections[$i + 1];
                    if (is_array($next)) {
                        $nextTitle = $next['title'] ?? ($next['sectionTitle'] ?? '');
                        $nextTrim = is_string($nextTitle) ? trim($nextTitle) : '';
                        if ($nextTrim === 'Address') {
                            $leftFields  = $fields;
                            $rightFields = is_array($next['fields'] ?? null) ? $next['fields'] : [];

                            $html = self::renderWaitlistTwoColumnBox(
                                $kind,
                                'Child Information',
                                $leftFields,
                                'Address',
                                $rightFields,
                                $data
                            );

                            if (trim($html) !== '') {
                                $out[] = $html;
                                $i++; // skip Address (consumed)
                                continue;
                            }
                        }
                    }
                }

                // (2) Subsidy / Fee Status + Sibling at GRASP + Allergies / Special Needs
                if ($titleTrim === 'Subsidy / Fee Status' && ($i + 2) < $n) {
                    $s2 = $sections[$i + 1];
                    $s3 = $sections[$i + 2];
                    if (is_array($s2) && is_array($s3)) {
                        $t2 = $s2['title'] ?? ($s2['sectionTitle'] ?? '');
                        $t3 = $s3['title'] ?? ($s3['sectionTitle'] ?? '');
                        $t2 = is_string($t2) ? trim($t2) : '';
                        $t3 = is_string($t3) ? trim($t3) : '';

                        if ($t2 === 'Sibling at GRASP' && $t3 === 'Allergies / Special Needs') {
                            $f2 = is_array($s2['fields'] ?? null) ? $s2['fields'] : [];
                            $f3 = is_array($s3['fields'] ?? null) ? $s3['fields'] : [];

                            $alignLeft = [];
                            foreach ($f3 as $ff) {
                                if (!is_array($ff)) continue;
                                $k = self::fieldKey($ff);
                                if ($k !== '') {
                                    $alignLeft[$k] = 'left';
                                }
                            }

                            $html = self::renderWaitlistThreeColumnBox(
                                $kind,
                                [
                                    ['title' => 'Subsidy / Fee Status', 'fields' => $fields],
                                    ['title' => 'Sibling at GRASP', 'fields' => $f2],
                                    ['title' => 'Allergies / Special Needs', 'fields' => $f3, 'opts' => ['valueAlignByKey' => $alignLeft, 'stackKeys' => array_keys($alignLeft)]],
                                ],
                                $data
                            );

                            if (trim($html) !== '') {
                                $out[] = $html;
                                $i += 2; // skip the next 2 sections (consumed)
                                continue;
                            }
                        }
                    }
                }
            }

            // -----------------------------------------------------------------
            // Enrollment-only layout compaction (email + PDF):
            // Child's Primary Information
            //
            // Expected layout:
            //   Row 1: First Name | Middle Name or Initial (optional) | Last Name
            //   Row 2: Birth Date | Subsidy File #
            //
            // This override intentionally renders child first/last even if a
            // derived "child_name" exists, since the original PDF expects
            // distinct fields.
            // -----------------------------------------------------------------
            if ($profile === 'enrollment' && $titleTrim !== '') {
                $normTitle = str_replace(["\u{2019}", "’"], "'", $titleTrim);
                if ($normTitle === "Child's Primary Information") {
                    $map = self::mapFieldsByName($fields);

                    $rows = [];
                    // Row 1: First/Middle/Last
                    $rows[] = self::renderEnrollmentThreeColRow(
                        $kind,
                        $map['child_first_name'] ?? null,
                        $map['child_middle_name_or_initial'] ?? null,
                        $map['child_last_name'] ?? null,
                        $data,
                        [
                            'noTopBorder' => true,
                            'labelOverrides' => [
                                'child_first_name' => 'First Name',
                                'child_middle_name_or_initial' => 'Middle Name / Initial',
                                'child_last_name' => 'Last Name',
                            ],
                        ]
                    );

                    // Row 2: Birth Date + Subsidy File #
                    $rows[] = self::renderEnrollmentTwoColRow(
                        $kind,
                        $map['child_birth_date'] ?? null,
                        $map['subsidy_file_number'] ?? null,
                        $data,
                        [
                            'labelOverrides' => [
                                'child_birth_date' => 'Birth Date',
                                'subsidy_file_number' => 'Subsidy File #',
                            ],
                            // This 2-column row follows a 3-column row above.
                            // Wrap as a full-width row so the divider line continues
                            // under the 3rd column (email clients otherwise treat the
                            // missing 3rd cell as an implicit blank column).
                            'colspan3' => true,
                        ]
                    );

                    $rowsHtml = implode("\n", array_filter($rows, function ($r) { return trim((string)$r) !== ''; }));
                    if (trim($rowsHtml) !== '') {
                        $out[] = str_replace(
                            ['{{SECTION_TITLE}}', '{{ROWS}}'],
                            [self::h("Child's Primary Information"), $rowsHtml],
                            $sectionTpl
                        );
                    }
                    continue;
                }
            }

            // -----------------------------------------------------------------
            // Enrollment-only layout parity (email + PDF):
            // Initial Parent/Guardian Interview
            //
            // Desired layout:
            //   Row 1: Child Name | value | Date of Birth | value
            //   (Remove the original standalone Date of Birth row.)
            // -----------------------------------------------------------------
            if ($profile === 'enrollment' && $titleTrim !== '') {
                $normTitle = str_replace(["\u{2019}", "’"], "'", $titleTrim);

                if ($normTitle === 'Initial Parent/Guardian Interview') {
                    $map = self::mapFieldsByName($fields);

                    $b = self::borderTop($kind);
                    $pad = ($kind === 'pdf') ? '5px 6px' : '7px 8px';

    $labelStyle = ($kind === 'pdf') ? 'font-weight:bold; font-size:80%;' : 'font-weight:bold;';
    $smallValueStyle = ($kind === 'pdf') ? 'font-size:80%; line-height:1.15;' : '';

                    $labelStyle = 'font-weight:bold; vertical-align:top;';
                    $valueStyle = 'text-align:left; vertical-align:top;';

                    $childNameHtml = self::displayFieldValueHtml($kind, $map['child_name'] ?? null, $data);
                    $dobHtml = self::displayFieldValueHtml($kind, $map['child_birth_date'] ?? null, $data);

                    $rows = [];

                    // For email: use a nested 1x4 table inside a single parent cell so we can
                    // control column widths without fighting the parent 2-column table layout.
                    if ($kind === 'email') {
                        $innerTable = '<table style="width:100%; border-collapse:collapse; table-layout:fixed;">'
                            . '<tr>'
                            . '<td style="width:18%; padding:' . $pad . '; ' . $labelStyle . '">' . self::h('Child Name') . '</td>'
                            . '<td style="width:32%; padding:' . $pad . '; ' . $valueStyle . '">' . $childNameHtml . '</td>'
                            . '<td style="width:20%; padding:' . $pad . '; ' . $labelStyle . ' white-space:nowrap;">' . self::h('Date of Birth') . '</td>'
                            . '<td style="width:30%; padding:' . $pad . '; ' . $valueStyle . ' white-space:nowrap;">' . $dobHtml . '</td>'
                            . '</tr>'
                            . '</table>';

                        $rows[] = '<tr>'
                            . '<td colspan="2" style="padding:0; ' . $btFirst . '">' . $innerTable . '</td>'
                            . '</tr>';
                    } else {
                        // PDF: keep the direct 4-cell row (TCPDF handles widths well here).
                        $rows[] = '<tr>'
                            . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $labelStyle . '">' . self::h('Child Name') . '</td>'
                            . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $valueStyle . '">' . $childNameHtml . '</td>'
                            . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $labelStyle . '">' . self::h('Date of Birth') . '</td>'
                            . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $valueStyle . '">' . $dobHtml . '</td>'
                            . '</tr>';
                    }

                    // Render the remaining rows, excluding the fields we just placed.
                    $remaining = [];
                    foreach ($fields as $f) {
                        if (!is_array($f)) continue;
                        $k = self::fieldKey($f);
                        if ($k === 'child_name' || $k === 'child_birth_date') {
                            continue;
                        }
                        $remaining[] = $f;
                    }

                    $rest = self::renderRows($kind, $remaining, $data);
                    if (trim($rest) !== '') {
                        $rows[] = $rest;
                    }

                    $rowsHtml = implode("\n", $rows);
                    if (trim($rowsHtml) !== '') {
                        $out[] = str_replace(
                            ['{{SECTION_TITLE}}', '{{ROWS}}'],
                            [self::h('Initial Parent/Guardian Interview'), $rowsHtml],
                            $sectionTpl
                        );
                    }

                    continue;
                }
            }


            
            // -----------------------------------------------------------------
            // Enrollment-only layout parity (email + PDF):
            // Arrival & Departure Procedure
            // -----------------------------------------------------------------
            if ($profile === 'enrollment' && $titleTrim !== '') {
                $normTitle = str_replace(["\u{2019}", "’"], "'", $titleTrim);

                if ($normTitle === 'Arrival & Departure Procedure') {
                    $map = self::mapFieldsByName($fields);

                    $b = self::borderTop($kind);
                    $pad = ($kind === 'pdf') ? '5px 6px' : '7px 8px';

                    // Static policy paragraph (matches online form display)
                    $arrivalPolicyHtml = <<<'HTML'
<div class="grasp-policy-block"><p>I always agree to accompany my child <strong><u>to and from</u></strong> GRASP classroom and notify staff verbally  <strong><u>upon arrival and departure</u></strong>. I understand that is my responsibility to inform all pick up and drop off persons of this policy and ensure they make verbal contact with the staff. In the event that my child is not accompanied into GRASP facilities by an adult, I understand GRASP has no legal responsibility for the safe arrival of my named child. Failure to inform staff of arrival and departure may result in notifying the authorities. Children’s arrival to GRASP from Greenland PS will be from the designated dismissal door of the school for each class. A GRASP staff member will await outside with all other pick up persons for children to be dismissed from school. If children are not in attendance at GRASP, parents must notify staff by 2:30 pm by email or call. I understand it is not the school responsibility to communicate with GRASP about my child’s attendance.</p></div>
HTML;

                    // Field defs
                    $ackField = $map['arrival_departure_ack'] ?? null;
                    $notesField = $map['arrival_departure_notes'] ?? null;

                    // Values
                    $ackVal = self::displayFieldValueHtml($kind, $ackField, $data);
                    $notesVal = self::displayFieldValueHtml($kind, $notesField, $data);

                    // Signature values (from final signature step)
                    $sigNameField = ['name' => 'parent_full_name_signature', 'type' => 'text', 'label' => 'Parent / Guardian Signature'];
                    $witnessField = ['name' => 'witness', 'type' => 'text', 'label' => 'Witness'];
                    $dateField = ['name' => 'signature_date', 'type' => 'date', 'label' => 'Date Signed'];

                    $sigVal = self::displayFieldValueHtml($kind, $sigNameField, $data);
                    $witVal = self::displayFieldValueHtml($kind, $witnessField, $data);
                    $dateVal = self::displayFieldValueHtml($kind, $dateField, $data);

                    $rows = [];

                    // Paragraph row (full width)
                    if ($kind === 'pdf') {
                        $rows[] = '<tr><td width="100%" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top; text-align:justify;">'
                            . $arrivalPolicyHtml
                            . '</td></tr>';
                    } else {
                        $rows[] = '<tr><td colspan="2" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top; text-align:justify;">'
                            . $arrivalPolicyHtml
                            . '</td></tr>';
                    }

                    // ACK row (75/25) using nested table for email, direct widths for PDF
                    $ackLabel = self::h('I agree to accompany my child to and from GRASP and notify staff verbally upon arrival and departure.');
                    if ($kind === 'pdf') {
                        $rows[] = '<tr>'
                            . '<td width="75%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . $ackLabel . '</td>'
                            . '<td width="25%" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top;">' . $ackVal . '</td>'
                            . '</tr>';
                    } else {
                        $inner = '<table style="width:100%; border-collapse:collapse; table-layout:fixed;"><tr>'
                            . '<td style="width:75%; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . $ackLabel . '</td>'
                            . '<td style="width:25%; padding:' . $pad . '; vertical-align:top;">' . $ackVal . '</td>'
                            . '</tr></table>';
                        $rows[] = '<tr><td colspan="2" style="padding:0; border-top:' . $b . ';">' . $inner . '</td></tr>';
                    }

                    // Notes row (50/50)
                    $notesLabel = self::h('Additional notes regarding arrival & departure (optional)');
                    if ($kind === 'pdf') {
                        $rows[] = '<tr>'
                            . '<td width="50%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . $notesLabel . '</td>'
                            . '<td width="50%" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top;">' . $notesVal . '</td>'
                            . '</tr>';
                    } else {
                        $inner = '<table style="width:100%; border-collapse:collapse; table-layout:fixed;"><tr>'
                            . '<td style="width:50%; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . $notesLabel . '</td>'
                            . '<td style="width:50%; padding:' . $pad . '; vertical-align:top;">' . $notesVal . '</td>'
                            . '</tr></table>';
                        $rows[] = '<tr><td colspan="2" style="padding:0; border-top:' . $b . ';">' . $inner . '</td></tr>';
                    }

                    // Signature values row (3 cols) + labels row
                    if ($kind === 'pdf') {
                        $rows[] = '<tr>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top;">' . $sigVal . '</td>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top;">' . $witVal . '</td>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top;">' . $dateVal . '</td>'
                            . '</tr>';
                        $rows[] = '<tr>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; font-size:80%; vertical-align:top;">' . self::h('Parent / Guardian Signature') . '</td>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; font-size:80%; vertical-align:top;">' . self::h('Witness') . '</td>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; font-size:80%; vertical-align:top;">' . self::h('Date Signed') . '</td>'
                            . '</tr>';
                    } else {
                        $innerVals = '<table style="width:100%; border-collapse:collapse; table-layout:fixed;"><tr>'
                            . '<td style="width:33.33%; padding:' . $pad . '; vertical-align:top;">' . $sigVal . '</td>'
                            . '<td style="width:33.33%; padding:' . $pad . '; vertical-align:top;">' . $witVal . '</td>'
                            . '<td style="width:33.33%; padding:' . $pad . '; vertical-align:top;">' . $dateVal . '</td>'
                            . '</tr></table>';
                        $rows[] = '<tr><td colspan="2" style="padding:0; border-top:' . $b . ';">' . $innerVals . '</td></tr>';

                        $innerLabs = '<table style="width:100%; border-collapse:collapse; table-layout:fixed;"><tr>'
                            . '<td style="width:33.33%; padding:' . $pad . '; font-weight:bold; font-size:80%; vertical-align:top;">' . self::h('Parent / Guardian Signature') . '</td>'
                            . '<td style="width:33.33%; padding:' . $pad . '; font-weight:bold; font-size:80%; vertical-align:top;">' . self::h('Witness') . '</td>'
                            . '<td style="width:33.33%; padding:' . $pad . '; font-weight:bold; font-size:80%; vertical-align:top;">' . self::h('Date Signed') . '</td>'
                            . '</tr></table>';
                        $rows[] = '<tr><td colspan="2" style="padding:0; border-top:' . $b . ';">' . $innerLabs . '</td></tr>';
                    }

                    $rowsHtml = implode("\n", $rows);
                    if (trim($rowsHtml) !== '') {
                        $out[] = str_replace(
                            ['{{SECTION_TITLE}}', '{{ROWS}}'],
                            [self::h('Arrival & Departure Procedure'), $rowsHtml],
                            $sectionTpl
                        );
                    }

                    continue;
                }



                // -----------------------------------------------------------------
                // Enrollment-only layout parity (email + PDF):
                // Safe Arrival, Dismissal & Sun Safety
                // -----------------------------------------------------------------
                if ($normTitle === 'Safe Arrival, Dismissal & Sun Safety') {
                    $map = self::mapFieldsByName($fields);

                    $b = self::borderTop($kind);
                    $pad = ($kind === 'pdf') ? '5px 6px' : '7px 8px';

                    $safeArrivalPolicyHtml = <<<'HTML'
<div class="grasp-policy-block"><div class="grasp-policy-heading"><u>SAFE ARRIVAL AND DISMISSAL ACKNOWLEDGEMENT</u></div>
<p>This policy and the procedures within help support the safe arrival and dismissal of children receiving care. This policy will provide staff, students and volunteers with a clear understanding of their roles and responsibilities for ensuring the safe arrival and dismissal of children receiving care, including what steps are to be taken when a child does not arrive at the child care centre as expected, as well as steps to follow to ensure the safe dismissal of children. This policy is intended to fulfill the obligations set out under <em>Ontario Regulation 137/15</em> for policies and procedures regarding the safe arrival and dismissal of children in care. Please note that this policy requires parents to call and inform the childcare by 10am if their child(ren) is going to be absent from childcare and/or school.</p>
<p><strong>Acknowledgement for children who attend school:</strong></p></div>
HTML;

                    $sunSafetyPolicyHtml = <<<'HTML'
<div class="grasp-policy-block"><div class="grasp-policy-heading"><u>SUN AND SAFETY POLICY</u></div>
<p>To ensure we are providing a healthy and safe environment for our children and educators, we are requesting all sunscreens provided are cream based, rather than aerosol. The application of aerosol sunscreens can be inconsistent, providing less protection for your child with and more opportunity for uneven coverage. These sprays can also trigger respiratory irritation for those with scent sensitivities. Other health and safety issues consists of overstay, misuse and miss directed sprayers.</p>
<p>Thank you for your ongoing support and understanding.</p>
<p><strong>- We will be applying sunscreen prior to going outside before and after every water play time.</strong><br>
<strong>- Staff will supervise the application of sunscreen and assist when necessary.</strong></p>
<p>Should parents wish to provide their own sunscreen, a labeled bottle with their child’s name on it must be supplied.</p>
<p>Their child is the only one who will be permitted to use this sunscreen. Cream only sunscreen please.</p>
<p><strong>Shade:</strong><br>The play area has a combination of natural and artificial shade located close to the portable.</p>
<p><strong>Smog Alerts:</strong><br>During smog alerts children will have limited outdoor play and increased indoor/air-conditioned play. Field trips may be postponed or canceled as necessary should the smog alert remain in effect for extended periods of time.</p></div>
HTML;

                    // Field defs
                    $beforeSchoolField = $map['before_school_program_ack'] ?? null;
                    $safeArrivalField  = $map['safe_arrival_ack'] ?? null;
                    $sunscreenArrField = $map['sunscreen_provided_by'] ?? null;
                    $assistField       = $map['sunscreen_assistance_consent'] ?? null;
                    $sunAckField       = $map['sun_safety_ack'] ?? null;

                    // Values
                    $beforeSchoolVal = self::displayFieldValueHtml($kind, $beforeSchoolField, $data);
                    $safeArrivalVal  = self::displayFieldValueHtml($kind, $safeArrivalField, $data);
                    $sunscreenArrVal = self::displayFieldValueHtml($kind, $sunscreenArrField, $data);
                    $assistVal       = self::displayFieldValueHtml($kind, $assistField, $data);
                    $sunAckVal       = self::displayFieldValueHtml($kind, $sunAckField, $data);

                    $rows = [];

                    // Policy paragraph: Safe Arrival
                    if ($kind === 'pdf') {
                        $rows[] = '<tr><td width="100%" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top; text-align:justify;">'
                            . $safeArrivalPolicyHtml
                            . '</td></tr>';
                    } else {
                        $rows[] = '<tr><td colspan="2" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top; text-align:justify;">'
                            . $safeArrivalPolicyHtml
                            . '</td></tr>';
                    }

                    // Re-ordered acknowledgement rows (80/20) — no inner vertical borders
                    $row8020 = function (string $labelHtml, string $valueHtml) use ($kind, $b, $pad) : string {
                        if ($kind === 'pdf') {
                            return '<tr>'
                                . '<td width="80%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . $labelHtml . '</td>'
                                . '<td width="20%" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top;">' . $valueHtml . '</td>'
                                . '</tr>';
                        }

                        $inner = '<table style="width:100%; border-collapse:collapse; table-layout:fixed;"><tr>'
                            . '<td style="width:80%; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . $labelHtml . '</td>'
                            . '<td style="width:20%; padding:' . $pad . '; vertical-align:top;">' . $valueHtml . '</td>'
                            . '</tr></table>';

                        return '<tr><td colspan="2" style="padding:0; border-top:' . $b . ';">' . $inner . '</td></tr>';
                    };

                    // 1) "My child may not attend childcare..." first
                    $rows[] = $row8020(
                        self::h('I acknowledge that my child may not attend childcare for the before-school program on a daily basis and may be dropped off directly at school.'),
                        $beforeSchoolVal
                    );

                    // 2) Safe arrival acknowledgement second
                    $rows[] = $row8020(
                        self::h('I acknowledge the Safe Arrival and Dismissal policy and agree to call the childcare by 10am if my child will be absent.'),
                        $safeArrivalVal
                    );

                    // Policy paragraph: Sun & Safety
                    if ($kind === 'pdf') {
                        $rows[] = '<tr><td width="100%" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top; text-align:justify;">'
                            . $sunSafetyPolicyHtml
                            . '</td></tr>';
                    } else {
                        $rows[] = '<tr><td colspan="2" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top; text-align:justify;">'
                            . $sunSafetyPolicyHtml
                            . '</td></tr>';
                    }

                    // Sunscreen Arrangement row (50/50)
                    if ($kind === 'pdf') {
                        $rows[] = '<tr>'
                            . '<td width="50%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . self::h('Sunscreen Arrangement') . '</td>'
                            . '<td width="50%" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top;">' . $sunscreenArrVal . '</td>'
                            . '</tr>';
                    } else {
                        $inner = '<table style="width:100%; border-collapse:collapse; table-layout:fixed;"><tr>'
                            . '<td style="width:50%; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . self::h('Sunscreen Arrangement') . '</td>'
                            . '<td style="width:50%; padding:' . $pad . '; vertical-align:top;">' . $sunscreenArrVal . '</td>'
                            . '</tr></table>';
                        $rows[] = '<tr><td colspan="2" style="padding:0; border-top:' . $b . ';">' . $inner . '</td></tr>';
                    }

                    // Assistance consent (80/20)
                    $rows[] = $row8020(
                        self::h('GRASP may assist my child in the application of sunscreen if necessary.'),
                        $assistVal
                    );

                    // Water bottle + hat acknowledgement (80/20)
                    $rows[] = $row8020(
                        self::h('I understand I must send my child with a water bottle and hat each day during July and August.'),
                        $sunAckVal
                    );

                    $rowsHtml = implode("
", $rows);
                    if (trim($rowsHtml) !== '') {
                        $out[] = str_replace(
                            ['{{SECTION_TITLE}}', '{{ROWS}}'],
                            [self::h('Safe Arrival, Dismissal & Sun Safety'), $rowsHtml],
                            $sectionTpl
                        );
                    }

                    continue;
                }

                // -----------------------------------------------------------------
                // Enrollment-only layout parity (email + PDF):
                // Information Sharing, Travel & Photo / Media
                // -----------------------------------------------------------------
                if ($normTitle === 'Information Sharing, Travel & Photo/Media' || $normTitle === 'Information Sharing, Travel & Photo / Media') {
                    $map = self::mapFieldsByName($fields);

                    $b = self::borderTop($kind);
                    $pad = ($kind === 'pdf') ? '5px 6px' : '7px 8px';

                    $disclosureHtml = <<<'HTML'
<div class="grasp-policy-block"><p><em>Consent for sharing information among professionals involved in a child’s day enhances educational and family support.</em></p><p>Consent for sharing information is a necessary legal and ethical practice and must be obtained in order to share any information. To provide quality care for children, there are times when it is appropriate for the Childcare Centre, the School, Toronto Children’s Services to exchange information. The kind of information shared may include, but is not limited to, matters involving attendance, illness or transportation etc.  I hereby consent to reciprocal exchange of information about my child between the Centre GRASP / School and/or Toronto Children’s Services.</p></div>
HTML;
                    $travelHtml = <<<'HTML'
<div class="grasp-policy-block"><p>I hereby give consent for my child to leave the premises of GRASP under the qualified staff's supervision to participate in daily outings, trips to parks, playgrounds, school and libraries that can be reached without public or other motorized transportation. This may occur from time to time with or without prior notice and shall be deemed normal daily activity. I understand that notices will be sent home with consent forms for special trips and events, which involve public or other motorized transportation, swimming off premises. I further understand, the child care center program plans age-appropriate activities in order to keep the children engaged and from time to time may engage in some age-appropriate risky play activities as promoted in childhood development. In order to fully appreciate the program and give all the children the equal opportunity to participate in the plan activities, it is expected and highly recommended all children be in care no later than 9:30 am on non-instructional days such as P.A. Day and summer camp. Any community outing or walks will not depart prior to 9:30 am. Once the group leaves the center for a walk, community outing or field trip staff is not permitted, under any circumstances, to release or accept your child. You must drop off or pick up your child before or after the outing on Greenland property. Children will only be accepted in their designated classes for ratio and safety purposes.</p></div>
HTML;

                    // Signature values (from final signature step)
                    $sigNameField = ['name' => 'parent_full_name_signature', 'type' => 'text', 'label' => 'Parent / Guardian Signature'];
                    $witnessField = ['name' => 'witness', 'type' => 'text', 'label' => 'Witness'];
                    $dateField = ['name' => 'signature_date', 'type' => 'date', 'label' => 'Date'];

                    $sigVal = self::displayFieldValueHtml($kind, $sigNameField, $data);
                    $witVal = self::displayFieldValueHtml($kind, $witnessField, $data);
                    $dateVal = self::displayFieldValueHtml($kind, $dateField, $data);

                    // Consent rows and mappings
                    $infoField = $map['info_sharing_consent'] ?? null;
                    $travelField = $map['travel_consent'] ?? null;
                    $photoField = $map['photo_media_consent'] ?? null;

                    $infoRaw = self::getFieldValue($infoField, $data);
                    $infoDisplay = ($infoRaw === 'I consent') ? 'I consent and agree' : $infoRaw;

                    $travelRaw = self::getFieldValue($travelField, $data);
                    $travelDisplay = ($travelRaw === 'I consent') ? 'I acknowledge and agree' : (($travelRaw !== '') ? 'I disagree and do not consent' : '');

                    $photoRaw = self::getFieldValue($photoField, $data);
                    $photoDisplay = ($photoRaw === 'I agree to full use as described')
                        ? 'I have read, understood and agree to the above Release'
                        : (($photoRaw !== '') ? <<<'HTML'
I disagree with the above release form and I do not give permission to GRASP to distribute Images to other parents of children at the Centre via email, and to publish such Images on the GRASP website, Instagram account, and in promotional materials such as brochures, newsletters and/or any other Center-related publication. I do give permission to display the Images in the Centre and to be used for internal projects.
HTML : '');

                    $rows = [];

                    // Sub-heading + disclosure paragraph
                    $subHead = self::h('Disclosure Of Information Policy');
                    if ($kind === 'pdf') {
                        $rows[] = '<tr><td width="100%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . $subHead . '</td></tr>';
                        $rows[] = '<tr><td width="100%" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top; text-align:justify;">' . $disclosureHtml . '</td></tr>';
                    } else {
                        $rows[] = '<tr><td colspan="2" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . $subHead . '</td></tr>';
                        $rows[] = '<tr><td colspan="2" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top; text-align:justify;">' . $disclosureHtml . '</td></tr>';
                    }

                    // Signature block under disclosure
                    if ($kind === 'pdf') {
                        $rows[] = '<tr>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . ';">' . $sigVal . '</td>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . ';">' . $witVal . '</td>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . ';">' . $dateVal . '</td>'
                            . '</tr>';
                        $rows[] = '<tr>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; font-size:80%;">' . self::h('Parent / Guardian Signature') . '</td>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; font-size:80%;">' . self::h('Witness') . '</td>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; font-size:80%;">' . self::h('Date') . '</td>'
                            . '</tr>';
                    } else {
                        $innerVals = '<table style="width:100%; border-collapse:collapse; table-layout:fixed;"><tr>'
                            . '<td style="width:33.33%; padding:' . $pad . ';">' . $sigVal . '</td>'
                            . '<td style="width:33.33%; padding:' . $pad . ';">' . $witVal . '</td>'
                            . '<td style="width:33.33%; padding:' . $pad . ';">' . $dateVal . '</td>'
                            . '</tr></table>';
                        $rows[] = '<tr><td colspan="2" style="padding:0; border-top:' . $b . ';">' . $innerVals . '</td></tr>';

                        $innerLabs = '<table style="width:100%; border-collapse:collapse; table-layout:fixed;"><tr>'
                            . '<td style="width:33.33%; padding:' . $pad . '; font-weight:bold; font-size:80%;">' . self::h('Parent / Guardian Signature') . '</td>'
                            . '<td style="width:33.33%; padding:' . $pad . '; font-weight:bold; font-size:80%;">' . self::h('Witness') . '</td>'
                            . '<td style="width:33.33%; padding:' . $pad . '; font-weight:bold; font-size:80%;">' . self::h('Date') . '</td>'
                            . '</tr></table>';
                        $rows[] = '<tr><td colspan="2" style="padding:0; border-top:' . $b . ';">' . $innerLabs . '</td></tr>';
                    }

                    // Info sharing consent row
                    $infoLabel = self::h('I consent to reciprocal exchange of information about my child between GRASP, the school and Toronto Children’s Services.');
                    $infoValHtml = self::displayValue($infoDisplay);
                    if ($kind === 'pdf') {
                        $rows[] = '<tr>'
                            . '<td width="65%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . $infoLabel . '</td>'
                            . '<td width="35%" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top;">' . $infoValHtml . '</td>'
                            . '</tr>';
                    } else {
                        $inner = '<table style="width:100%; border-collapse:collapse; table-layout:fixed;"><tr>'
                            . '<td style="width:65%; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . $infoLabel . '</td>'
                            . '<td style="width:35%; padding:' . $pad . '; vertical-align:top;">' . $infoValHtml . '</td>'
                            . '</tr></table>';
                        $rows[] = '<tr><td colspan="2" style="padding:0; border-top:' . $b . ';">' . $inner . '</td></tr>';
                    }

                    // Travel heading + paragraph
                    $travelHead = self::h('Travel Consent Parents Authorization');
                    if ($kind === 'pdf') {
                        $rows[] = '<tr><td width="100%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold;">' . $travelHead . '</td></tr>';
                        $rows[] = '<tr><td width="100%" style="border-top:' . $b . '; padding:' . $pad . '; text-align:justify;">' . $travelHtml . '</td></tr>';
                    } else {
                        $rows[] = '<tr><td colspan="2" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold;">' . $travelHead . '</td></tr>';
                        $rows[] = '<tr><td colspan="2" style="border-top:' . $b . '; padding:' . $pad . '; text-align:justify;">' . $travelHtml . '</td></tr>';
                    }

                    // Travel consent row (50/50)
                    $travelLabel = self::h('I give consent for my child to leave GRASP premises for local outings with qualified staff.');
                    $travelValHtml = self::displayValue($travelDisplay);
                    if ($kind === 'pdf') {
                        $rows[] = '<tr>'
                            . '<td width="50%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . $travelLabel . '</td>'
                            . '<td width="50%" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top;">' . $travelValHtml . '</td>'
                            . '</tr>';
                    } else {
                        $inner = '<table style="width:100%; border-collapse:collapse; table-layout:fixed;"><tr>'
                            . '<td style="width:50%; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . $travelLabel . '</td>'
                            . '<td style="width:50%; padding:' . $pad . '; vertical-align:top;">' . $travelValHtml . '</td>'
                            . '</tr></table>';
                        $rows[] = '<tr><td colspan="2" style="padding:0; border-top:' . $b . ';">' . $inner . '</td></tr>';
                    }

                    // Photo heading
                    $photoHead = self::h('Photo / Media Release');
                    if ($kind === 'pdf') {
                        $rows[] = '<tr><td width="100%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold;">' . $photoHead . '</td></tr>';
                    } else {
                        $rows[] = '<tr><td colspan="2" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold;">' . $photoHead . '</td></tr>';
                    }

                    // Photo/media row (50/50)
                    $photoLabel = self::h('Photo / media release for GRASP activities and promotional materials (see handbook for full wording).');
                    $photoValHtml = is_string($photoDisplay) ? self::displayValue($photoDisplay) : $photoDisplay;
                    if ($kind === 'pdf') {
                        $rows[] = '<tr>'
                            . '<td width="50%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . $photoLabel . '</td>'
                            . '<td width="50%" style="border-top:' . $b . '; padding:' . $pad . '; vertical-align:top;">' . $photoValHtml . '</td>'
                            . '</tr>';
                    } else {
                        $inner = '<table style="width:100%; border-collapse:collapse; table-layout:fixed;"><tr>'
                            . '<td style="width:50%; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . $photoLabel . '</td>'
                            . '<td style="width:50%; padding:' . $pad . '; vertical-align:top;">' . $photoValHtml . '</td>'
                            . '</tr></table>';
                        $rows[] = '<tr><td colspan="2" style="padding:0; border-top:' . $b . ';">' . $inner . '</td></tr>';
                    }

                    // Final signature block
                    if ($kind === 'pdf') {
                        $rows[] = '<tr>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . ';">' . $sigVal . '</td>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . ';">' . $witVal . '</td>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . ';">' . $dateVal . '</td>'
                            . '</tr>';
                        $rows[] = '<tr>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; font-size:80%;">' . self::h('Parent / Guardian Signature') . '</td>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; font-size:80%;">' . self::h('Witness') . '</td>'
                            . '<td width="33.33%" style="border-top:' . $b . '; padding:' . $pad . '; font-weight:bold; font-size:80%;">' . self::h('Date') . '</td>'
                            . '</tr>';
                    } else {
                        $innerVals = '<table style="width:100%; border-collapse:collapse; table-layout:fixed;"><tr>'
                            . '<td style="width:33.33%; padding:' . $pad . ';">' . $sigVal . '</td>'
                            . '<td style="width:33.33%; padding:' . $pad . ';">' . $witVal . '</td>'
                            . '<td style="width:33.33%; padding:' . $pad . ';">' . $dateVal . '</td>'
                            . '</tr></table>';
                        $rows[] = '<tr><td colspan="2" style="padding:0; border-top:' . $b . ';">' . $innerVals . '</td></tr>';

                        $innerLabs = '<table style="width:100%; border-collapse:collapse; table-layout:fixed;"><tr>'
                            . '<td style="width:33.33%; padding:' . $pad . '; font-weight:bold; font-size:80%;">' . self::h('Parent / Guardian Signature') . '</td>'
                            . '<td style="width:33.33%; padding:' . $pad . '; font-weight:bold; font-size:80%;">' . self::h('Witness') . '</td>'
                            . '<td style="width:33.33%; padding:' . $pad . '; font-weight:bold; font-size:80%;">' . self::h('Date') . '</td>'
                            . '</tr></table>';
                        $rows[] = '<tr><td colspan="2" style="padding:0; border-top:' . $b . ';">' . $innerLabs . '</td></tr>';
                    }

                    $rowsHtml = implode("\n", $rows);
                    if (trim($rowsHtml) !== '') {
                        $out[] = str_replace(
                            ['{{SECTION_TITLE}}', '{{ROWS}}'],
                            [self::h('Information Sharing, Travel & Photo / Media'), $rowsHtml],
                            $sectionTpl
                        );
                    }

                    continue;
                }
            }
// -----------------------------------------------------------------
            // Enrollment-only layout compaction (email + PDF):
            // Parent / Guardian Information
            //
            // Combine the "Parent / Guardian 1" + "Parent / Guardian 2 (optional)"
            // sections into a single 3-column matrix.
            // -----------------------------------------------------------------
            if ($profile === 'enrollment' && $titleTrim !== '') {
                $normTitle = str_replace(["\u{2019}", "’"], "'", $titleTrim);

                if ($normTitle === 'Parent / Guardian 1' && ($i + 1) < $n) {
                    $next = $sections[$i + 1];
                    if (is_array($next)) {
                        $nextTitle = $next['title'] ?? ($next['sectionTitle'] ?? '');
                        $nextTrim = is_string($nextTitle) ? trim($nextTitle) : '';
                        $nextNorm = str_replace(["\u{2019}", "’"], "'", $nextTrim);

                        if (strpos($nextNorm, 'Parent / Guardian 2') === 0) {
                            $p1Fields = $fields;
                            $p2Fields = is_array($next['fields'] ?? null) ? $next['fields'] : [];

                            $rowsHtml = self::renderEnrollmentParentGuardianMatrix($kind, $p1Fields, $p2Fields, $data);
                            if (trim($rowsHtml) !== '') {
                                $out[] = str_replace(
                                    ['{{SECTION_TITLE}}', '{{ROWS}}'],
                                    [self::h('Parent / Guardian Information'), $rowsHtml],
                                    $sectionTpl
                                );
                            }

                            $i++; // skip Parent / Guardian 2 (consumed)
                            continue;
                        }
                    }
                }
            }


            // -----------------------------------------------------------------
            // Enrollment-only layout compaction (email + PDF):
            // Doctor & Allergy Information
            //
            // Desired layout (4 columns, 4 rows):
            //   1) Doctor's Name | value | Doctor's Phone # | value
            //   2) Doctor's Address | (merged 3 cols: street, unit, <br> city, province, postal)
            //   3) Does your child have any known allergies? | value | Symptoms to look for with allergy | value
            //   4) Treatment for allergy | value | Epipen Required? | value
            //
            // NOTE: Ensure the derived postal code exists (doctor_postal_code).
            // -----------------------------------------------------------------
            if ($profile === 'enrollment' && $titleTrim !== '') {
                $normTitle = str_replace(["\u{2019}", "’"], "'", $titleTrim);

                if ($normTitle === 'Doctor & Allergy Information') {
                    $map = self::mapFieldsByName($fields);
                    $eff = $data;

                    // Ensure derived postal code exists (some submissions may only include postal1/postal2)
                    if (!isset($eff['doctor_postal_code']) || trim((string)$eff['doctor_postal_code']) === '') {
                        $p1 = trim((string)($eff['doctor_postal1'] ?? ''));
                        $p2 = trim((string)($eff['doctor_postal2'] ?? ''));
                        if ($p1 !== '' || $p2 !== '') {
                            $eff['doctor_postal_code'] = trim(strtoupper(trim($p1 . ' ' . $p2)));
                        }
                    }

                    $b = self::borderTop($kind);
                    $pad = ($kind === 'pdf') ? '5px 6px' : '7px 8px';

                    $labelStyle = 'font-weight:bold; vertical-align:top;';
                    $valueStyle = 'text-align:left; vertical-align:top;';

                    // Row 1
                    $doctorNameHtml = self::displayFieldValueHtml($kind, $map['doctor_name'] ?? null, $eff);
                    $doctorPhoneHtml = self::displayFieldValueHtml($kind, $map['doctor_phone'] ?? null, $eff);

                    // Row 2
                    $streetHtml = self::displayFieldValueHtml($kind, $map['doctor_street'] ?? null, $eff);
                    $unitHtml = self::displayFieldValueHtml($kind, $map['doctor_unit'] ?? null, $eff);
                    $cityHtml = self::displayFieldValueHtml($kind, $map['doctor_city'] ?? null, $eff);
                    $provHtml = self::displayFieldValueHtml($kind, $map['doctor_province'] ?? null, $eff);
                    $postalHtml = self::displayFieldValueHtml($kind, $map['doctor_postal_code'] ?? null, $eff);

                    $line1 = $streetHtml . ', ' . $unitHtml;
                    $line2 = $cityHtml . ', ' . $provHtml . ', ' . $postalHtml;

                    // Try to keep the address on a single line if it is short enough.
                    $addrHtml = $line1 . '<br />' . $line2;
                    $line1Text = trim(strip_tags($line1));
                    $line2Text = trim(strip_tags($line2));
                    $comboText = $line1Text . ', ' . $line2Text;
                    $len = function_exists('mb_strlen') ? mb_strlen($comboText) : strlen($comboText);
                    if ($len <= 72) {
                        $addrHtml = $line1 . ', ' . $line2;
                    }

                    // Row 3 + 4
                    $allergiesHtml = self::displayFieldValueHtml($kind, $map['child_allergies'] ?? null, $eff);
                    $symptomsHtml = self::displayFieldValueHtml($kind, $map['allergy_symptoms'] ?? null, $eff);
                    $treatmentHtml = self::displayFieldValueHtml($kind, $map['allergy_treatment'] ?? null, $eff);
                    $epipenHtml = self::displayFieldValueHtml($kind, $map['epipen_required'] ?? null, $eff);

                    $rows = [];

                    $btFirst = ($kind === 'pdf')
                        ? 'border-top:none; border-top-width:0;'
                        : 'border-top:' . $b . ';';

                    $rows[] = '<tr>'
                        . '<td style="width:25%; padding:' . $pad . '; ' . $btFirst . ' ' . $labelStyle . '">' . self::h("Doctor's Name") . '</td>'
                        . '<td style="width:25%; padding:' . $pad . '; ' . $btFirst . ' ' . $valueStyle . '">' . $doctorNameHtml . '</td>'
                        . '<td style="width:25%; padding:' . $pad . '; ' . $btFirst . ' ' . $labelStyle . '">' . self::h("Doctor's Phone #") . '</td>'
                        . '<td style="width:25%; padding:' . $pad . '; ' . $btFirst . ' ' . $valueStyle . '">' . $doctorPhoneHtml . '</td>'
                        . '</tr>';

                    $rows[] = '<tr>'
                        . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $labelStyle . '">' . self::h("Doctor's Address") . '</td>'
                        . '<td colspan="3" style="width:75%; padding:' . $pad . '; border-top:' . $b . '; ' . $valueStyle . '">' . $addrHtml . '</td>'
                        . '</tr>';

                    $rows[] = '<tr>'
                        . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $labelStyle . '">' . self::h('Does your child have any known allergies?') . '</td>'
                        . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $valueStyle . '">' . $allergiesHtml . '</td>'
                        . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $labelStyle . '">' . self::h('Symptoms to look for with allergy') . '</td>'
                        . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $valueStyle . '">' . $symptomsHtml . '</td>'
                        . '</tr>';

                    $rows[] = '<tr>'
                        . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $labelStyle . '">' . self::h('Treatment for allergy') . '</td>'
                        . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $valueStyle . '">' . $treatmentHtml . '</td>'
                        . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $labelStyle . '">' . self::h('Epipen Required?') . '</td>'
                        . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $valueStyle . '">' . $epipenHtml . '</td>'
                        . '</tr>';

                    $rowsHtml = implode("\n", $rows);
                    if (trim($rowsHtml) !== '') {
                        $out[] = str_replace(
                            ['{{SECTION_TITLE}}', '{{ROWS}}'],
                            [self::h('Doctor and Allergy Information'), $rowsHtml],
                            $sectionTpl
                        );
                    }
                    continue;
                }
            }

            // -----------------------------------------------------------------
            // Enrollment-only: Emergency & Authorized Pickups (compact 3-column layout)
            if ($profile === 'enrollment' && $titleTrim === 'Emergency & Authorized Pickups') {
              $rows = self::renderEnrollmentEmergencyAuthorizedPickupsSection(
                $kind,
                $fields,
                $data,
                (!empty($section['contentBlocks']) && is_array($section['contentBlocks'])) ? $section['contentBlocks'] : []
              );

              if (trim($rows) !== '') {
                $out[] = str_replace(
                  ['{{SECTION_TITLE}}', '{{ROWS}}'],
                  [self::h((string)$title), $rows],
                  $sectionTpl
                );
              }
              continue;
            }

            // -----------------------------------------------------------------
            // Enrollment-only: General Health (custom compact layout)
            if ($profile === 'enrollment' && $titleTrim === 'General Health') {
                $rows = self::renderEnrollmentGeneralHealthSection($kind, $fields, $data);
                if (trim($rows) !== '') {
                    $out[] = str_replace(
                        ['{{SECTION_TITLE}}', '{{ROWS}}'],
                        [self::h((string)$title), $rows],
                        $sectionTpl
                    );
                }
                continue;
            }


            // -----------------------------------------------------------------
            // Enrollment-only: Medical Release & Medication (render last rows as 60/40)
            if ($profile === 'enrollment' && $titleTrim === 'Medical Release & Medication') {
                $contentRows = '';
                if (!empty($section['contentBlocks']) && is_array($section['contentBlocks'])) {
                    $contentRows = self::renderContentBlocks($kind, $section['contentBlocks'], $data);
                }

                $rows = trim($contentRows) === ''
                    ? self::renderRows60_40($kind, $fields, $data)
                    : ($contentRows . "\n" . self::renderRows60_40($kind, $fields, $data));

                if (trim($rows) !== '') {
                    $out[] = str_replace(
                        ['{{SECTION_TITLE}}', '{{ROWS}}'],
                        [self::h((string)$title), $rows],
                        $sectionTpl
                    );
                }
                continue;
            }

            // -----------------------------------------------------------------
            // Special case: Waitlist Parents/Guardians.
            // Email + PDF: render as 2 columns when both parent blocks exist.
            // -----------------------------------------------------------------
            if (is_string($title) && $titleTrim === 'Parents / Guardians') {
                $split = self::splitWaitlistGuardians($fields);
                if (count($split) > 0) {
                    if ($profile === 'waitlist' && count($split) === 2) {
                        $rows = self::renderWaitlistGuardiansTwoCol($kind, $split, $data);
                        if (trim($rows) !== '') {
                            $out[] = str_replace(
                                ['{{SECTION_TITLE}}', '{{ROWS}}'],
                                [self::h('Parents / Guardians'), $rows],
                                $sectionTpl
                            );
                        }
                        continue;
                    }

                    // Fallback: two stacked sections
                    foreach ($split as $sub) {
                        $rows = self::renderRows($kind, $sub['fields'], $data);
                        if (trim($rows) === '') continue;
                        $out[] = str_replace(
                            ['{{SECTION_TITLE}}', '{{ROWS}}'],
                            [self::h($sub['title']), $rows],
                            $sectionTpl
                        );
                    }
                    continue;
                }
            }

            // -----------------------------------------------------------------
            // Special case: Waitlist Address + Current Attendance sentence layout (email + PDF)
            // (Address-only override still used when Address isn't combined with Child Info)
            // -----------------------------------------------------------------
            if ($profile === 'waitlist' && is_string($title) && $titleTrim !== '') {
                // Address override
                if ($titleTrim === 'Address') {
                    $map = self::mapFieldsByName($fields);
                    $rows = [];

                    // One row, 4 cells (each cell contains label + value)
                    $rows[] = self::renderWaitlistFourColRow(
                        $kind,
                        $map['parent1_home_street'] ?? null,
                        $map['parent1_home_unit'] ?? null,
                        $map['parent1_home_city'] ?? null,
                        $map['parent1_postal_code'] ?? null,
                        $data,
                        ['postalLabel' => 'Postal Code']
                    );

                    // Home phone row: value aligned left, closer to label
                    if (isset($map['parent1_phones']) && is_array($map['parent1_phones'])) {
                        $rows[] = self::renderWaitlistHomePhoneRowLeft($kind, $map['parent1_phones'], $data);
                    }

                    $rowsHtml = implode("
", array_filter($rows, function ($r) { return trim((string)$r) !== ''; }));
                    if (trim($rowsHtml) !== '') {
                        $out[] = str_replace(
                            ['{{SECTION_TITLE}}', '{{ROWS}}'],
                            [self::h('Address'), $rowsHtml],
                            $sectionTpl
                        );
                    }
                    continue;
                }

                // Current Attendance override (allow for custom title variations)
                if (stripos($titleTrim, 'Current Attendance') === 0) {
                    $map = self::mapFieldsByName($fields);

                    $vDaycare = self::getFieldValue($map['currently_attends_daycare'] ?? null, $data);
                    $vSchool  = self::getFieldValue($map['currently_attending_school'] ?? null, $data);
                    $vWill    = self::getFieldValue($map['will_attend_when_require_care'] ?? null, $data);

                    $vDaycare = ($vDaycare === '' ? 'none' : $vDaycare);
                    $vSchool  = ($vSchool === '' ? 'none' : $vSchool);
                    $vWill    = ($vWill === '' ? 'none' : $vWill);

                    // Render as a single, compact sentence row.
                    // Values are underlined; "when we require care at GRASP" is bold.
                    $rowsHtml = self::renderWaitlistCurrentAttendanceOneRow($kind, $vDaycare, $vSchool, $vWill);
                    if (trim($rowsHtml) !== '') {
                        $out[] = str_replace(
                            ['{{SECTION_TITLE}}', '{{ROWS}}'],
                            [self::h((string)$titleTrim), $rowsHtml],
                            $sectionTpl
                        );
                    }
                    continue;
                }
            }



            // -----------------------------------------------------------------
            // Enrollment-only: Water Play & Hand Sanitizer
            //
            // Expected layout:
            //  - Block 1 heading + paragraph
            //  - Water play consent row (75/25)
            //  - Block 2 heading + paragraph
            //  - Hand sanitizer consent row (75/25)
            //
            // Default renderer places ALL contentBlocks first, then ALL fields.
            // This override interleaves blocks and their corresponding fields
            // to match the original PDF layout.
            // -----------------------------------------------------------------
            if ($profile === 'enrollment' && $titleTrim === 'Water Play & Hand Sanitizer') {
                $blocks = (!empty($section['contentBlocks']) && is_array($section['contentBlocks'])) ? $section['contentBlocks'] : [];
                $map = self::mapFieldsByName($fields);

                $rowsOut = [];

                // Block 1
                if (isset($blocks[0]) && is_array($blocks[0])) {
                    $rowsOut[] = self::renderContentBlocks($kind, [$blocks[0]], $data);
                }
                // Row 1: water play consent
                if (isset($map['water_play_consent']) && is_array($map['water_play_consent'])) {
                    $rowsOut[] = self::renderEnrollmentRow75_25($kind, $map['water_play_consent'], $data);
                }

                // Block 2
                if (isset($blocks[1]) && is_array($blocks[1])) {
                    $rowsOut[] = self::renderContentBlocks($kind, [$blocks[1]], $data);
                }
                // Row 2: hand sanitizer consent
                if (isset($map['hand_sanitizer_consent']) && is_array($map['hand_sanitizer_consent'])) {
                    $rowsOut[] = self::renderEnrollmentRow75_25($kind, $map['hand_sanitizer_consent'], $data);
                }

                $rowsHtml = implode("
", array_filter($rowsOut, function ($r) { return trim((string)$r) !== ''; }));
                if (trim($rowsHtml) !== '') {
                    $out[] = str_replace(
                        ['{{SECTION_TITLE}}', '{{ROWS}}'],
                        [self::h((string)$title), $rowsHtml],
                        $sectionTpl
                    );
                }
                continue;
            }
            // Default render path (supports contentBlocks)
            $contentRows = '';
            if (!empty($section['contentBlocks']) && is_array($section['contentBlocks'])) {
                $contentRows = self::renderContentBlocks($kind, $section['contentBlocks'], $data);
            }
            $rows = trim($contentRows) === ''
                ? self::renderRows($kind, $fields, $data)
                : ($contentRows . "
" . self::renderRows($kind, $fields, $data));

            // Enrollment PDF page-1 parity: suppress the first field-row top border for key sections
            // so the section header divider remains the single top rule.
            if ($profile === 'enrollment' && $kind === 'pdf') {
                $tNorm = str_replace(["\u{2019}", "’"], "'", $titleTrim);
                if ($tNorm === "Child's Primary Information") {
                    $rows = self::suppressFirstRowTopBorderPdf($rows);
                }
            }

            if (trim($rows) === '') continue;

            $out[] = str_replace(
                ['{{SECTION_TITLE}}', '{{ROWS}}'],
                [self::h((string)$title), $rows],
                $sectionTpl
            );
        }

        return implode("
", $out);
    }

    private static function renderFromConfigInternal(string $kind, string $configPath, array $data, array $meta = []): string
    {
        $data = self::preprocessData($data);
        if (!file_exists($configPath)) {
            throw new Exception("Config not found: " . $configPath);
        }

        $json = file_get_contents($configPath);
        $cfg = json_decode($json, true);
        if (!is_array($cfg)) {
            throw new Exception("Invalid config JSON: " . $configPath);
        }

        $formTitle = $meta['formTitle'] ?? ($cfg['title'] ?? ($cfg['formTitle'] ?? 'GRASP Form Submission'));
        $submittedAt = $meta['submittedAt'] ?? date('F j, Y, g:i a');

        // Optional: append session ID to the Submitted line (used as a unique reference)
        $sessionId = isset($meta['sessionId']) ? trim((string)$meta['sessionId']) : '';
        if ($sessionId !== '' && stripos((string)$submittedAt, 'session ID') === false) {
            $submittedAt = rtrim((string)$submittedAt);
            $submittedAt .= ' * session ID: ' . $sessionId . '.';
        }

        // Optional: GRASP contact info line under the Submitted line (must fit on one line)
        $orgBlock = '';
        if (in_array(($meta['templateProfile'] ?? ''), ['waitlist','enrollment'], true)) {
            if ($kind === 'pdf') {
                $orgBlock = '<tr>'
                  . '<td style="font-size:6.8pt; color:#555; padding-bottom:0px; line-height:1.05;">'
                  . '<nobr>Greenland Recreational After School Program&nbsp;*&nbsp;15 Greenland Road, Toronto, ON M3C 1N1&nbsp;*&nbsp;416-444-7427&nbsp;*&nbsp;info@greenlandrecreational.com</nobr>'
                  . '</td>'
                  . '</tr>'
                  . '<tr><td style="height:2px; font-size:1px; line-height:1px;">&nbsp;</td></tr>';
            } else {
                $orgBlock = '<div style="font-size:11px; color:#555; margin-top:2px; margin-bottom:3px; line-height:1.2;">'
                  . '<nobr>Greenland Recreational After School Program&nbsp;*&nbsp;15 Greenland Road, Toronto, ON M3C 1N1&nbsp;*&nbsp;416-444-7427&nbsp;*&nbsp;info@greenlandrecreational.com</nobr>'
                  . '</div>';
            }
        }


        // Config schema support:
// - Newer configs: { title, steps:[{title, groups:[{title, fields:[...]}]}] }
// - Legacy: { sections:[{title, fields:[...]}] } or { fields:[...] }
$sections = null;

// Prefer steps/groups (current front-end config format)
if (!empty($cfg['steps']) && is_array($cfg['steps'])) {
    $sections = [];
    foreach ($cfg['steps'] as $step) {
        if (!is_array($step)) continue;
        $groups = $step['groups'] ?? [];
        if (!is_array($groups)) continue;
        foreach ($groups as $group) {
            if (!is_array($group)) continue;
            $gTitle = $group['title'] ?? ($step['title'] ?? 'Section');
            $gFields = $group['fields'] ?? [];
            if (!is_array($gFields) || count($gFields) === 0) continue;
            $sections[] = [
                'title'  => $gTitle,
                'fields' => $gFields,
                'contentBlocks' => (isset($group['contentBlocks']) && is_array($group['contentBlocks'])) ? $group['contentBlocks'] : []
            ];
        }
    }
}

// Legacy sections
if (!is_array($sections) || count($sections) === 0) {
    $sections = $cfg['sections'] ?? null;
}

// Single-section legacy fallback
if (!is_array($sections)) {
    $sections = [
        [
            'title'  => 'Form Details',
            'fields' => $cfg['fields'] ?? []
        ]
    ];
}

$content = self::renderSections($kind, $sections, $data, $meta);

        $baseTpl = self::loadTemplate($kind, 'base');
        return str_replace(
            ['{{FORM_TITLE}}', '{{SUBMITTED_AT}}', '{{ORG_BLOCK}}', '{{CONTENT}}'],
            [self::h((string)$formTitle), self::h((string)$submittedAt), $orgBlock, $content],
            $baseTpl
        );
    }

    // Public API

    public static function renderFromConfig(string $configPath, array $data, array $meta = []): string
    {
        return self::renderFromConfigInternal('email', $configPath, $data, $meta);
    }

    public static function renderPdfFromConfig(string $configPath, array $data, array $meta = []): string
    {
        return self::renderFromConfigInternal('pdf', $configPath, $data, $meta);
    }

    public static function renderParentManualWithAttachmentNotice(string $configPath, array $data, array $meta = []): string
    {
        $html = self::renderFromConfig($configPath, $data, $meta);
        // Insert notice just above footer by adding it before the closing outer table.
        $notice = '<div style="margin:10px 0 0 0; font-size:12px; color:#333;"><b>Attachment:</b> The completed Parent Manual PDF is attached to this email.</div>';
        return str_replace('{{CONTENT}}', $notice . '{{CONTENT}}', $html);
    }


  // -------------------------
  // Waitlist compact helpers
  // -------------------------

  private static function mapFieldsByName(array $fields): array {
    $map = [];
    foreach ($fields as $f) {
      if (!is_array($f)) continue;
      $name = isset($f['name']) ? (string)$f['name'] : '';
      if ($name === '') continue;
      $map[$name] = $f;
    }
    return $map;
  }

  private static function getFieldValue(?array $field, array $data): string {
    if (!$field) return '';
    $name = isset($field['name']) ? (string)$field['name'] : '';
    if ($name === '') return '';
    if (!array_key_exists($name, $data)) return '';
    $v = $data[$name];

    if (is_bool($v)) return $v ? 'Yes' : 'No';
    if (is_array($v)) {
      // join array values on newline (rare in Waitlist)
      $parts = [];
      foreach ($v as $x) {
        $s = trim((string)$x);
        if ($s !== '') $parts[] = $s;
      }
      return implode("\n", $parts);
    }
    return trim((string)$v);
  }

  private static function borderTop(string $kind): string {
    return ($kind === 'pdf') ? '0.5pt solid #333' : '1px solid #333';
  }

  /**
   * Enrollment PDF: the section heading already renders its own divider line.
   * For parity with the original form, suppress the top border on the first rendered field row
   * to avoid stacked/double rules in TCPDF.
   */
  private static function suppressFirstRowTopBorderPdf(string $rowsHtml): string {
    $rowsHtml = (string)$rowsHtml;
    if (trim($rowsHtml) === '') return $rowsHtml;

    // Only touch the very first <tr>...</tr> block.
    if (!preg_match('/<tr\b[\s\S]*?<\/tr>/i', $rowsHtml, $m)) return $rowsHtml;
    $firstTr = $m[0];

    // Replace the first row's border-top rule(s) with none.
    $firstTr2 = str_replace('border-top:0.5pt solid #333;', 'border-top:none; border-top-width:0;', $firstTr);

    return preg_replace('/<tr\b[\s\S]*?<\/tr>/i', addcslashes($firstTr2, '\\$'), $rowsHtml, 1);
  }

  // ----------------------------
  // Enrollment compact helpers
  // ----------------------------

  private static function displayFieldValueHtml(string $kind, ?array $field, array $data): string {
    if (!$field) {
      return self::displayValue('');
    }
    $name = isset($field['name']) ? (string)$field['name'] : '';
    if ($name === '') {
      return self::displayValue('');
    }
    $raw = $data[$name] ?? '';
    $normalized = self::normalizeFieldValue($field, $raw);
    return self::displayValue($normalized);
  }

  private static function fieldLabelOverride(?array $field, array $opts = []): string {
    if (!$field) return '';
    $name = isset($field['name']) ? (string)$field['name'] : '';
    $over = $opts['labelOverrides'] ?? [];
    if ($name !== '' && is_array($over) && isset($over[$name])) {
      return (string)$over[$name];
    }
    return (string)($field['label'] ?? '');
  }

  private static function renderEnrollmentThreeColRow(
    string $kind,
    ?array $f1,
    ?array $f2,
    ?array $f3,
    array $data,
    array $opts = []
  ): string {
    $b = self::borderTop($kind);
    if (!empty($opts['noTopBorder'])) { $b = ($kind === 'pdf') ? 'none' : '0'; }
    $pad = ($kind === 'pdf') ? '5px 6px' : '7px 8px';

    $cells = [
      [$f1, '33.33%'],
      [$f2, '33.33%'],
      [$f3, '33.34%'],
    ];

    $tds = [];
    foreach ($cells as $pair) {
      [$f, $w] = $pair;
      $name = is_array($f) && isset($f['name']) ? (string)$f['name'] : '';
      $label = self::fieldLabelOverride($f, $opts);
      $labelHtml = self::h(trim($label));
      $valueHtml = self::displayFieldValueHtml($kind, $f, $data);

      // PDF-only: prevent the middle label from wrapping by using a <nobr> wrapper.
      if ($kind === 'pdf' && $name === 'child_middle_name_or_initial') {
        $labelHtml = '<nobr>' . $labelHtml . '</nobr>';
      }

      $labelStyle = 'font-weight:bold; vertical-align:top;';
      $valueStyle = 'text-align:left; vertical-align:top;';
      if ($kind === 'email') {
        $labelStyle .= ' width:1%; white-space:nowrap; padding-right:10px;';
        $valueStyle = 'text-align:left; vertical-align:top; width:99%;';
      }
      // Ensure the middle-name label stays on one line in compact layouts.
      if ($name === 'child_middle_name_or_initial') {
        $labelStyle .= ($kind === 'pdf')
          ? ' font-size:8.2pt; white-space:nowrap;'
          : ' font-size:12px; white-space:nowrap;';
      }

      $tds[] = '<td style="width:' . $w . '; padding:' . $pad . '; border-top:' . $b . '; vertical-align:top;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
          . '<tr>'
            . '<td style="' . $labelStyle . '">' . $labelHtml . '</td>'
            . '<td style="' . $valueStyle . '">' . $valueHtml . '</td>'
          . '</tr>'
        . '</table>'
      . '</td>';
    }

    return "<tr>\n" . implode("\n", $tds) . "\n</tr>";
  }

  private static function renderEnrollmentTwoColRow(
    string $kind,
    ?array $f1,
    ?array $f2,
    array $data,
    array $opts = []
  ): string {
    $b = self::borderTop($kind);
    if (!empty($opts['noTopBorder'])) { $b = ($kind === 'pdf') ? 'none' : '0'; }
    $pad = ($kind === 'pdf') ? '5px 6px' : '7px 8px';

    // When a 2-column row follows a 3-column row in the same table, some email
    // clients treat the missing 3rd cell as an implicit empty column. This
    // causes the divider line (border-top) to stop under column 2. To keep the
    // divider line continuous across the full width, we can wrap this as a
    // single full-width cell (colspan=3) containing a nested 2-column table.
    $colspan3 = (bool)($opts['colspan3'] ?? false);

    if ($colspan3) {
      $makeInner = function (?array $f) use ($kind, $data, $opts) : string {
        $label = self::fieldLabelOverride($f, $opts);
        $labelHtml = self::h(trim($label));
        $valueHtml = self::displayFieldValueHtml($kind, $f, $data);

        $labelStyle = 'font-weight:bold; vertical-align:top;';
        $valueStyle = 'text-align:left; vertical-align:top;';
        if ($kind === 'email') {
          $labelStyle .= ' width:1%; white-space:nowrap; padding-right:10px;';
          $valueStyle = 'text-align:left; vertical-align:top; width:99%;';
        }

        return '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
          . '<tr>'
            . '<td style="' . $labelStyle . '">' . $labelHtml . '</td>'
            . '<td style="' . $valueStyle . '">' . $valueHtml . '</td>'
          . '</tr>'
        . '</table>';
      };

      $inner = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
        . '<tr>'
          . '<td style="width:50%; padding:' . $pad . '; vertical-align:top;">' . $makeInner($f1) . '</td>'
          . '<td style="width:50%; padding:' . $pad . '; vertical-align:top;">' . $makeInner($f2) . '</td>'
        . '</tr>'
      . '</table>';

      return "<tr>\n<td colspan=\"3\" style=\"padding:0; border-top:" . $b . "; vertical-align:top;\">" . $inner . "</td>\n</tr>";
    }

    $cells = [
      [$f1, '50%'],
      [$f2, '50%'],
    ];

    $tds = [];
    foreach ($cells as $pair) {
      [$f, $w] = $pair;
      $label = self::fieldLabelOverride($f, $opts);
      $labelHtml = self::h(trim($label));
      $valueHtml = self::displayFieldValueHtml($kind, $f, $data);

      $labelStyle = 'font-weight:bold; vertical-align:top;';
      $valueStyle = 'text-align:left; vertical-align:top;';
      if ($kind === 'email') {
        $labelStyle .= ' width:1%; white-space:nowrap; padding-right:10px;';
        $valueStyle = 'text-align:left; vertical-align:top; width:99%;';
      }

      $tds[] = '<td style="width:' . $w . '; padding:' . $pad . '; border-top:' . $b . '; vertical-align:top;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
          . '<tr>'
            . '<td style="' . $labelStyle . '">' . $labelHtml . '</td>'
            . '<td style="' . $valueStyle . '">' . $valueHtml . '</td>'
          . '</tr>'
        . '</table>'
      . '</td>';
    }

    return "<tr>\n" . implode("\n", $tds) . "\n</tr>";
  }

  private static function renderEnrollmentParentGuardianMatrix(string $kind, array $p1Fields, array $p2Fields, array $data): string
  {
    $p1 = self::mapFieldsByName($p1Fields);
    $p2 = self::mapFieldsByName($p2Fields);

    // Effective data (supports: "Parent / Guardian 2 home address same as Parent / Guardian 1")
    $eff = $data;

    // Ensure derived postal codes exist (some submissions may only include postal1/postal2)
    $derivePostal = function (string $codeKey, string $part1Key, string $part2Key) use (&$eff): void {
      if (isset($eff[$codeKey]) && trim((string)$eff[$codeKey]) !== '') return;
      $p1 = trim((string)($eff[$part1Key] ?? ''));
      $p2 = trim((string)($eff[$part2Key] ?? ''));
      if ($p1 !== '' || $p2 !== '') {
        $eff[$codeKey] = trim(strtoupper(trim($p1 . ' ' . $p2)));
      }
    };
    $derivePostal('parent1_postal_code', 'parent1_home_postal1', 'parent1_home_postal2');
    $derivePostal('parent2_postal_code', 'parent2_home_postal1', 'parent2_home_postal2');
    $derivePostal('parent1_work_postal_code', 'parent1_work_postal1', 'parent1_work_postal2');
    $derivePostal('parent2_work_postal_code', 'parent2_work_postal1', 'parent2_work_postal2');

    if (self::truthy($eff['parent2_home_same_as_parent1'] ?? '')) {
      $copy = [
        'parent2_home_street' => 'parent1_home_street',
        'parent2_home_unit' => 'parent1_home_unit',
        'parent2_home_city' => 'parent1_home_city',
        'parent2_home_province' => 'parent1_home_province',
        'parent2_postal_code' => 'parent1_postal_code',
        'parent2_home_address' => 'parent1_home_address',
      ];
      foreach ($copy as $k2 => $k1) {
        $v2 = trim((string)($eff[$k2] ?? ''));
        if ($v2 === '') {
          $eff[$k2] = $eff[$k1] ?? '';
        }
      }
    }

    $combineCityProvince = function (string $cityKey, string $provKey) use (&$eff): string {
      $city = trim((string)($eff[$cityKey] ?? ''));
      $prov = trim((string)($eff[$provKey] ?? ''));
      if ($city !== '' && $prov !== '') return $city . ', ' . $prov;
      return ($city !== '') ? $city : $prov;
    };

    $makeName = function (string $prefix) use (&$eff): string {
      $first = trim((string)($eff[$prefix . '_first_name'] ?? ''));
      $last = trim((string)($eff[$prefix . '_last_name'] ?? ''));
      $name = trim(trim($first . ' ' . $last));
      if ($name === '') {
        $name = trim((string)($eff[$prefix . '_name'] ?? ''));
      }
      return $name;
    };

    $b = self::borderTop($kind);
    $bgAttr = ($kind === 'pdf') ? ' bgcolor="#F3F3F3"' : '';

    // Match default section typography/padding:
    // - PDF: use table cellpadding=5 (same as section_enrollment inner table)
    // - Email: use padding 7px 10px (same as email row template)
    $tableCellpadding = ($kind === 'pdf') ? '0' : '0';
    $cellPad = ($kind === 'pdf') ? ' padding:5px 6px;' : ' padding:7px 10px;';

    $t = '<table width="100%" cellpadding="' . $tableCellpadding . '" cellspacing="0" style="border-collapse:collapse;">';

    // Column header row (3 columns) - NO inner vertical borders
    $hdrCommon = 'font-weight:bold; text-align:center; background:#f3f3f3;' . $cellPad . ' white-space:nowrap; border-bottom:' . $b . ';';
    $t .= '<tr>'
      . '<td width="38%"' . $bgAttr . ' style="' . $hdrCommon . '"></td>'
      . '<td width="31%"' . $bgAttr . ' style="' . $hdrCommon . '">' . self::h('Parent / Guardian 1') . '</td>'
      . '<td width="31%"' . $bgAttr . ' style="' . $hdrCommon . '">' . self::h('Parent / Guardian 2') . '</td>'
      . '</tr>';

    $row = function (string $label, string $v1Html, string $v2Html, bool $useTopBorder = true, bool $labelBold = true) use ($cellPad, $b, $kind): string {
      $bt = $useTopBorder ? ('border-top:' . $b . ';') : (($kind === 'pdf') ? 'border-top:none; border-top-style:none; border-top-width:0;' : 'border-top:0;');
      $labelCell = ($label === '') ? '&nbsp;' : self::h($label);

      $labelStyle = 'text-align:left; vertical-align:top;' . $cellPad . ' ' . $bt;
      if ($labelBold && $label !== '') {
        $labelStyle = 'font-weight:bold; ' . $labelStyle . ' white-space:nowrap;';
      }

      $vStyle = 'text-align:left; vertical-align:top;' . $cellPad . ' ' . $bt;

      return '<tr>'
        . '<td width="38%" style="' . $labelStyle . '">' . $labelCell . '</td>'
        . '<td width="31%" style="' . $vStyle . '">' . $v1Html . '</td>'
        . '<td width="31%" style="' . $vStyle . '">' . $v2Html . '</td>'
        . '</tr>';
    };

    $subheader = function (string $title) use ($cellPad, $b, $bgAttr): string {
      $style = 'font-weight:bold; text-align:left; background:#f3f3f3;' . $cellPad . ' border-top:' . $b . '; border-bottom:' . $b . ';';
      return '<tr><td colspan="3"' . $bgAttr . ' style="' . $style . '">' . self::h($title) . '</td></tr>';
    };

    // Name (First + Last) - inserted as first row (HIGH PRIORITY)
    $t .= $row(
      'Name',
      self::displayValue($makeName('parent1')),
      self::displayValue($makeName('parent2')),
      false
    );

    // E-mail
    $t .= $row(
      'E-mail Address',
      self::displayFieldValueHtml($kind, $p1['parent1_email'] ?? null, $eff),
      self::displayFieldValueHtml($kind, $p2['parent2_email'] ?? null, $eff)
    );


    // Address block (single row; 2-line cell contents)
    // - Unit/Apt/Suite: omit completely when blank (do not show "(blank)")
    // - If unit is present, append to the street line with ", "
    // - Postal Code: move to same line as Province with ", " (remove dedicated postal line)
    $formatAddr = function (?array $streetField, ?array $unitField, string $cityKey, string $provKey, string $postalKey) use ($kind, &$eff): array {
      $streetHtml = self::displayFieldValueHtml($kind, $streetField, $eff);

      $unitRaw = self::getFieldValue($unitField, $eff);
      if (trim($unitRaw) !== '') {
        $streetHtml .= ', ' . self::displayValue($unitRaw);
      }

      $city = trim((string)($eff[$cityKey] ?? ''));
      $prov = trim((string)($eff[$provKey] ?? ''));
      $postal = trim((string)($eff[$postalKey] ?? ''));

      $line2 = '';
      if ($city !== '' && $prov !== '') {
        $line2 = $city . ', ' . $prov;
      } else {
        $line2 = ($city !== '') ? $city : $prov;
      }

      if ($postal !== '') {
        $line2 = ($line2 !== '') ? ($line2 . ', ' . $postal) : $postal;
      }

      return [$streetHtml, self::displayValue($line2)];
    };

    [$a1Line1, $a1Line2] = $formatAddr(
      $p1['parent1_home_street'] ?? null,
      $p1['parent1_home_unit'] ?? null,
      'parent1_home_city',
      'parent1_home_province',
      'parent1_postal_code'
    );
    $addr1 = $a1Line1 . '<br />' . $a1Line2;

    [$a2Line1, $a2Line2] = $formatAddr(
      $p2['parent2_home_street'] ?? null,
      $p2['parent2_home_unit'] ?? null,
      'parent2_home_city',
      'parent2_home_province',
      'parent2_postal_code'
    );
    $addr2 = $a2Line1 . '<br />' . $a2Line2;

    $t .= $row('Address', $addr1, $addr2);

    // Phones
    $t .= $row(
      'Cell and Home #',
      self::displayFieldValueHtml($kind, $p1['parent1_phones'] ?? null, $eff),
      self::displayFieldValueHtml($kind, $p2['parent2_phones'] ?? null, $eff)
    );

    // Work/School subsection header
    $t .= $subheader('Parent / Guardian Work / School Information');


    // Work/School address block (single row; 2-line cell contents)
    // Same formatting rules as Home Address (Unit inline when present; Postal inline with Province)
    [$w1Line1, $w1Line2] = $formatAddr(
      $p1['parent1_work_street'] ?? null,
      $p1['parent1_work_unit'] ?? null,
      'parent1_work_city',
      'parent1_work_province',
      'parent1_work_postal_code'
    );
    $work1 = $w1Line1 . '<br />' . $w1Line2;

    [$w2Line1, $w2Line2] = $formatAddr(
      $p2['parent2_work_street'] ?? null,
      $p2['parent2_work_unit'] ?? null,
      'parent2_work_city',
      'parent2_work_province',
      'parent2_work_postal_code'
    );
    $work2 = $w2Line1 . '<br />' . $w2Line2;

    $t .= $row('Street Address', $work1, $work2, false);

    // Work/School phone
    $t .= $row(
      'Parent Work/School phone #',
      self::displayFieldValueHtml($kind, $p1['parent1_work_phone'] ?? null, $eff),
      self::displayFieldValueHtml($kind, $p2['parent2_work_phone'] ?? null, $eff)
    );

    $t .= '</table>';

    // PDF: bypass row_full wrapper to avoid injected padding/borders (prevents stacked rules + indentation)
    if ($kind === 'pdf') {
      return '<tr><td style="padding:0; border-top:none; border-top-style:none; border-top-width:0;">' . $t . '</td></tr>';
    }

    // Email: keep the shared wrapper for consistent spacing in email clients
    $rowFullTpl = self::loadTemplate($kind, 'row_full');
    return str_replace(
      ['{{STYLE}}', '{{CONTENT}}'],
      ['padding:0;', $t],
      $rowFullTpl
    );

  }


  private static function renderEnrollmentEmergencyAuthorizedPickupsSection(
    string $kind,
    array $fields,
    array $data,
    array $contentBlocks = []
  ): string {
    $byName = self::mapFieldsByName($fields);
    $b = self::borderTop($kind);
    $pad = ($kind === 'pdf') ? '5px 6px' : '7px 8px';

// Label/value styles (PDF compaction): keep headings bold and ~80% for compact labels/values
$labelStyle = 'font-weight:bold;';
if ($kind === 'pdf') {
  $labelStyle .= ' font-size:80%;';
}
$smallValueStyle = '';
if ($kind === 'pdf') {
  $smallValueStyle = 'font-size:80%; line-height:1.1;';
}


    // First row sits directly under the section header; avoid a double line by suppressing the first top border.
    $firstRow = true;
    $rowTop = function () use (&$firstRow, $b): string {
      $bt = $firstRow ? 'none' : $b;
      $firstRow = false;
      return $bt;
    };

    $rows = [];

    // Content block(s): e.g., "PERSON TO CALL IN CASE OF EMERGENCY"
    if (!empty($contentBlocks) && is_array($contentBlocks)) {
      foreach ($contentBlocks as $block) {
        if (!is_array($block)) continue;

        $style = isset($block['style']) ? trim((string)$block['style']) : '';
        if ($style !== '' && substr($style, -1) !== ';') {
          $style .= ';';
        }

        $bgcolorAttr = '';
        if ($kind === 'pdf') {
          if (preg_match('/(?:background|background-color)\s*:\s*(#[0-9a-fA-F]{3,6})/i', $style, $mm)) {
            $bg = strtoupper($mm[1]);
            $bgcolorAttr = ' bgcolor="' . $bg . '"';
          }
        }

        $html = '';
        if (!empty($block['html'])) {
          $html = (string)$block['html'];
        } elseif (!empty($block['text'])) {
          $html = nl2br(self::h(self::normalizeWhitespace((string)$block['text'])));
        }

        $html = self::replaceTokens($html, $data, $kind);
        if (trim($html) === '') continue;

        $bt = $rowTop();

        $rows[] =
          '<tr><td colspan="3"' .
          $bgcolorAttr .
          ' style="padding:' .
          $pad .
          '; border-top:' .
          $bt .
          '; vertical-align:top; ' .
          self::h($style) .
          '">' .
          $html .
          '</td></tr>';
      }
    }

    $fName = $byName['emergency_contact_name'] ?? null;
    $fRel = $byName['emergency_contact_relationship'] ?? null;
    $fPhone = $byName['emergency_contact_day_phone'] ?? null;
    $fAddr = $byName['emergency_contact_address'] ?? null;
    $fAuth = $byName['authorized_pickups'] ?? null;

    // Row 1: values (3 cols)
    $bt = $rowTop();

    $rows[] =
      '<tr>' .
      '<td style="width:33.33%; padding:' .
      $pad .
      '; border-top:' .
      $bt .
      '; vertical-align:top;">' .
      self::displayFieldValueHtml($kind, $fName, $data) .
      '</td>' .
      '<td style="width:33.33%; padding:' .
      $pad .
      '; border-top:' .
      $bt .
      '; vertical-align:top;">' .
      self::displayFieldValueHtml($kind, $fRel, $data) .
      '</td>' .
      '<td style="width:33.34%; padding:' .
      $pad .
      '; border-top:' .
      $bt .
      '; vertical-align:top;">' .
      self::displayFieldValueHtml($kind, $fPhone, $data) .
      '</td>' .
      '</tr>';

    // Row 2: labels (3 cols)
    $bt = $rowTop();

    $rows[] =
      '<tr>' .
      '<td style="width:33.33%; padding:' .
      $pad .
      '; border-top:' .
      $bt .
      '; vertical-align:top; ' .
      $labelStyle .
      '">' .
      self::h('Contact Name') .
      '</td>' .
      '<td style="width:33.33%; padding:' .
      $pad .
      '; border-top:' .
      $bt .
      '; vertical-align:top; ' .
      $labelStyle .
      '">' .
      self::h('Relationship To Child') .
      '</td>' .
      '<td style="width:33.34%; padding:' .
      $pad .
      '; border-top:' .
      $bt .
      '; vertical-align:top; ' .
      $labelStyle .
      '">' .
      self::h('Day Time Phone #') .
      '</td>' .
      '</tr>';

    // Row 3: day-time address label + value on one row (PDF parity request)
$bt = $rowTop();

$rows[] =
  '<tr>' .
  '<td style="width:40%; padding:' .
  $pad .
  '; border-top:' .
  $bt .
  '; vertical-align:top; ' .
  $labelStyle .
  '">' .
  self::h('Day time Address (incl. postal code)') .
  '</td>' .
  '<td colspan="2" style="width:60%; padding:' .
  $pad .
  '; border-top:' .
  $bt .
  '; vertical-align:top;">' .
  self::displayFieldValueHtml($kind, $fAddr, $data) .
  '</td>' .
  '</tr>';


    // Row 5: other authorized pickups label + value (merge cols 2-3)
    $bt = $rowTop();

    $rows[] =
      '<tr>' .
      '<td style="width:33.33%; padding:' .
      $pad .
      '; border-top:' .
      $bt .
      '; vertical-align:top; font-weight:bold;">' .
      self::h('Other Authorized Pickups') .
      '</td>' .
      '<td colspan="2" style="width:66.67%; padding:' .
      $pad .
      '; border-top:' .
      $bt .
      '; vertical-align:top; ' .
      $smallValueStyle .
      '">' .
      self::displayFieldValueHtml($kind, $fAuth, $data) .
      '</td>' .
      '</tr>';

    

    // Row 6-7: Signature / Date Signed / Witness (from Final Acknowledgement & Signature)
    $parentSigRaw = trim((string)($data['parent_full_name_signature'] ?? ''));
    if ($parentSigRaw === '') {
      $parentSigRaw = trim((string)($data['parent_signature'] ?? ''));
    }
    $dateSignedRaw = trim((string)($data['signature_date'] ?? ''));
    $witnessRaw = trim((string)($data['witness'] ?? ''));

    // Create a small visual gap between the values row and the label row for readability
    $padLabel = ($kind === 'pdf') ? '3px 6px 6px' : '3px 8px 8px';

    // Row 6: values (3 cols)
    $bt = $rowTop();

    $rows[] =
      '<tr>' .
      '<td style="width:33.33%; padding:' .
      $pad .
      '; border-top:' .
      $bt .
      '; border-bottom:none; border-bottom-style:none; border-bottom-width:0; vertical-align:top;">' .
      self::displayValue($parentSigRaw) .
      '</td>' .
      '<td style="width:33.33%; padding:' .
      $pad .
      '; border-top:' .
      $bt .
      '; border-bottom:none; border-bottom-style:none; border-bottom-width:0; vertical-align:top;">' .
      self::displayValue($dateSignedRaw) .
      '</td>' .
      '<td style="width:33.34%; padding:' .
      $pad .
      '; border-top:' .
      $bt .
      '; border-bottom:none; border-bottom-style:none; border-bottom-width:0; vertical-align:top;">' .
      self::displayValue($witnessRaw) .
      '</td>' .
      '</tr>';

    // Row 7: labels (3 cols)
    $bt = $rowTop();

    $rows[] =
      '<tr>' .
      '<td style="width:33.33%; padding:' .
      $padLabel .
      '; border-top:' .
      $bt .
      '; vertical-align:top; ' .
      $labelStyle .
      '">' .
      self::h('Parent / Guardian Signature') .
      '</td>' .
      '<td style="width:33.33%; padding:' .
      $padLabel .
      '; border-top:' .
      $bt .
      '; vertical-align:top; ' .
      $labelStyle .
      '">' .
      self::h('Date Signed') .
      '</td>' .
      '<td style="width:33.34%; padding:' .
      $padLabel .
      '; border-top:' .
      $bt .
      '; vertical-align:top; ' .
      $labelStyle .
      '">' .
      self::h('Witness') .
      '</td>' .
      '</tr>';
return implode("\n", $rows);
  }

private static function renderWaitlistFourColRow(
    string $kind,
    ?array $f1,
    ?array $f2,
    ?array $f3,
    ?array $f4,
    array $data,
    array $opts = []
  ): string {
    $b = self::borderTop($kind);

    $cells = [
      [$f1, null],
      [$f2, null],
      [$f3, null],
      [$f4, $opts['postalLabel'] ?? null],
    ];

    $tds = [];
    foreach ($cells as $i => $pair) {
      [$f, $labelOverride] = $pair;

      $label = $labelOverride ?? ($f['label'] ?? '');
      $value = self::getFieldValue($f, $data);
      if (trim($value) === '') $value = '(blank)';

      $labelHtml = self::h((string)$label);
      $valueHtml = self::h($value);

      $isLast = ($i === (count($cells) - 1));
      $rightBorder = ''; // Waitlist Address: remove vertical dividers to match overall horizontal-line aesthetic
      $tds[] = '<td style="width:25%; padding:'.($kind === 'pdf' ? '5px 6px' : '7px 8px').'; border-top:'.$b.'; '.$rightBorder.' vertical-align:top;">'
        .'<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
          .'<tr>'
            .'<td style="font-weight:bold; vertical-align:top;">'.$labelHtml.'</td>'
            .'<td style="text-align:right; vertical-align:top;">'.$valueHtml.'</td>'
          .'</tr>'
        .'</table>'
      .'</td>';
    }

    return "<tr>\n".implode("\n", $tds)."\n</tr>";
  }

  private static function renderWaitlistHomePhoneRowLeft(string $kind, array $field, array $data): string {
    $b = self::borderTop($kind);
    $label = $field['label'] ?? 'Home Phone #';
    $value = self::getFieldValue($field, $data);
    if (trim($value) === '') $value = '(blank)';

    $labelHtml = self::h((string)$label);
    $valueHtml = self::h($value);

    return '<tr>'
      .'<td colspan="4" style="padding:7px 10px; border-top:'.$b.'; vertical-align:top;">'
        .'<span style="font-weight:bold;">'.$labelHtml.'</span>'
        .'<span style="padding-left:10px;">'.$valueHtml.'</span>'
      .'</td>'
    .'</tr>';
  }

  private static function renderWaitlistSentenceFullRow(
    string $kind,
    string $prefix1,
    string $value1,
    string $mid,
    string $value2,
    string $suffix
  ): string {
    $b = self::borderTop($kind);

    $p1 = self::h($prefix1);
    $v1 = self::h(trim($value1) === '' ? 'none' : $value1);
    $midH = self::h($mid);
    $v2 = self::h(trim($value2) === '' ? 'none' : $value2);
    $suf = self::h($suffix);

    $content = '<span style="font-weight:bold;">'.$p1.'</span>'
      .'<span style="padding:0 6px;"><b>'.$v1.'</b></span>'
      .'<span>'.$midH.'</span>'
      .'<span style="padding:0 6px;"><b>'.$v2.'</b></span>'
      .'<span>'.$suf.'</span>';

    return '<tr><td colspan="2" style="padding:7px 10px; border-top:'.$b.'; vertical-align:top;">'.$content.'</td></tr>';
  }

  private static function renderWaitlistSentenceSingleValueRow(
    string $kind,
    string $prefix,
    string $value,
    string $suffix
  ): string {
    $b = self::borderTop($kind);

    $p = self::h($prefix);
    $v = self::h(trim($value) === '' ? 'none' : $value);
    $s = self::h($suffix);

    $content = '<span style="font-weight:bold;">'.$p.'</span>'
      .'<span style="padding:0 6px;"><b>'.$v.'</b></span>'
      .'<span>'.$s.'</span>';

    return '<tr><td colspan="2" style="padding:7px 10px; border-top:'.$b.'; vertical-align:top;">'.$content.'</td></tr>';
  }

  // Waitlist Current Attendance: render as a single compact sentence row (email + PDF)
  // - Underline all field values
  // - Bold the "when we require care at GRASP" phrase
  // - Reduce font-size slightly so the full sentence fits on one row when possible
  private static function renderWaitlistCurrentAttendanceOneRow(
    string $kind,
    string $daycare,
    string $school,
    string $willAttend
  ): string {
    $b = self::borderTop($kind);

    $daycare = self::h(trim($daycare) === '' ? 'none' : $daycare);
    $school  = self::h(trim($school) === '' ? 'none' : $school);
    $will    = self::h(trim($willAttend) === '' ? 'none' : $willAttend);

    $fs = ($kind === 'pdf') ? '8.4pt' : '12px';
    $lh = ($kind === 'pdf') ? '1.08' : '1.15';

    $u1 = '<u><b>' . $daycare . '</b></u>';
    $u2 = '<u><b>' . $school  . '</b></u>';
    $u3 = '<u><b>' . $will    . '</b></u>';

    $content = ''
      . '<span style="font-size:' . $fs . '; line-height:' . $lh . ';">'
        . '<span style="font-weight:bold;">My child attends </span>' . $u1
        . '<span style="font-weight:bold;"> day care at the current time. My child is attending </span>' . $u2
        . '<span style="font-weight:bold;"> at the current time. My child will be attending </span>' . $u3
        . '<span style="font-weight:bold;"> when we require care at GRASP.</span>'
      . '</span>';

    return '<tr><td colspan="2" style="padding:7px 10px; border-top:' . $b . '; vertical-align:top;">' . $content . '</td></tr>';
  }




  private static function borderAll(string $kind): string {
    return ($kind === 'pdf') ? '0.5pt solid #333' : '1px solid #333';
  }


    private static function renderWaitlistKeyValueTable(string $kind, array $fields, array $data, array $opts = []): string {
    $pad = ($kind === 'pdf') ? '2px 8px' : '4px 8px';

    // Match the standard PDF row proportions (label ~38% / value ~62%) and improve readability.
    $labelW = isset($opts['labelWidth']) ? (int)$opts['labelWidth'] : 38;
    $valueW = 100 - $labelW;

    $valueAlignByKey = (isset($opts['valueAlignByKey']) && is_array($opts['valueAlignByKey'])) ? $opts['valueAlignByKey'] : [];
    $defaultAlign = isset($opts['defaultValueAlign']) ? (string)$opts['defaultValueAlign'] : 'left';
    if ($defaultAlign !== 'left' && $defaultAlign !== 'right' && $defaultAlign !== 'center') $defaultAlign = 'left';

    // For long-form fields (e.g., allergies), render label and value stacked to reduce wrapping.

    // Reduce font-size for a couple of very long labels in compact blocks (email + PDF).
    $smallLabelKeys = [
      'has_sibling_at_grasp' => true,
      'sibling_name' => true,
      'allergies_special_needs' => true,
    ];
    $stackKeys = (isset($opts['stackKeys']) && is_array($opts['stackKeys'])) ? $opts['stackKeys'] : [];
    $stackSet = [];
    foreach ($stackKeys as $k) {
      $stackSet[(string)$k] = true;
    }

    $rows = [];
    foreach ($fields as $field) {
      if (!is_array($field)) continue;
      if (self::shouldSkipField($field, $data)) continue;

      $key = self::fieldKey($field);
      if ($key === '') continue;

      $val = $data[$key] ?? '';
      $val = self::normalizeFieldValue($field, $val);

      $label = self::rowLabel($field); // already escaped

      // TCPDF quirk: padding on nested tables/cells can be inconsistently applied.
      // For the left-most compact blocks, we optionally prefix labels with NBSPs to
      // ensure a visible inset from the left border in the PDF.
      if ($kind === 'pdf') {
        $nbspCount = isset($opts['leftPadNbsp']) ? (int)$opts['leftPadNbsp'] : 0;
        if ($nbspCount > 0) {
          $label = str_repeat('&nbsp;', $nbspCount) . $label;
        }
      }
      $valueHtml = self::displayValue($val);

      if (isset($stackSet[$key]) && $stackSet[$key]) {
        $rows[] = '<tr>'
          . '<td colspan="2" style="padding:' . $pad . '; font-weight:bold; vertical-align:top;'
            . (isset($smallLabelKeys[$key]) ? (' font-size:' . (($kind === 'pdf') ? '8.0pt' : '11px') . '; line-height:1.1;') : '')
            . '">' . $label . '</td>'
          . '</tr>';
        $rows[] = '<tr>'
          . '<td colspan="2" style="padding:' . $pad . '; vertical-align:top; text-align:left;">' . $valueHtml . '</td>'
          . '</tr>';
        continue;
      }

      $align = isset($valueAlignByKey[$key]) ? (string)$valueAlignByKey[$key] : $defaultAlign;
      if ($align !== 'left' && $align !== 'right' && $align !== 'center') $align = $defaultAlign;

      $rows[] = '<tr>'
        . '<td style="width:' . $labelW . '%; padding:' . $pad . '; font-weight:bold; vertical-align:top;'
            . (isset($smallLabelKeys[$key]) ? (' font-size:' . (($kind === 'pdf') ? '8.0pt' : '11px') . '; line-height:1.1;') : '')
            . '">' . $label . '</td>'
        . '<td style="width:' . $valueW . '%; padding:' . $pad . '; vertical-align:top; text-align:' . $align . ';">' . $valueHtml . '</td>'
        . '</tr>';
    }

    if (count($rows) === 0) return '';

    return '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
      . implode("\n", $rows)
      . '</table>';
  }


    private static function renderWaitlistTwoColumnBox(string $kind, string $leftTitle, array $leftFields, string $rightTitle, array $rightFields, array $data, array $opts = []): string {
    $b = self::borderAll($kind);
    $bg = '#f3f3f3';
    $headPad = ($kind === 'pdf') ? '7pt 8pt' : '8px 10px';
    $margin = ($kind === 'pdf') ? '0 0 6px 0' : '0 0 14px 0';
    $nobr = ($kind === 'pdf') ? ' nobr="true"' : '';

    // Defaults for compact column blocks: left-aligned values + standard proportions.
    $opts = array_merge(['defaultValueAlign' => 'left', 'labelWidth' => 38], $opts);

    // PDF-only: the left-most compact block can crowd the left border in TCPDF.
    // Use NBSP-prefixed labels for the left table only to guarantee a visible inset.
    $leftOpts = $opts;
    if ($kind === 'pdf') {
      $leftOpts['leftPadNbsp'] = 4;
    }

    $leftTable = self::renderWaitlistKeyValueTable($kind, $leftFields, $data, $leftOpts);
    $rightTable = self::renderWaitlistKeyValueTable($kind, $rightFields, $data, $opts);

    // Match section heading style (same as section.html).
    // No explicit left/right borders on header cells to avoid heavy vertical lines.
    $headCommon = 'background-color:' . $bg . '; padding:' . $headPad . '; font-weight:bold; border-bottom:' . $b . ';';
    $headLeft = $headCommon . (($kind === 'pdf') ? ' padding-left:12pt;' : '');
    $headRight = $headCommon;

    // Add outer padding + a small inner gutter between columns (no divider line).
    $outerPad = ($kind === 'pdf') ? '6pt' : '10px';
    $innerPad = ($kind === 'pdf') ? '3pt' : '6px';

    return '<table' . $nobr . ' width="100%" cellpadding="0" cellspacing="0" style="border:' . $b . '; border-collapse:collapse; margin:' . $margin . ';">'
      . '<tr>'
        . '<td width="50%" style="' . $headLeft . '">' . (($kind === 'pdf') ? '&nbsp;&nbsp;' : '') . self::h($leftTitle) . '</td>'
        . '<td width="50%" style="' . $headRight . '">' . self::h($rightTitle) . '</td>'
      . '</tr>'
      . '<tr>'
        . '<td width="50%" style="vertical-align:top; padding:0 ' . $innerPad . ' 0 ' . $outerPad . '; border-left:' . $b . ';">' . $leftTable . '</td>'
        . '<td width="50%" style="vertical-align:top; padding:0 ' . $outerPad . ' 0 ' . $innerPad . '; border-right:' . $b . ';">' . $rightTable . '</td>'
      . '</tr>'
    . '</table>';
  }


    private static function renderWaitlistThreeColumnBox(string $kind, array $cols, array $data, array $opts = []): string {
    // $cols: [ ['title'=>..., 'fields'=>...], ... ] expected 3
    $b = self::borderAll($kind);
    $bg = '#f3f3f3';
    $headPad = ($kind === 'pdf') ? '7pt 8pt' : '8px 10px';
    $margin = ($kind === 'pdf') ? '0 0 6px 0' : '0 0 14px 0';
    $nobr = ($kind === 'pdf') ? ' nobr="true"' : '';

    $w = [34, 33, 33];

    // Section-heading style (no internal column dividers)
    $headCommon = 'background-color:' . $bg . '; padding:' . $headPad . '; font-weight:bold; border-bottom:' . $b . ';';
    $head = '<tr>';
    for ($i = 0; $i < 3; $i++) {
      $title = $cols[$i]['title'] ?? ('Column ' . ($i+1));
      $style = $headCommon;

      // PDF-only: add a touch more left padding for the left-most header to avoid
      // crowding against the outer border.
      if ($kind === 'pdf' && $i === 0) {
        $style .= ' padding-left:12pt;';
      }

      // No explicit left/right borders on header cells (match section header styling).
      // The table's outer border provides the frame.

      $head .= '<td width="' . $w[$i] . '%" style="' . $style . '">' . self::h((string)$title) . '</td>';
    }
    $head .= '</tr>';

    // Outer padding + inner gutters (no divider lines).
    $outerPad = ($kind === 'pdf') ? '6pt' : '10px';
    $innerPad = ($kind === 'pdf') ? '3pt' : '6px';
    $pads = [
      '0 ' . $innerPad . ' 0 ' . $outerPad,
      '0 ' . $innerPad . ' 0 ' . $innerPad,
      '0 ' . $outerPad . ' 0 ' . $innerPad
    ];

    $body = '<tr>';
    for ($i = 0; $i < 3; $i++) {
      $fields = $cols[$i]['fields'] ?? [];
      if (!is_array($fields)) $fields = [];

      $colOpts = $opts;
      // per-column overrides
      if (isset($cols[$i]['opts']) && is_array($cols[$i]['opts'])) {
        $colOpts = array_merge($colOpts, $cols[$i]['opts']);
      }

      // Defaults for compact columns: left-aligned values + standard proportions.
      $colOpts = array_merge(['defaultValueAlign' => 'left', 'labelWidth' => 38], $colOpts);

      // PDF-only: the left-most compact block can crowd the left border in TCPDF.
      // Prefix labels with NBSPs to guarantee a visible inset.
      if ($kind === 'pdf' && $i === 0) {
        $colOpts['leftPadNbsp'] = 4;
      }

      $table = self::renderWaitlistKeyValueTable($kind, $fields, $data, $colOpts);

      $style = 'vertical-align:top; padding:' . $pads[$i] . ';';
      if ($i === 0) $style .= ' border-left:' . $b . ';';
      if ($i === 2) $style .= ' border-right:' . $b . ';';

      $body .= '<td width="' . $w[$i] . '%" style="' . $style . '">' . $table . '</td>';
    }
    $body .= '</tr>';

    return '<table' . $nobr . ' width="100%" cellpadding="0" cellspacing="0" style="border:' . $b . '; border-collapse:collapse; margin:' . $margin . ';">'
      . $head
      . $body
      . '</table>';
  }

  /**
   * Enrollment-only helper to render the General Health section in a compact format.
   * The first row uses a 60/40 split, the next two rows use 4 columns,
   * followed by an 80/20 split row, and remaining fields rendered normally.
   *
   * @param string $kind     'email' or 'pdf'
   * @param array  $fields   Array of field definitions for this section
   * @param array  $data     Submitted enrollment data
   * @return string          HTML rows (<tr> tags) for this section
   */
  private static function renderEnrollmentGeneralHealthSection(
    string $kind,
    array $fields,
    array $data
  ): string {
    $map = self::mapFieldsByName($fields);
    $b = self::borderTop($kind);
    $pad = ($kind === 'pdf') ? '5px 6px' : '7px 8px';

    // EMAIL NOTE:
    // Many email clients calculate column widths across the *entire* table.
    // If a single row has 4 <td> cells, the whole table is treated as 4 columns,
    // which makes 2-cell rows render with unexpected widths and wrapping.
    // To avoid this, the email version renders each logical row as a 1-cell
    // wrapper row that contains its own nested table.
    if ($kind === 'email') {
      $rows = [];

      $wrapRow = function (string $borderTop, string $innerHtml) {
        return '<tr>'
          . '<td style="padding:0; border-top:' . $borderTop . '; vertical-align:top;">'
          . $innerHtml
          . '</td>'
          . '</tr>';
      };

      // Shared cell styles
      $labelCellBase = 'font-weight:bold; vertical-align:top; white-space:nowrap;';
      $valueCellBase = 'text-align:left; vertical-align:top;';

      // Row 1: General health / things to be aware of (nested 2-col). No top border (prevents double line under header).
      $ghVal = self::displayFieldValueHtml($kind, $map['general_health_notes'] ?? null, $data);
      $inner1 = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
        . '<tr>'
        . '<td width="30%" style="width:30%; padding:' . $pad . '; ' . $labelCellBase . '">' . self::h('General health / things to be aware of') . '</td>'
        . '<td width="70%" style="width:70%; padding:' . $pad . '; ' . $valueCellBase . '">' . $ghVal . '</td>'
        . '</tr>'
        . '</table>';
      $rows[] = $wrapRow('none', $inner1);

      // Row 2: Is your child asthmatic? / Is your child using a puffer? (nested 4-col)
      $asthVal = self::displayFieldValueHtml($kind, $map['child_asthmatic'] ?? null, $data);
      $pufferVal = self::displayFieldValueHtml($kind, $map['child_uses_puffer'] ?? null, $data);
      $inner2 = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
        . '<tr>'
        . '<td width="35%" style="width:35%; padding:' . $pad . '; ' . $labelCellBase . '">' . self::h('Is your child asthmatic?') . '</td>'
        . '<td width="15%" style="width:15%; padding:' . $pad . '; ' . $valueCellBase . '">' . $asthVal . '</td>'
        . '<td width="35%" style="width:35%; padding:' . $pad . '; ' . $labelCellBase . '">' . self::h('Is your child using a puffer?') . '</td>'
        . '<td width="15%" style="width:15%; padding:' . $pad . '; ' . $valueCellBase . '">' . $pufferVal . '</td>'
        . '</tr>'
        . '</table>';
      $rows[] = $wrapRow($b, $inner2);

      // Row 3: Date of last medical examination / Current weight (kg)
      $examVal = self::displayFieldValueHtml($kind, $map['last_medical_exam_date'] ?? null, $data);
      $weightVal = self::displayFieldValueHtml($kind, $map['current_weight'] ?? null, $data);

      $miniPair = function (string $label, string $valueHtml) {
        return '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
          . '<tr>'
          . '<td style="font-weight:bold; vertical-align:top; width:1%; white-space:nowrap; padding-right:10px;">' . self::h($label) . '</td>'
          . '<td style="text-align:left; vertical-align:top; width:99%;">' . $valueHtml . '</td>'
          . '</tr>'
          . '</table>';
      };

      $inner3 = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
        . '<tr>'
        . '<td width="50%" style="width:50%; padding:' . $pad . '; vertical-align:top;">' . $miniPair('Date of last medical examination', $examVal) . '</td>'
        . '<td width="50%" style="width:50%; padding:' . $pad . '; vertical-align:top;">' . $miniPair('Current weight (kg)', $weightVal) . '</td>'
        . '</tr>'
        . '</table>';
      $rows[] = $wrapRow($b, $inner3);

      // Row 4: Free of communicable diseases? (keep 80/20 split, nested 2-col)
      $freeVal = self::displayFieldValueHtml($kind, $map['free_of_disease'] ?? null, $data);
      $inner4 = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
        . '<tr>'
        . '<td width="80%" style="width:80%; padding:' . $pad . '; ' . $labelCellBase . '">' . self::h('At the present time is the child free of communicable diseases?') . '</td>'
        . '<td width="20%" style="width:20%; padding:' . $pad . '; ' . $valueCellBase . '">' . $freeVal . '</td>'
        . '</tr>'
        . '</table>';
      $rows[] = $wrapRow($b, $inner4);

      // Row 5: Previous history of any communicable diseases (nested 2-col)
      if (isset($map['disease_history'])) {
        $histVal = self::displayFieldValueHtml($kind, $map['disease_history'], $data);
        $inner5 = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
          . '<tr>'
          . '<td width="38%" style="width:38%; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . self::h('Previous history of any communicable diseases') . '</td>'
          . '<td width="62%" style="width:62%; padding:' . $pad . '; vertical-align:top;">' . $histVal . '</td>'
          . '</tr>'
          . '</table>';
        $rows[] = $wrapRow($b, $inner5);
      }

      // Row 6: Special requirements for diet, rest or exercise (nested 2-col)
      if (isset($map['special_requirements'])) {
        $specVal = self::displayFieldValueHtml($kind, $map['special_requirements'], $data);
        $inner6 = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
          . '<tr>'
          . '<td width="38%" style="width:38%; padding:' . $pad . '; font-weight:bold; vertical-align:top;">' . self::h('Special requirements for diet, rest or exercise') . '</td>'
          . '<td width="62%" style="width:62%; padding:' . $pad . '; vertical-align:top;">' . $specVal . '</td>'
          . '</tr>'
          . '</table>';
        $rows[] = $wrapRow($b, $inner6);
      }

      return implode("\n", $rows);
    }

    $rows = [];

    // Styles for label and value cells
    $labelStyle = 'font-weight:bold; vertical-align:top;';
    $valueStyle = 'text-align:left; vertical-align:top;';
    // Prevent labels from wrapping in email; allows longer labels to stay on a single line.
    if ($kind === 'email') {
      $labelStyle .= ' white-space:nowrap;';
    }

    // Row 1: General health notes (50/50). Remove border-top when rendering email to avoid double lines under the section heading.
    $ghVal = self::displayFieldValueHtml($kind, $map['general_health_notes'] ?? null, $data);
    $row1Border = ($kind === 'email') ? 'none' : $b;
    $rows[] =
      '<tr>'
      . '<td style="width:50%; padding:' . $pad . '; border-top:' . $row1Border . '; ' . $labelStyle . '">' . self::h('General health / things to be aware of') . '</td>'
      . '<td style="width:50%; padding:' . $pad . '; border-top:' . $row1Border . '; ' . $valueStyle . '">' . $ghVal . '</td>'
      . '</tr>';

    // Row 2: Is your child asthmatic? / Is your child using a puffer? (4 columns)
    $asthVal = self::displayFieldValueHtml($kind, $map['child_asthmatic'] ?? null, $data);
    $pufferVal = self::displayFieldValueHtml($kind, $map['child_uses_puffer'] ?? null, $data);
    $rows[] =
      '<tr>'
      . '<td style="width:35%; padding:' . $pad . '; border-top:' . $b . '; ' . $labelStyle . '">' . self::h('Is your child asthmatic?') . '</td>'
      . '<td style="width:15%; padding:' . $pad . '; border-top:' . $b . '; ' . $valueStyle . '">' . $asthVal . '</td>'
      . '<td style="width:35%; padding:' . $pad . '; border-top:' . $b . '; ' . $labelStyle . '">' . self::h('Is your child using a puffer?') . '</td>'
      . '<td style="width:15%; padding:' . $pad . '; border-top:' . $b . '; ' . $valueStyle . '">' . $pufferVal . '</td>'
      . '</tr>';

    // Row 3: Date of last medical examination / Current weight (kg)
    $examVal = self::displayFieldValueHtml($kind, $map['last_medical_exam_date'] ?? null, $data);
    $weightVal = self::displayFieldValueHtml($kind, $map['current_weight'] ?? null, $data);
    if ($kind === 'email') {
      // For email, wrap the two label/value pairs in their own nested tables to allow flexible widths.
      $label1 = self::h('Date of last medical examination');
      $label2 = self::h('Current weight (kg)');
      $inner1 = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
        . '<tr>'
        . '<td style="font-weight:bold; vertical-align:top; width:1%; white-space:nowrap; padding-right:10px;">' . $label1 . '</td>'
        . '<td style="text-align:left; vertical-align:top; width:99%;">' . $examVal . '</td>'
        . '</tr>'
        . '</table>';
      $inner2 = '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
        . '<tr>'
        . '<td style="font-weight:bold; vertical-align:top; width:1%; white-space:nowrap; padding-right:10px;">' . $label2 . '</td>'
        . '<td style="text-align:left; vertical-align:top; width:99%;">' . $weightVal . '</td>'
        . '</tr>'
        . '</table>';
      $rows[] =
        '<tr>'
        . '<td colspan="4" style="padding:0; border-top:' . $b . '; vertical-align:top;">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">'
        . '<tr>'
        . '<td style="width:50%; padding:' . $pad . '; vertical-align:top;">' . $inner1 . '</td>'
        . '<td style="width:50%; padding:' . $pad . '; vertical-align:top;">' . $inner2 . '</td>'
        . '</tr>'
        . '</table>'
        . '</td>'
        . '</tr>';
    } else {
      // PDF: use a 4-column layout with explicit widths
      $rows[] =
        '<tr>'
        . '<td style="width:40%; padding:' . $pad . '; border-top:' . $b . '; ' . $labelStyle . '">' . self::h('Date of last medical examination') . '</td>'
        . '<td style="width:20%; padding:' . $pad . '; border-top:' . $b . '; ' . $valueStyle . '">' . $examVal . '</td>'
        . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $labelStyle . '">' . self::h('Current weight (kg)') . '</td>'
        . '<td style="width:15%; padding:' . $pad . '; border-top:' . $b . '; ' . $valueStyle . '">' . $weightVal . '</td>'
        . '</tr>';
    }

    // Row 4: Free of communicable diseases? (80/20)
    $freeVal = self::displayFieldValueHtml($kind, $map['free_of_disease'] ?? null, $data);
    $rows[] =
      '<tr>'
      . '<td style="width:80%; padding:' . $pad . '; border-top:' . $b . '; ' . $labelStyle . '">' . self::h('At the present time is the child free of communicable diseases?') . '</td>'
      . '<td style="width:20%; padding:' . $pad . '; border-top:' . $b . '; ' . $valueStyle . '">' . $freeVal . '</td>'
      . '</tr>';

    // Remaining fields: disease_history and special_requirements (if defined)
    $remainingFields = [];
    foreach (['disease_history', 'special_requirements'] as $fname) {
      if (isset($map[$fname])) {
        $remainingFields[] = $map[$fname];
      }
    }
    if (!empty($remainingFields)) {
      // Render these using the default 2-column layout
      $rows[] = self::renderRows($kind, $remainingFields, $data);
    }

    // Combine all rows into a single HTML string
    $htmlPieces = [];
    foreach ($rows as $r) {
      if (is_string($r) && trim($r) !== '') {
        $htmlPieces[] = $r;
      }
    }
    return implode("\n", $htmlPieces);
  }

}
