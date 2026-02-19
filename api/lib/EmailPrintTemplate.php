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

            if ($title !== '') {
                $html = '<div style="font-weight:bold; margin:0 0 4px 0;">' . self::h($title) . '</div>' . $html;
            }

            if (trim($html) === '') continue;

            $row = str_replace(
                ['{{STYLE}}', '{{CONTENT}}', '{{BGCOLOR_ATTR}}'],
                [self::h($style), $html, $bgcolorAttr],
                $rowTpl
            );

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

                    $rows[] = '<tr>'
                        . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $labelStyle . '">' . self::h("Doctor's Name") . '</td>'
                        . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $valueStyle . '">' . $doctorNameHtml . '</td>'
                        . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $labelStyle . '">' . self::h("Doctor's Phone #") . '</td>'
                        . '<td style="width:25%; padding:' . $pad . '; border-top:' . $b . '; ' . $valueStyle . '">' . $doctorPhoneHtml . '</td>'
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

            // Default render path (supports contentBlocks)
            $contentRows = '';
            if (!empty($section['contentBlocks']) && is_array($section['contentBlocks'])) {
                $contentRows = self::renderContentBlocks($kind, $section['contentBlocks'], $data);
            }
            $rows = trim($contentRows) === ''
                ? self::renderRows($kind, $fields, $data)
                : ($contentRows . "
" . self::renderRows($kind, $fields, $data));

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
    $tableCellpadding = ($kind === 'pdf') ? '5' : '0';
    $cellPad = ($kind === 'pdf') ? '' : ' padding:7px 10px;';

    $t = '<table width="100%" cellpadding="' . $tableCellpadding . '" cellspacing="0" style="border-collapse:collapse;">';

    // Column header row (3 columns) - NO inner vertical borders
    $hdrCommon = 'font-weight:bold; text-align:center; background:#f3f3f3;' . $cellPad . ' white-space:nowrap;';
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
      self::displayValue($makeName('parent2'))
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

    // Wrap in a full-width row so the matrix sits inside the section
    $rowFullTpl = self::loadTemplate($kind, 'row_full');
    if ($kind === 'pdf') {
      return str_replace(
        ['{{BGCOLOR_ATTR}}', '{{STYLE}}', '{{CONTENT}}'],
        ['', 'padding:0;', $t],
        $rowFullTpl
      );
    }

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

        $rows[] =
          '<tr><td colspan="3"' .
          $bgcolorAttr .
          ' style="padding:' .
          $pad .
          '; border-top:' .
          $b .
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
    $rows[] =
      '<tr>' .
      '<td style="width:33.33%; padding:' .
      $pad .
      '; border-top:' .
      $b .
      '; vertical-align:top;">' .
      self::displayFieldValueHtml($kind, $fName, $data) .
      '</td>' .
      '<td style="width:33.33%; padding:' .
      $pad .
      '; border-top:' .
      $b .
      '; vertical-align:top;">' .
      self::displayFieldValueHtml($kind, $fRel, $data) .
      '</td>' .
      '<td style="width:33.34%; padding:' .
      $pad .
      '; border-top:' .
      $b .
      '; vertical-align:top;">' .
      self::displayFieldValueHtml($kind, $fPhone, $data) .
      '</td>' .
      '</tr>';

    // Row 2: labels (3 cols)
    $rows[] =
      '<tr>' .
      '<td style="width:33.33%; padding:' .
      $pad .
      '; border-top:' .
      $b .
      '; vertical-align:top; font-weight:bold;">' .
      self::h('Contact Name') .
      '</td>' .
      '<td style="width:33.33%; padding:' .
      $pad .
      '; border-top:' .
      $b .
      '; vertical-align:top; font-weight:bold;">' .
      self::h('Relationship To Child') .
      '</td>' .
      '<td style="width:33.34%; padding:' .
      $pad .
      '; border-top:' .
      $b .
      '; vertical-align:top; font-weight:bold;">' .
      self::h('Day Time Phone #') .
      '</td>' .
      '</tr>';

    // Row 3: day-time address value (colspan=3)
    $rows[] =
      '<tr><td colspan="3" style="padding:' .
      $pad .
      '; border-top:' .
      $b .
      '; vertical-align:top;">' .
      self::displayFieldValueHtml($kind, $fAddr, $data) .
      '</td></tr>';

    // Row 4: day-time address label (colspan=3)
    $rows[] =
      '<tr><td colspan="3" style="padding:' .
      $pad .
      '; border-top:' .
      $b .
      '; vertical-align:top; font-weight:bold;">' .
      self::h('Day time Address (incl. postal code)') .
      '</td></tr>';

    // Row 5: other authorized pickups label + value (merge cols 2-3)
    $rows[] =
      '<tr>' .
      '<td style="width:33.33%; padding:' .
      $pad .
      '; border-top:' .
      $b .
      '; vertical-align:top; font-weight:bold;">' .
      self::h('Other Authorized Pickups') .
      '</td>' .
      '<td colspan="2" style="width:66.67%; padding:' .
      $pad .
      '; border-top:' .
      $b .
      '; vertical-align:top;">' .
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
    $rows[] =
      '<tr>' .
      '<td style="width:33.33%; padding:' .
      $pad .
      '; border-top:' .
      $b .
      '; border-bottom:none; border-bottom-style:none; border-bottom-width:0; vertical-align:top;">' .
      self::displayValue($parentSigRaw) .
      '</td>' .
      '<td style="width:33.33%; padding:' .
      $pad .
      '; border-top:' .
      $b .
      '; border-bottom:none; border-bottom-style:none; border-bottom-width:0; vertical-align:top;">' .
      self::displayValue($dateSignedRaw) .
      '</td>' .
      '<td style="width:33.34%; padding:' .
      $pad .
      '; border-top:' .
      $b .
      '; border-bottom:none; border-bottom-style:none; border-bottom-width:0; vertical-align:top;">' .
      self::displayValue($witnessRaw) .
      '</td>' .
      '</tr>';

    // Row 7: labels (3 cols)
    $rows[] =
      '<tr>' .
      '<td style="width:33.33%; padding:' .
      $padLabel .
      '; border-top:' .
      $b .
      '; vertical-align:top; font-weight:bold;">' .
      self::h('Parent / Guardian Signature') .
      '</td>' .
      '<td style="width:33.33%; padding:' .
      $padLabel .
      '; border-top:' .
      $b .
      '; vertical-align:top; font-weight:bold;">' .
      self::h('Date Signed') .
      '</td>' .
      '<td style="width:33.34%; padding:' .
      $padLabel .
      '; border-top:' .
      $b .
      '; vertical-align:top; font-weight:bold;">' .
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

}
