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

    private static function fieldKey(array $field): string
    {
        // Your configs primarily use "name"
        if (!empty($field['name'])) return (string)$field['name'];
        if (!empty($field['id'])) return (string)$field['id'];
        if (!empty($field['key'])) return (string)$field['key'];
        return '';
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
        $label = $field['label'] ?? '';
        return self::h((string)$label);
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

    private static function renderSections(string $kind, array $sections, array $data): string
    {
        $sectionTpl = self::loadTemplate($kind, 'section');
        $out = [];

        foreach ($sections as $section) {
            if (!is_array($section)) continue;

            $title = $section['title'] ?? ($section['sectionTitle'] ?? 'Section');
            $fields = $section['fields'] ?? [];

            if (!is_array($fields) || count($fields) === 0) continue;

            $rows = self::renderRows($kind, $fields, $data);

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
        if (!file_exists($configPath)) {
            throw new Exception("Config not found: " . $configPath);
        }

        $json = file_get_contents($configPath);
        $cfg = json_decode($json, true);
        if (!is_array($cfg)) {
            throw new Exception("Invalid config JSON: " . $configPath);
        }

        $formTitle = $meta['formTitle'] ?? ($cfg['formTitle'] ?? 'GRASP Form Submission');
        $submittedAt = $meta['submittedAt'] ?? date('F j, Y, g:i a');

        // Your configs are either:
        // - { sections: [...] }
        // - or { fields: [...] } (single section)
        $sections = $cfg['sections'] ?? null;
        if (!is_array($sections)) {
            $sections = [
                [
                    'title' => 'Form Details',
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
