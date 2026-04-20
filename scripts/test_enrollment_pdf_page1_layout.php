#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/api/lib/EmailPrintTemplate.php';
require_once $root . '/api/lib/FormPdfGenerator.php';
require_once __DIR__ . '/enrollment_renderer_test_lib.php';

$configPath = enrollmentRendererConfigPath();
if (!is_file($configPath)) {
    fwrite(STDERR, "[FAIL] config/enrollment-fields.json not found\n");
    exit(1);
}

$sampleData = enrollmentRendererSampleData();
$meta = enrollmentRendererMeta('test-page1-layout');

$pdfHtml = EmailPrintTemplate::renderPdfFromConfig($configPath, $sampleData, $meta);
$decodedPdfHtml = enrollmentRendererDecodedHtml($pdfHtml);
$parentMatrixSection = enrollmentRendererSectionWindow(
    $decodedPdfHtml,
    'Parent / Guardian Information',
    'Doctor & Allergy Information'
);

$checks = [];

$startMarker = '<!--GRASP_PAGE1_FIT_START-->';
$endMarker = '<!--GRASP_PAGE1_FIT_END-->';
$pageBreakMarker = '<!--GRASP_PAGEBREAK-->';
$emergencyTitle = 'Emergency &amp; Authorized Pickups';
$medicalTitle = 'Medical Release &amp; Medication';

$posStart = strpos($pdfHtml, $startMarker);
$posEmergency = strpos($pdfHtml, $emergencyTitle);
$posEnd = strpos($pdfHtml, $endMarker);
$posBreak = strpos($pdfHtml, $pageBreakMarker, ($posEnd !== false ? $posEnd : 0));
$posMedical = strpos($pdfHtml, $medicalTitle);

$checks[] = ['name' => 'contains combined parent section title', 'ok' => (strpos($decodedPdfHtml, 'Parent / Guardian Information') !== false)];
$checks[] = ['name' => 'combined parent matrix appears before doctor section', 'ok' => ($parentMatrixSection !== '')];
$checks[] = ['name' => 'does not emit standalone parent2 section title in pdf html', 'ok' => (strpos($decodedPdfHtml, 'Parent Guardian 2 Information (if applicable)') === false)];
$checks[] = ['name' => 'contains page1 fit start marker', 'ok' => ($posStart !== false)];
$checks[] = ['name' => 'contains page1 fit end marker', 'ok' => ($posEnd !== false)];
$checks[] = ['name' => 'contains emergency section title', 'ok' => ($posEmergency !== false)];
$checks[] = ['name' => 'contains medical section title', 'ok' => ($posMedical !== false)];
$checks[] = ['name' => 'contains page break marker after fit end', 'ok' => ($posBreak !== false)];
$checks[] = [
    'name' => 'marker/section order is start -> emergency -> end -> break -> medical',
    'ok' => ($posStart !== false && $posEmergency !== false && $posEnd !== false && $posBreak !== false && $posMedical !== false
        && $posStart < $posEmergency
        && $posEmergency < $posEnd
        && $posEnd < $posBreak
        && $posBreak < $posMedical),
];
$checks[] = [
    'name' => 'legacy tcpdf AddPage tag not emitted in rendered HTML',
    'ok' => (strpos($pdfHtml, '<tcpdf method="AddPage" />') === false),
];

$failures = enrollmentRendererPrintChecks($checks);

$autoloadPath = $root . '/api/vendor/autoload.php';
$canRunBinaryPdfCheck = is_file($autoloadPath) && trim((string)shell_exec('command -v pdftotext 2>/dev/null')) !== '';

if ($canRunBinaryPdfCheck) {
    $tmpPdfPath = null;
    try {
        $pdfDoc = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $pdfHtml . '</body></html>';
        $info = FormPdfGenerator::generateFromHtml(
            'GRASP Enrollment Form',
            $pdfDoc,
            'test-page1-layout-' . date('Ymd-His'),
            ['profile' => 'enrollment', 'tmpDir' => sys_get_temp_dir()]
        );

        $tmpPdfPath = $info['path'] ?? null;
        if (!$tmpPdfPath || !is_file($tmpPdfPath)) {
            throw new RuntimeException('Generated PDF path missing');
        }

        $cmd = 'pdftotext -f 1 -l 2 ' . escapeshellarg($tmpPdfPath) . ' -';
        $text = (string)shell_exec($cmd);
        $pages = explode("\f", $text);
        $page1 = $pages[0] ?? '';
        $page2 = $pages[1] ?? '';

        $pdfChecks = [
            ['name' => 'page 1 contains Parent / Guardian Information title', 'ok' => (stripos($page1, 'Parent / Guardian Information') !== false)],
            [
                'name' => 'page 1 contains Doctor & Allergy Information title',
                'ok' => (
                    stripos($page1, 'Doctor & Allergy Information') !== false
                    || stripos($page1, 'Doctor and Allergy Information') !== false
                ),
            ],
            ['name' => 'page 1 contains Emergency section title', 'ok' => (stripos($page1, 'Emergency & Authorized Pickups') !== false)],
            ['name' => 'page 1 does not contain Medical section title', 'ok' => (stripos($page1, 'Medical Release & Medication') === false)],
            ['name' => 'page 2 does not contain Emergency section title', 'ok' => (stripos($page2, 'Emergency & Authorized Pickups') === false)],
            ['name' => 'page 2 contains Medical section title', 'ok' => (stripos($page2, 'Medical Release & Medication') !== false)],
        ];

        $failures += enrollmentRendererPrintChecks($pdfChecks);
    } catch (Throwable $e) {
        echo '[FAIL] binary PDF check threw exception: ' . $e->getMessage() . PHP_EOL;
        $failures++;
    } finally {
        if ($tmpPdfPath && is_file($tmpPdfPath)) {
            @unlink($tmpPdfPath);
        }
    }
} else {
    echo '[SKIP] binary PDF page text check (composer autoload or pdftotext unavailable in this environment)' . PHP_EOL;
}

if ($failures > 0) {
    echo '---' . PHP_EOL;
    echo '[RESULT] FAIL (' . $failures . ' failed check(s))' . PHP_EOL;
    exit(1);
}

echo '---' . PHP_EOL;
echo '[RESULT] PASS' . PHP_EOL;
exit(0);
