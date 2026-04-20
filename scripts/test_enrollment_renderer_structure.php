#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/api/lib/EmailPrintTemplate.php';
require_once __DIR__ . '/enrollment_renderer_test_lib.php';

$configPath = enrollmentRendererConfigPath();
if (!is_file($configPath)) {
    fwrite(STDERR, "[FAIL] config/enrollment-fields.json not found\n");
    exit(1);
}

$sampleData = enrollmentRendererSampleData();
$meta = enrollmentRendererMeta('test-enrollment-renderer-structure');

$outputs = [
    'email' => EmailPrintTemplate::renderFromConfig($configPath, $sampleData, $meta),
    'pdf' => EmailPrintTemplate::renderPdfFromConfig($configPath, $sampleData, $meta),
];

$failures = 0;

foreach ($outputs as $kind => $html) {
    $decoded = enrollmentRendererDecodedHtml($html);
    $matrixSection = enrollmentRendererSectionWindow(
        $decoded,
        'Parent / Guardian Information',
        'Doctor & Allergy Information'
    );

    $checks = [
        [
            'name' => $kind . ' output contains combined parent section title',
            'ok' => (strpos($decoded, 'Parent / Guardian Information') !== false),
        ],
        [
            'name' => $kind . ' output keeps parent matrix bounded before doctor section',
            'ok' => ($matrixSection !== ''),
        ],
        [
            'name' => $kind . ' output includes Parent / Guardian 1 matrix column',
            'ok' => ($matrixSection !== '' && substr_count($matrixSection, 'Parent / Guardian 1') === 1),
            'detail' => 'expected exactly one combined-matrix column header',
        ],
        [
            'name' => $kind . ' output includes Parent / Guardian 2 matrix column',
            'ok' => ($matrixSection !== '' && substr_count($matrixSection, 'Parent / Guardian 2') === 1),
            'detail' => 'expected exactly one combined-matrix column header',
        ],
        [
            'name' => $kind . ' output includes Parent / Guardian 1 sample name inside matrix',
            'ok' => ($matrixSection !== '' && strpos($matrixSection, 'Leilani Mau') !== false),
        ],
        [
            'name' => $kind . ' output includes Parent / Guardian 2 sample name inside matrix',
            'ok' => ($matrixSection !== '' && strpos($matrixSection, 'Kevin Li') !== false),
        ],
        [
            'name' => $kind . ' output does not emit standalone Parent 2 section title',
            'ok' => (strpos($decoded, 'Parent Guardian 2 Information (if applicable)') === false),
        ],
        [
            'name' => $kind . ' output does not duplicate Parent / Guardian 1 as standalone section',
            'ok' => (substr_count($decoded, 'Parent / Guardian 1') === 1),
            'detail' => 'a second occurrence usually means the renderer split the matrix into a standalone section',
        ],
    ];

    $failures += enrollmentRendererPrintChecks($checks);
}

if ($failures > 0) {
    echo "---\n[RESULT] FAIL ({$failures} failed check(s))\n";
    exit(1);
}

echo "---\n[RESULT] PASS\n";
exit(0);
