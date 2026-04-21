#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once __DIR__ . '/lib/RecoveryMessageParser.php';

$parser = new RecoveryMessageParser($root);
$fixtureDir = __DIR__ . '/fixtures/recovery';

$cases = [
    'enrollment-legacy-raw.json' => [
        'formType' => 'enrollment',
        'variant' => 'enrollment_legacy_raw',
        'fields' => [
            'child_first_name' => 'Jeanne',
            'child_last_name' => 'Liu',
            'parent1_name' => 'Elliot Liu',
            'parent2_name' => 'Ryan Liu',
            'doctor_name' => 'Dr. Test Physician',
            'parent_full_name_signature' => 'Elliot Liu',
        ],
    ],
    'enrollment-structured.json' => [
        'formType' => 'enrollment',
        'variant' => 'enrollment_structured',
        'fields' => [
            'child_first_name' => 'Avery',
            'child_last_name' => 'Mercer',
            'parent1_email' => 'jordan.mercer@example.test',
            'parent2_email' => 'taylor.mercer@example.test',
            'doctor_name' => 'Dr. Helen North',
            'emergency_contact_name' => 'Priya Mercer aunt',
            'parent_full_name_signature' => 'Taylor Mercer',
        ],
    ],
    'enrollment-structured-flattened-parent-layout.json' => [
        'formType' => 'enrollment',
        'variant' => 'enrollment_structured',
        'fields' => [
            'child_first_name' => 'Rowan',
            'child_last_name' => 'Hale',
            'parent1_email' => 'rowan.guardian@example.test',
            'parent1_home_address' => '10 Cedar Lane, 307, Toronto, Ontario',
            'parent1_work_address' => 'Northview College, 210 Learning Way, Toronto, Ontario',
            'doctor_name' => 'Dr. Mira Quinn',
            'parent_full_name_signature' => 'Rowan Hale',
        ],
    ],
    'waitlist-structured.json' => [
        'formType' => 'waitlist',
        'variant' => 'waitlist_structured',
        'fields' => [
            'child_name' => 'Avery Mercer',
            'parent1_name' => 'Jordan Mercer',
            'parent2_name' => 'Taylor Mercer',
            'parent1_email' => 'jordan.mercer@example.test',
            'parent_signature' => 'Jordan Mercer',
        ],
    ],
    'waitlist-legacy.json' => [
        'formType' => 'waitlist',
        'variant' => 'waitlist_legacy',
        'fields' => [
            'child_name' => 'Test value',
            'parent1_name' => 'Test value',
            'parent2_name' => 'Test value',
            'parent1_email' => 'test@test.com',
            'parent_signature' => 'Test value',
        ],
    ],
    'parent-manual-structured.json' => [
        'formType' => 'parent_manual',
        'variant' => 'parent_manual_structured',
        'fields' => [
            'pm_initials_p09_01' => 'JM',
            'pm_initials_p28_01' => 'JM',
            'pm_ack_printed_name' => 'Jordan Mercer',
            'pm_parent_signature' => 'Jordan Mercer',
            'pm_parent_date' => 'April 20, 2026',
        ],
    ],
];

$failures = 0;
foreach ($cases as $fixture => $expect) {
    $payload = json_decode((string)file_get_contents($fixtureDir . '/' . $fixture), true);
    if (!is_array($payload)) {
        fwrite(STDERR, "[FAIL] {$fixture}: invalid fixture JSON\n");
        $failures++;
        continue;
    }

    $parsed = $parser->parse($payload);
    $caseFailures = [];
    if (($parsed['formType'] ?? '') !== $expect['formType']) {
        $caseFailures[] = 'wrong formType';
    }
    if (($parsed['variant'] ?? '') !== $expect['variant']) {
        $caseFailures[] = 'wrong variant';
    }
    if (empty($parsed['ok'])) {
        $caseFailures[] = 'parser did not report ok';
    }

    foreach ($expect['fields'] as $field => $expectedValue) {
        $actual = $parsed['fields'][$field] ?? null;
        if ($actual !== $expectedValue) {
            $caseFailures[] = $field . ' expected [' . $expectedValue . '] got [' . (string)$actual . ']';
        }
    }

    if ($caseFailures !== []) {
        fwrite(STDERR, "[FAIL] {$fixture}: " . implode('; ', $caseFailures) . "\n");
        $failures++;
        continue;
    }

    echo '[PASS] ' . $fixture . PHP_EOL;
}

if ($failures > 0) {
    exit(1);
}
