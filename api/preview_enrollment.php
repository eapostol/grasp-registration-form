<?php
// api/preview_enrollment.php
// Returns server-rendered preview HTML (email + PDF styles) without saving or sending.

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(400);
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

$configPath = realpath(__DIR__ . '/../config/enrollment-fields.json');
if (!$configPath) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Form config not found']);
    exit;
}

try {
    $meta = [
        'formTitle' => 'GRASP Enrollment Form',
        'submittedAt' => $submittedAtNormalized,
        'sessionId' => $sessionId,
        'templateProfile' => 'enrollment',
    ];

    $emailHtml = EmailPrintTemplate::renderFromConfig($configPath, $fields, $meta);
    $pdfHtml = EmailPrintTemplate::renderPdfFromConfig($configPath, $fields, $meta);

    echo json_encode([
        'success' => true,
        'emailHtml' => is_string($emailHtml) ? $emailHtml : '',
        'pdfHtml' => is_string($pdfHtml) ? $pdfHtml : '',
        'submittedAt' => $submittedAtNormalized,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to build preview',
    ]);
}

