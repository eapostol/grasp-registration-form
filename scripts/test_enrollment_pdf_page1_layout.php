#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/api/lib/EmailPrintTemplate.php';
require_once $root . '/api/lib/FormPdfGenerator.php';

$configPath = realpath($root . '/config/enrollment-fields.json');
if ($configPath === false) {
    fwrite(STDERR, "[FAIL] config/enrollment-fields.json not found\n");
    exit(1);
}

$sampleData = [
    'child_first_name' => 'SHAYNE',
    'child_middle_name_or_initial' => '',
    'child_last_name' => 'DABIDEEN',
    'child_birth_date' => '2018-09-02',

    'parent1_name' => 'SHERRY DABIDEEN',
    'parent1_email' => 'sherry@example.com',
    'parent1_street' => '74 DUTCH MYRTLE WAY',
    'parent1_city' => 'TORONTO',
    'parent1_province' => 'ON',
    'parent1_postal1' => 'M3B',
    'parent1_postal2' => '3K8',
    'parent1_phones' => '647 997 9410',
    'parent1_work_street' => '1 DUNDAS STREET WEST',
    'parent1_work_city' => 'TORONTO',
    'parent1_work_province' => 'ON',
    'parent1_work_postal1' => 'M5G',
    'parent1_work_postal2' => '2L5',
    'parent1_work_phone' => '647 997 9410',

    'parent2_name' => 'SATEISH DABIDEEN',
    'parent2_email' => 'sateish@example.com',
    'parent2_street' => '74 DUTCH MYRTLE WAY',
    'parent2_city' => 'TORONTO',
    'parent2_province' => 'ON',
    'parent2_postal1' => 'M3B',
    'parent2_postal2' => '3K8',
    'parent2_phones' => '647 285 9410',

    'doctor_name' => 'Dr. Elizabeth Yoo-Hee Glowczewski-Park',
    'doctor_street' => '520 ELLESMERE ROAD',
    'doctor_city' => 'SCARBOROUGH',
    'doctor_province' => 'ON',
    'doctor_postal1' => 'M1R',
    'doctor_postal2' => '0B1',
    'doctor_phone' => '416-751-5600',
    'child_allergies' => 'NO',
    'epipen_required' => 'no',

    'emergency_contact_name' => 'LAURA LUDWIN',
    'emergency_contact_relationship' => 'AUNT',
    'emergency_contact_day_phone' => '416 951 1904',
    'emergency_contact_address' => "311 Waverley Rd\nToronto, ON\nM4L 3T5",
    'authorized_pickups' => "LAURA LUDWIN (AUNT)\nKAMI HARRIPERSAD",

    'parent_full_name_signature' => 'Sherry Ann Dabideen',
    'signature_date' => '2026-04-15',
    'witness' => 'Sateish Dabideen',

    'medical_release_consent' => 'I agree',
];

$meta = [
    'formTitle' => 'GRASP Enrollment Form',
    'submittedAt' => '2026-04-16 12:00:00',
    'sessionId' => 'test-page1-layout',
    'templateProfile' => 'enrollment',
];

$pdfHtml = EmailPrintTemplate::renderPdfFromConfig($configPath, $sampleData, $meta);

$checks = [];
$failures = [];

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

foreach ($checks as $check) {
    if ($check['ok']) {
        echo '[PASS] ' . $check['name'] . PHP_EOL;
    } else {
        echo '[FAIL] ' . $check['name'] . PHP_EOL;
        $failures[] = $check['name'];
    }
}

$canRunBinaryPdfCheck = defined('CURLOPT_CONNECTTIMEOUT') && class_exists('TCPDF') && trim((string)shell_exec('command -v pdftotext 2>/dev/null')) !== '';

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
            ['name' => 'page 1 contains Emergency section title', 'ok' => (stripos($page1, 'Emergency & Authorized Pickups') !== false)],
            ['name' => 'page 1 does not contain Medical section title', 'ok' => (stripos($page1, 'Medical Release & Medication') === false)],
            ['name' => 'page 2 contains Medical section title', 'ok' => (stripos($page2, 'Medical Release & Medication') !== false)],
        ];

        foreach ($pdfChecks as $check) {
            if ($check['ok']) {
                echo '[PASS] ' . $check['name'] . PHP_EOL;
            } else {
                echo '[FAIL] ' . $check['name'] . PHP_EOL;
                $failures[] = $check['name'];
            }
        }
    } catch (Throwable $e) {
        echo '[FAIL] binary PDF check threw exception: ' . $e->getMessage() . PHP_EOL;
        $failures[] = 'binary PDF check exception';
    } finally {
        if ($tmpPdfPath && is_file($tmpPdfPath)) {
            @unlink($tmpPdfPath);
        }
    }
} else {
    echo '[SKIP] binary PDF page text check (TCPDF/cURL constant or pdftotext unavailable in this environment)' . PHP_EOL;
}

if (!empty($failures)) {
    echo '---' . PHP_EOL;
    echo '[RESULT] FAIL (' . count($failures) . ' failed check(s))' . PHP_EOL;
    exit(1);
}

echo '---' . PHP_EOL;
echo '[RESULT] PASS' . PHP_EOL;
exit(0);
