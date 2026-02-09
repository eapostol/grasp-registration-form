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

    private static function renderSections(string $kind, array $sections, array $data): string
    {
        $sectionTpl = self::loadTemplate($kind, 'section');
        $out = [];

        foreach ($sections as $section) {
            if (!is_array($section)) continue;

            $title = $section['title'] ?? ($section['sectionTitle'] ?? 'Section');
            $fields = $section['fields'] ?? [];

            if (!is_array($fields) || count($fields) === 0) continue;

            // Special case: Waitlist Parents/Guardians should be split into 2 blocks.
            if (is_string($title) && trim($title) === 'Parents / Guardians') {
                $split = self::splitWaitlistGuardians($fields);
                if (count($split) > 0) {
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

            $contentRows = '';
            if (!empty($section['contentBlocks']) && is_array($section['contentBlocks'])) {
                $contentRows = self::renderContentBlocks($kind, $section['contentBlocks'], $data);
            }
            $rows = trim($contentRows) === '' ? self::renderRows($kind, $fields, $data) : ($contentRows . "\n" . self::renderRows($kind, $fields, $data));

            // If every row was skipped, don't render the section
            if (trim($rows) === '') continue;

            $out[] = str_replace(
                ['{{SECTION_TITLE}}', '{{ROWS}}'],
                [self::h((string)$title), $rows],
                $sectionTpl
            );
        }

        return implode("\n", $out);
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

$content = self::renderSections($kind, $sections, $data);

        $baseTpl = self::loadTemplate($kind, 'base');
        return str_replace(
            ['{{FORM_TITLE}}', '{{SUBMITTED_AT}}', '{{CONTENT}}'],
            [self::h((string)$formTitle), self::h((string)$submittedAt), $content],
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
}