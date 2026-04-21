#!/usr/bin/env php
<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
require_once __DIR__ . '/lib/RecoveryMessageParser.php';
require_once __DIR__ . '/lib/RecoveryRepository.php';
require_once __DIR__ . '/lib/RecoverySubmissionService.php';

$options = getopt('', [
    'input:',
    'report:',
    'env::',
    'send',
    'no-db',
    'allow-has-attachment',
]);

if (!isset($options['input']) || trim((string)$options['input']) === '') {
    fwrite(STDERR, "Usage: php scripts/recover_missing_attachments.php --input <file-or-dir> [--report <path>] [--env production|staging|local] [--send] [--no-db] [--allow-has-attachment]\n");
    exit(2);
}

$env = strtolower(trim((string)($options['env'] ?? 'production')));
if ($env === 'staging') {
    $_SERVER['HTTP_HOST'] = 'greenlandrecreational.com';
    $_SERVER['REQUEST_URI'] = '/staging/';
} elseif ($env === 'local') {
    $_SERVER['HTTP_HOST'] = 'reg-form-project.ddev.site';
    $_SERVER['REQUEST_URI'] = '/';
} else {
    $_SERVER['HTTP_HOST'] = 'greenlandrecreational.com';
    $_SERVER['REQUEST_URI'] = '/';
}

/** @var array<string,mixed> $config */
$config = require $projectRoot . '/api/config.php';
$parser = new RecoveryMessageParser($projectRoot);
$repository = new RecoveryRepository($config);
$service = new RecoverySubmissionService($projectRoot, $config, $repository);

$inputPath = (string)$options['input'];
$reportPath = (string)($options['report'] ?? ($projectRoot . '/test-results/recovery-report.json'));
$send = isset($options['send']);
$writeDb = !isset($options['no-db']);
$allowHasAttachment = isset($options['allow-has-attachment']);

$paths = [];
if (is_dir($inputPath)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($inputPath, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $fileInfo) {
        if ($fileInfo instanceof SplFileInfo && $fileInfo->isFile()) {
            $ext = strtolower((string)$fileInfo->getExtension());
            if (in_array($ext, ['json', 'txt'], true)) {
                $paths[] = $fileInfo->getPathname();
            }
        }
    }
    sort($paths);
} elseif (is_file($inputPath)) {
    $paths[] = $inputPath;
} else {
    fwrite(STDERR, "Input path not found: {$inputPath}\n");
    exit(2);
}

$report = [
    'generatedAt' => date('c'),
    'env' => $env,
    'send' => $send,
    'writeDb' => $writeDb,
    'inputPath' => $inputPath,
    'items' => [],
    'summary' => [
        'processed' => 0,
        'skipped' => 0,
        'parsed' => 0,
        'recovered' => 0,
        'dbSaved' => 0,
        'mailSent' => 0,
        'failed' => 0,
    ],
];

/** @var array<string,true> $seenRecoveredSessions */
$seenRecoveredSessions = [];

