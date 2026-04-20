#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$configPath = $root . '/config/enrollment-fields.json';

require_once $root . '/api/lib/EnrollmentFieldValidator.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "[FAIL] missing config/enrollment-fields.json\n");
    exit(1);
}

$cfg = json_decode((string)file_get_contents($configPath), true);
if (!is_array($cfg)) {
    fwrite(STDERR, "[FAIL] unable to parse enrollment-fields.json\n");
    exit(1);
}

$fieldMap = [];
foreach (($cfg['steps'] ?? []) as $step) {
    foreach (($step['groups'] ?? []) as $group) {
        foreach (($group['fields'] ?? []) as $f) {
            if (!is_array($f) || empty($f['name'])) continue;
            $fieldMap[(string)$f['name']] = $f;
        }
    }
}

$expectedCaps = [
    'child_first_name' => 80,
    'child_middle_name_or_initial' => 80,
    'child_last_name' => 80,
    'subsidy_file_number' => 40,
    'parent1_email' => 254,
    'parent1_home_street' => 120,
    'parent1_home_unit' => 25,
    'parent1_home_city' => 60,
    'parent1_phones' => 25,
    'doctor_name' => 100,
    'doctor_phone' => 25,
    'emergency_contact_name' => 80,
    'emergency_contact_relationship' => 50,
    'emergency_contact_day_phone' => 25,
    'emergency_contact_address' => 180,
    'authorized_pickups' => 420,
    'allergy_treatment' => 600,
    'parent_full_name_signature' => 80,
    'witness' => 80,
    'additional_comments' => 600,
];

$failed = 0;

foreach ($expectedCaps as $name => $expected) {
    $actual = null;
    if (isset($fieldMap[$name]) && isset($fieldMap[$name]['maxLength'])) {
        $actual = (int)$fieldMap[$name]['maxLength'];
    }

    if ($actual !== $expected) {
        echo "[FAIL] cap mismatch for {$name}: expected {$expected}, got " . var_export($actual, true) . PHP_EOL;
        $failed++;
    } else {
        echo "[PASS] cap {$name} = {$expected}" . PHP_EOL;
    }
}

$validPayload = [
    'child_first_name' => str_repeat('A', 80),
    'doctor_name' => str_repeat('D', 100),
    'emergency_contact_address' => str_repeat('X', 180),
    'authorized_pickups' => str_repeat('Y', 420),
    'parent1_email' => 'x@example.com',
];

$validErrors = EnrollmentFieldValidator::validateAgainstConfig($configPath, $validPayload);
if (!empty($validErrors)) {
    echo '[FAIL] valid payload produced validation errors' . PHP_EOL;
    $failed++;
} else {
    echo '[PASS] valid payload accepted at configured boundaries' . PHP_EOL;
}

$invalidPayload = [
    'child_first_name' => str_repeat('A', 81),
    'doctor_name' => str_repeat('D', 101),
    'emergency_contact_address' => str_repeat('X', 181),
    'authorized_pickups' => str_repeat('Y', 421),
];

$invalidErrors = EnrollmentFieldValidator::validateAgainstConfig($configPath, $invalidPayload);
$invalidNames = [];
foreach ($invalidErrors as $err) {
    if (isset($err['name'])) {
        $invalidNames[(string)$err['name']] = true;
    }
}

foreach (array_keys($invalidPayload) as $name) {
    if (!isset($invalidNames[$name])) {
        echo "[FAIL] missing expected overlength error for {$name}" . PHP_EOL;
        $failed++;
    } else {
        echo "[PASS] overlength rejected for {$name}" . PHP_EOL;
    }
}

if ($failed > 0) {
    echo "---\n[RESULT] FAIL ({$failed} issue(s))\n";
    exit(1);
}

echo "---\n[RESULT] PASS\n";
exit(0);
