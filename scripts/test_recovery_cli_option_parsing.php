#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$script = $root . '/scripts/recover_missing_attachments.php';
$fixture = $root . '/scripts/fixtures/recovery/waitlist-structured.json';
$tempDir = $root . '/test-results/cli-option-tests';

if (!is_dir($tempDir)) {
    mkdir($tempDir, 0775, true);
}

$tests = [
    [
        'name' => 'accepts equals-style env and honors no-db',
        'command' => [
            'php',
            $script,
            '--input',
            $fixture,
            '--report',
            $tempDir . '/valid-report.json',
            '--env=staging',
            '--no-db',
        ],
        'expectExit' => 0,
        'expectStdErr' => '',
        'expectReport' => [
            'env' => 'staging',
            'writeDb' => false,
        ],
    ],
    [
        'name' => 'rejects space-separated env usage loudly',
        'command' => [
            'php',
            $script,
            '--input',
            $fixture,
            '--report',
            $tempDir . '/invalid-report.json',
            '--env',
            'staging',
            '--no-db',
        ],
        'expectExit' => 2,
        'expectStdErr' => 'Invalid --env usage.',
        'expectReport' => null,
    ],
    [
        'name' => 'rejects unsupported env values loudly',
        'command' => [
            'php',
            $script,
            '--input',
            $fixture,
            '--report',
            $tempDir . '/bogus-report.json',
            '--env=preview',
            '--no-db',
        ],
        'expectExit' => 2,
        'expectStdErr' => 'Invalid --env value.',
        'expectReport' => null,
    ],
];

$failures = 0;
foreach ($tests as $test) {
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($test['command'], $descriptorSpec, $pipes, $root);
    if (!is_resource($process)) {
        fwrite(STDERR, '[FAIL] ' . $test['name'] . ': unable to launch process' . PHP_EOL);
        $failures++;
        continue;
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $caseFailures = [];
    if ($exitCode !== $test['expectExit']) {
        $caseFailures[] = 'expected exit ' . $test['expectExit'] . ' got ' . $exitCode;
    }

    $expectedStdErr = $test['expectStdErr'];
    if ($expectedStdErr !== '' && strpos($stderr, $expectedStdErr) === false) {
        $caseFailures[] = 'stderr missing [' . $expectedStdErr . ']';
    }

    if (is_array($test['expectReport'])) {
        $reportPath = $test['command'][5];
        if (!is_file($reportPath)) {
            $caseFailures[] = 'expected report file missing';
        } else {
            $report = json_decode((string)file_get_contents($reportPath), true);
            if (!is_array($report)) {
                $caseFailures[] = 'report JSON invalid';
            } else {
                foreach ($test['expectReport'] as $key => $value) {
                    if (($report[$key] ?? null) !== $value) {
                        $caseFailures[] = $key . ' expected [' . var_export($value, true) . '] got [' . var_export($report[$key] ?? null, true) . ']';
                    }
                }
            }
        }
    }

    if ($caseFailures !== []) {
        fwrite(STDERR, '[FAIL] ' . $test['name'] . ': ' . implode('; ', $caseFailures) . PHP_EOL);
        if ($stdout !== '') {
            fwrite(STDERR, 'stdout: ' . trim($stdout) . PHP_EOL);
        }
        if ($stderr !== '') {
            fwrite(STDERR, 'stderr: ' . trim($stderr) . PHP_EOL);
        }
        $failures++;
        continue;
    }

    echo '[PASS] ' . $test['name'] . PHP_EOL;
}

if ($failures > 0) {
    exit(1);
}
