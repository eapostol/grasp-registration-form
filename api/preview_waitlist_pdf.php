<?php
// api/preview_waitlist_pdf.php
// Generates the same Wait List PDF attachment binary for preview printing only.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

$fields = [];
if (isset($payload['data']) && is_array($payload['data'])) {
    $fields = $payload['data'];
}

$submittedAtRaw = isset($payload['submittedAt']) ? trim((string)$payload['submittedAt']) : '';
$sessionId = isset($payload['sessionId']) ? trim((string)$payload['sessionId']) : '';

$submittedAtSql = date('Y-m-d H:i:s', strtotime($submittedAtRaw ?: 'now'));
if ($submittedAtSql === false || $submittedAtSql === null) {
    $submittedAtSql = date('Y-m-d H:i:s');
}

require_once __DIR__ . '/lib/EmailPrintTemplate.php';
require_once __DIR__ . '/lib/FormPdfGenerator.php';

$configPath = realpath(__DIR__ . '/../config/waitlist-fields.json');
if (!$configPath) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Form config not found']);
    exit;
}

// Keep preview output aligned with submit_waitlist.php.
foreach (['currently_attends_daycare', 'currently_attending_school', 'will_attend_when_require_care'] as $key) {
    if (!isset($fields[$key])) {
        $fields[$key] = 'none';
        continue;
    }

    $value = $fields[$key];
    if (is_bool($value)) {
        $fields[$key] = $value ? 'yes' : 'none';
        continue;
    }

    $stringValue = trim((string)$value);
    if ($stringValue === '') {
        $fields[$key] = 'none';
    }
}

$pdfTmpPath = null;
$pdfFilename = 'GRASP-Waitlist-Preview.pdf';
$debugRaw = (string)($_GET['isdebug'] ?? $_GET['debug'] ?? '');
$debugEnabled = in_array(strtolower($debugRaw), ['1', 'true', 'yes', 'on'], true);
$logPath = __DIR__ . '/../preview_waitlist_pdf.log';

function previewWaitlistPdfLog(string $logPath, string $message): void
{
    @file_put_contents($logPath, date('c') . ' ' . $message . PHP_EOL, FILE_APPEND);
}

try {
    $meta = [
        'formTitle' => 'GRASP Wait List Application',
        'templateProfile' => 'waitlist',
    ];

    if ($sessionId !== '') {
        $meta['sessionId'] = $sessionId;
    }

    if ($submittedAtRaw !== '') {
        try {
            $dt = new DateTime($submittedAtRaw);
            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $meta['submittedAt'] = $dt->format('F j, Y, g:i a');
        } catch (Exception $e) {
            $meta['submittedAt'] = date('F j, Y, g:i a', strtotime($submittedAtSql));
        }
    } else {
        $meta['submittedAt'] = date('F j, Y, g:i a', strtotime($submittedAtSql));
    }

    $pdfHtml = EmailPrintTemplate::renderPdfFromConfig($configPath, $fields, $meta);
    $pdfDoc = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $pdfHtml . '</body></html>';

    $preferredTmpDir = __DIR__ . '/tmp';
    if (!is_dir($preferredTmpDir)) {
        @mkdir($preferredTmpDir, 0775, true);
    }
    if (!is_dir($preferredTmpDir) || !is_writable($preferredTmpDir)) {
        $preferredTmpDir = sys_get_temp_dir();
    }

    $pdfInfo = FormPdfGenerator::generateFromHtml(
        'GRASP Wait List Application',
        $pdfDoc,
        'GRASP-Waitlist-Preview-' . ($sessionId ?: date('Ymd-His')),
        [
            'tmpDir' => $preferredTmpDir,
            'profile' => 'waitlist',
        ]
    );
    $pdfTmpPath = $pdfInfo['path'] ?? null;
    $pdfFilename = $pdfInfo['filename'] ?? $pdfFilename;

    if (!$pdfTmpPath || !file_exists($pdfTmpPath)) {
        throw new RuntimeException('Generated PDF not found');
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . addcslashes($pdfFilename, '"') . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Content-Length: ' . filesize($pdfTmpPath));
    readfile($pdfTmpPath);
} catch (Throwable $e) {
    $ctx = [
        'host' => (string)($_SERVER['HTTP_HOST'] ?? ''),
        'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        'configPath' => $configPath ?: '(missing)',
        'vendorAutoload' => file_exists(__DIR__ . '/vendor/autoload.php') ? 'yes' : 'no',
        'sysTempDir' => (string)sys_get_temp_dir(),
        'sysTempWritable' => is_writable(sys_get_temp_dir()) ? 'yes' : 'no',
        'apiTmpDir' => __DIR__ . '/tmp',
        'apiTmpExists' => is_dir(__DIR__ . '/tmp') ? 'yes' : 'no',
        'apiTmpWritable' => is_writable(__DIR__ . '/tmp') ? 'yes' : 'no',
    ];
    previewWaitlistPdfLog(
        $logPath,
        'ERROR preview_waitlist_pdf: ' . $e->getMessage() .
        ' @ ' . $e->getFile() . ':' . $e->getLine() .
        ' context=' . json_encode($ctx, JSON_UNESCAPED_SLASHES)
    );

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    $resp = ['success' => false, 'error' => 'Failed to generate preview PDF'];
    if ($debugEnabled) {
        $resp['debugError'] = $e->getMessage();
    }
    echo json_encode($resp);
} finally {
    if ($pdfTmpPath && file_exists($pdfTmpPath)) {
        @unlink($pdfTmpPath);
    }
}
