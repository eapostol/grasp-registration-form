<?php
// api/preview_enrollment_pdf.php
// Generates the same Enrollment PDF attachment binary for preview printing only.

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

$submittedAt = isset($payload['submittedAt']) ? (string)$payload['submittedAt'] : '';
$sessionId = isset($payload['sessionId']) ? (string)$payload['sessionId'] : '';

$submittedAtNormalized = date('Y-m-d H:i:s', strtotime($submittedAt ?: 'now'));
if ($submittedAtNormalized === false || $submittedAtNormalized === null) {
    $submittedAtNormalized = date('Y-m-d H:i:s');
}

require_once __DIR__ . '/lib/EmailPrintTemplate.php';
require_once __DIR__ . '/lib/FormPdfGenerator.php';

$configPath = realpath(__DIR__ . '/../config/enrollment-fields.json');
if (!$configPath) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Form config not found']);
    exit;
}

$pdfTmpPath = null;
$pdfFilename = 'GRASP-Enrollment-Preview.pdf';
$debugRaw = (string)($_GET['isdebug'] ?? $_GET['debug'] ?? '');
$debugEnabled = in_array(strtolower($debugRaw), ['1', 'true', 'yes', 'on'], true);
$logPath = __DIR__ . '/../preview_enrollment_pdf.log';

function previewPdfLog(string $logPath, string $message): void
{
    @file_put_contents($logPath, date('c') . ' ' . $message . PHP_EOL, FILE_APPEND);
}

try {
    $meta = [
        'formTitle' => 'GRASP Enrollment Form',
        'submittedAt' => $submittedAtNormalized,
        'sessionId' => $sessionId,
        'templateProfile' => 'enrollment',
    ];

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
        'GRASP Enrollment Form',
        $pdfDoc,
        'GRASP-Enrollment-Preview-' . ($sessionId ?: date('Ymd-His')),
        [
            'tmpDir' => $preferredTmpDir,
            'profile' => 'enrollment',
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
    previewPdfLog(
        $logPath,
        'ERROR preview_enrollment_pdf: ' . $e->getMessage() .
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