foreach ($paths as $path) {
    $messages = loadRecoveryMessages($path);
    if ($messages === []) {
        $report['summary']['failed']++;
        $report['items'][] = [
            'path' => $path,
            'ok' => false,
            'reason' => 'unsupported-or-invalid-input',
        ];
        continue;
    }

    foreach ($messages as $message) {
        $report['summary']['processed']++;
        $parsed = $parser->parse($message);
        if (!$allowHasAttachment && !empty($parsed['hasAttachment'])) {
            $report['summary']['skipped']++;
            $report['items'][] = [
                'path' => $path,
                'ok' => true,
                'skipped' => true,
                'reason' => 'already-has-attachment',
                'formType' => $parsed['formType'] ?? 'unknown',
                'sessionId' => $parsed['sessionId'] ?? '',
                'subject' => $parsed['subject'] ?? '',
            ];
            continue;
        }

        if (empty($parsed['ok'])) {
            $report['summary']['failed']++;
            $report['items'][] = [
                'path' => $path,
                'ok' => false,
                'reason' => 'parse-failed',
                'variant' => $parsed['variant'] ?? 'unknown',
                'confidence' => $parsed['confidence'] ?? 0,
                'formType' => $parsed['formType'] ?? 'unknown',
                'sessionId' => $parsed['sessionId'] ?? '',
                'subject' => $parsed['subject'] ?? '',
                'notes' => $parsed['notes'] ?? [],
            ];
            continue;
        }

        $dedupeKey = buildRecoveryDedupeKey($parsed);
        if ($dedupeKey !== '' && isset($seenRecoveredSessions[$dedupeKey])) {
            $report['summary']['skipped']++;
            $report['items'][] = [
                'path' => $path,
                'ok' => true,
                'skipped' => true,
                'reason' => 'duplicate-session-in-batch',
                'formType' => $parsed['formType'] ?? 'unknown',
                'sessionId' => $parsed['sessionId'] ?? '',
                'subject' => $parsed['subject'] ?? '',
                'variant' => $parsed['variant'] ?? 'unknown',
                'confidence' => $parsed['confidence'] ?? 0,
            ];
            continue;
        }

        $report['summary']['parsed']++;
        try {
            $recovered = $service->recover($parsed, [
                'send' => $send,
                'writeDb' => $writeDb,
            ]);
            if ($dedupeKey !== '') {
                $seenRecoveredSessions[$dedupeKey] = true;
            }
            $report['summary']['recovered']++;
            if (!empty($recovered['normalizedSaved'])) {
                $report['summary']['dbSaved']++;
            }
            if (!empty($recovered['mailSent'])) {
                $report['summary']['mailSent']++;
            }
            $report['items'][] = [
                'path' => $path,
                'ok' => true,
                'formType' => $recovered['formType'] ?? ($parsed['formType'] ?? 'unknown'),
                'sessionId' => $recovered['sessionId'] ?? ($parsed['sessionId'] ?? ''),
                'submittedAt' => $recovered['submittedAt'] ?? ($parsed['submittedAt'] ?? ''),
                'fieldSource' => $recovered['fieldSource'] ?? 'parser',
                'variant' => $parsed['variant'] ?? 'unknown',
                'confidence' => $parsed['confidence'] ?? 0,
                'subject' => $parsed['subject'] ?? '',
                'resendSubject' => $recovered['resendSubject'] ?? '',
                'normalizedSaved' => !empty($recovered['normalizedSaved']),
                'mailSent' => !empty($recovered['mailSent']),
                'pdfFilename' => $recovered['pdfFilename'] ?? '',
                'normalizedResult' => $recovered['normalizedResult'] ?? [],
                'mailResult' => $recovered['mailResult'] ?? [],
            ];
        } catch (Throwable $e) {
            $report['summary']['failed']++;
            $report['items'][] = [
                'path' => $path,
                'ok' => false,
                'reason' => 'recovery-exception',
                'formType' => $parsed['formType'] ?? 'unknown',
                'sessionId' => $parsed['sessionId'] ?? '',
                'subject' => $parsed['subject'] ?? '',
                'error' => $e->getMessage(),
            ];
        }
    }
}

$reportDir = dirname($reportPath);
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0775, true);
}
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo 'Processed: ' . $report['summary']['processed'] . PHP_EOL;
echo 'Parsed: ' . $report['summary']['parsed'] . PHP_EOL;
echo 'Recovered: ' . $report['summary']['recovered'] . PHP_EOL;
echo 'DB saved: ' . $report['summary']['dbSaved'] . PHP_EOL;
echo 'Mail sent: ' . $report['summary']['mailSent'] . PHP_EOL;
echo 'Failed: ' . $report['summary']['failed'] . PHP_EOL;
echo 'Report: ' . $reportPath . PHP_EOL;

/**
 * @return array<int,array<string,mixed>>
 */
function loadRecoveryMessages(string $path): array
{
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'json') {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }
        if (isset($decoded['emails']) && is_array($decoded['emails'])) {
            return array_values(array_filter($decoded['emails'], static fn($item): bool => is_array($item)));
        }
        return [$decoded];
    }

    if ($ext === 'txt') {
        return [[
            'id' => basename($path),
            'subject' => '',
            'body' => (string)file_get_contents($path),
            'email_ts' => '',
            'has_attachment' => false,
        ]];
    }

    return [];
}

/**
 * @param array<string,mixed> $parsed
 */
function buildRecoveryDedupeKey(array $parsed): string
{
    $formType = trim((string)($parsed['formType'] ?? ''));
    $sessionId = trim((string)($parsed['sessionId'] ?? ''));
    if ($formType === '' || $sessionId === '') {
        return '';
    }

    return strtolower($formType . '|' . $sessionId);
}
