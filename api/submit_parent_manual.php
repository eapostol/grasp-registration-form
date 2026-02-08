<?php
// api/submit_parent_manual.php
// Sends GRASP Parent Manual / Handbook Agreement submission via email (HTML) to configured recipient.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
  exit;
}

$raw = file_get_contents('php://input');
if (!$raw) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Empty request body.']);
  exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Invalid JSON.']);
  exit;
}

$config = require __DIR__ . '/config.php';


require_once __DIR__ . '/lib/EmailPrintTemplate.php';
$to = $config['email_to'] ?? '';
$from = $config['email_from'] ?? '';
if (!$to || !$from) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Email configuration is missing.']);
  exit;
}

$sessionId = $payload['sessionId'] ?? '';
$submittedAt = $payload['submittedAt'] ?? '';
$data = $payload['data'] ?? [];
// HTML body: server-rendered, Gmail-safe print layout (PDF-like)
$emailHtml = '';
$configPath = realpath(__DIR__ . '/../config/parent-manual-fields.json');
if ($configPath) {
  $emailHtml = EmailPrintTemplate::renderParentManualWithAttachmentNotice($configPath, (is_array($data) ? $data : []), ['formTitle' => 'GRASP Parent Manual Agreement', 'submittedAt' => ($submittedAt ?: '')]);
}
$parentName = '';
if (is_array($data)) {
  // Prefer acknowledgement printed name
  $parentName = trim(($data['pm_ack_printed_name'] ?? '') . '');
  if (!$parentName) $parentName = trim(($data['pm_parent_printed_name'] ?? '') . '');
}

$subject = 'GRASP Parent Manual Agreement';
if ($parentName) $subject .= ' - ' . $parentName;

$headers = [];
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-type: text/html; charset=utf-8';
$headers[] = 'From: ' . $from;
if (!empty($config['email_bcc'])) {
  $headers[] = 'Bcc: ' . $config['email_bcc'];
}

$meta = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#333;margin:0 0 10px;">'
  . '<p style="margin:0;">Session ID: ' . htmlspecialchars($sessionId) . '</p>'
  . '<p style="margin:0;">Submitted: ' . htmlspecialchars($submittedAt) . '</p>'
  . '</div>';

$body = $meta . ($emailHtml ? $emailHtml : '<p>No emailHtml provided.</p>');

// --- PDF attachment (completed manual) ---
// We generate a PDF from the handbook page images + the submitted field values, then attach it.
$pdfTmpPath = null;
try {
  $autoload = __DIR__ . '/vendor/autoload.php';
  if (!file_exists($autoload)) {
    throw new RuntimeException('Missing Composer dependencies. Run: cd api && composer install');
  }
  require_once $autoload;
  require_once __DIR__ . '/lib/ParentManualPdfGenerator.php';

  $pdfInfo = ParentManualPdfGenerator::generate(['fields' => $data], $sessionId ?: uniqid('pm_', true));
  $pdfTmpPath = $pdfInfo['path'] ?? null;
  $pdfFilename = $pdfInfo['filename'] ?? 'GRASP-Parent-Manual.pdf';

  if (!$pdfTmpPath || !file_exists($pdfTmpPath)) {
    throw new RuntimeException('Failed to generate Parent Manual PDF.');
  }

  $boundary = '=_GRASP_PM_' . bin2hex(random_bytes(12));
  $headers = [];
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'From: ' . $from;
  $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

  $attachmentData = chunk_split(base64_encode(file_get_contents($pdfTmpPath)));

  $message = '';
  $message .= '--' . $boundary . "\r\n";
  $message .= "Content-Type: text/html; charset=utf-8\r\n";
  $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
  $message .= $body . "\r\n\r\n";

  $message .= '--' . $boundary . "\r\n";
  $message .= 'Content-Type: application/pdf; name="' . addcslashes($pdfFilename, '"') . '"' . "\r\n";
  $message .= "Content-Transfer-Encoding: base64\r\n";
  $message .= 'Content-Disposition: attachment; filename="' . addcslashes($pdfFilename, '"') . '"' . "\r\n\r\n";
  $message .= $attachmentData . "\r\n";
  $message .= '--' . $boundary . "--\r\n";

  $ok = @mail($to, $subject, $message, implode("\r\n", $headers));
} catch (Throwable $e) {
  // Fallback: if we cannot generate/attach the PDF, fail so the sender can fix server setup.
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'message' => 'Unable to send Parent Manual email with PDF attachment.',
    'detail' => $e->getMessage(),
  ]);
  exit;
} finally {
  if ($pdfTmpPath && file_exists($pdfTmpPath)) {
    @unlink($pdfTmpPath);
  }
}

if (!$ok) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Unable to send email (mail() failed).']);
  exit;
}

echo json_encode(['ok' => true]);
