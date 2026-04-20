<?php

declare(strict_types=1);

function enrollmentRendererProjectRoot(): string
{
    return dirname(__DIR__);
}

function enrollmentRendererConfigPath(): string
{
    return enrollmentRendererProjectRoot() . '/config/enrollment-fields.json';
}

function enrollmentRendererSampleData(): array
{
    return [
        'child_first_name' => 'Kayla',
        'child_middle_name_or_initial' => '',
        'child_last_name' => 'Li',
        'child_birth_date' => '2020-09-10',

        'parent1_first_name' => 'Leilani',
        'parent1_last_name' => 'Mau',
        'parent1_name' => 'Leilani Mau',
        'parent1_email' => 'leilani_mau@hotmail.com',
        'parent1_home_street' => '35C Green Belt Drive',
        'parent1_home_unit' => '',
        'parent1_home_city' => 'Toronto',
        'parent1_home_province' => 'ON',
        'parent1_home_postal1' => 'M3C',
        'parent1_home_postal2' => '1L8',
        'parent1_phones' => '(416) 889-0137',
        'parent1_work_street' => '35C Green Belt Drive',
        'parent1_work_unit' => '',
        'parent1_work_city' => 'Toronto',
        'parent1_work_province' => 'ON',
        'parent1_work_postal1' => 'M3C',
        'parent1_work_postal2' => '1L8',
        'parent1_work_phone' => '(416) 889-0137',

        'parent2_first_name' => 'Kevin',
        'parent2_last_name' => 'Li',
        'parent2_name' => 'Kevin Li',
        'parent2_email' => 'kevin.li@hotmail.ca',
        'parent2_home_same_as_parent1' => '1',
        'parent2_home_street' => '',
        'parent2_home_unit' => '',
        'parent2_home_city' => '',
        'parent2_home_province' => '',
        'parent2_home_postal1' => '',
        'parent2_home_postal2' => '',
        'parent2_phones' => '(416) 918-3134',
        'parent2_work_street' => '',
        'parent2_work_unit' => '',
        'parent2_work_city' => '',
        'parent2_work_province' => '',
        'parent2_work_postal1' => '',
        'parent2_work_postal2' => '',
        'parent2_work_phone' => '',

        'doctor_name' => 'Dr. Stephen Baker',
        'doctor_phone' => '(416) 492-5888',
        'doctor_street' => '4800 Leslie St',
        'doctor_unit' => '',
        'doctor_city' => 'Toronto',
        'doctor_province' => 'ON',
        'doctor_postal1' => 'M2J',
        'doctor_postal2' => '2K9',
        'child_allergies' => 'None',
        'allergy_symptoms' => '',
        'allergy_treatment' => '',
        'epipen_required' => 'no',

        'emergency_contact_name' => 'Lilin Li',
        'emergency_contact_relationship' => 'Grandmother',
        'emergency_contact_day_phone' => '(416) 818-6385',
        'emergency_contact_address' => "7 Hycrest Ave\nNorth York, ON\nM2N 5G2",
        'authorized_pickups' => implode("\n", [
            'Lilin Li, Grandmother, (416) 818-6385',
            'Stephen Li, Grandfather, (416) 818-1950',
        ]),

        'medical_release_consent' => 'I agree',

        'parent_full_name_signature' => 'Leilani Mau',
        'witness' => 'Kevin Li',
        'signature_date' => '2026-04-13',
    ];
}

function enrollmentRendererMeta(string $sessionId): array
{
    return [
        'formTitle' => 'GRASP Enrollment Form',
        'submittedAt' => '2026-04-20 12:00:00',
        'sessionId' => $sessionId,
        'templateProfile' => 'enrollment',
    ];
}

function enrollmentRendererDecodedHtml(string $html): string
{
    $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return preg_replace('/\s+/', ' ', $decoded) ?? $decoded;
}

function enrollmentRendererSectionWindow(string $decodedHtml, string $sectionTitle, ?string $nextSectionTitle = null): string
{
    $start = strpos($decodedHtml, $sectionTitle);
    if ($start === false) {
        return '';
    }

    if ($nextSectionTitle === null) {
        return substr($decodedHtml, $start);
    }

    $next = strpos($decodedHtml, $nextSectionTitle, $start + strlen($sectionTitle));
    if ($next === false) {
        return substr($decodedHtml, $start);
    }

    return substr($decodedHtml, $start, $next - $start);
}

function enrollmentRendererPrintChecks(array $checks): int
{
    $failures = 0;

    foreach ($checks as $check) {
        $ok = !empty($check['ok']);
        $name = (string)($check['name'] ?? 'unnamed check');

        if ($ok) {
            echo '[PASS] ' . $name . PHP_EOL;
            continue;
        }

        echo '[FAIL] ' . $name;
        if (!empty($check['detail'])) {
            echo ': ' . (string)$check['detail'];
        }
        echo PHP_EOL;
        $failures++;
    }

    return $failures;
}
