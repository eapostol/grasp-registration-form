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
$reqUri = $_SERVER['REQUEST_URI'] ?? '';
$isStagingPath = (strpos($reqUri, '/staging/') === 0);


require_once __DIR__ . '/lib/EmailPrintTemplate.php';
require_once __DIR__ . '/lib/NormalizedSubmissionStore.php';
$to = $config['email_to'] ?? '';
$from = $config['email_from'] ?? '';
if (!$to || !$from) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Email configuration is missing.']);
  exit;
}

$sessionId = $payload['sessionId'] ?? '';
$submittedAt = $payload['submittedAt'] ?? '';
$submittedAtSql = date('Y-m-d H:i:s', strtotime((string)$submittedAt ?: 'now'));
if ($submittedAtSql === false || $submittedAtSql === null) {
  $submittedAtSql = date('Y-m-d H:i:s');
}
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

$meta = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#333;margin:0 0 10px;">'
  . '<p style="margin:0;">Session ID: ' . htmlspecialchars($sessionId) . '</p>'
  . '<p style="margin:0;">Submitted: ' . htmlspecialchars($submittedAt) . '</p>'
  . '</div>';

$body = $meta . ($emailHtml ? $emailHtml : '<p>No emailHtml provided.</p>');

// Best-effort dual-write to normalized DB (non-blocking for existing behavior).
$normalizedResult = ['ok' => false, 'skipped' => true];
try {
  $normalizedResult = NormalizedSubmissionStore::persistSubmission([
    'dsn' => $config['db']['dsn'] ?? '',
    'user' => $config['db']['user'] ?? '',
    'password' => $config['db']['password'] ?? '',
    'formType' => 'parent_manual',
    'sessionId' => (string)$sessionId,
    'submittedAt' => $submittedAtSql,
    'status' => 'submitted',
    'fields' => is_array($data) ? $data : [],
    'payloadJson' => json_encode($data, JSON_UNESCAPED_UNICODE),
    'emailHtml' => $emailHtml,
    'pdfHtml' => null,
    'source' => 'submit_parent_manual.php',
  ]);
} catch (Throwable $e) {
  $normalizedResult = ['ok' => false, 'error' => $e->getMessage()];
}

// --- PDF attachment (completed manual) ---
// We generate a PDF from the handbook page images + the submitted field values, then attach it.
$pdfTmpPath = null;
$pdfBytes = 0;
$messageBytes = 0;
$envFromUsed = 0;
$mailError = '';
$ok = false;
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

  $pdfBytes = (int) filesize($pdfTmpPath);
  // Keep encoded message safely below common shared-host limits.
  $maxPdfBytes = 8 * 1024 * 1024;
  if (!$isStagingPath && $pdfBytes > $maxPdfBytes) {
    throw new RuntimeException('Generated Parent Manual PDF is too large to email safely (' . $pdfBytes . ' bytes).');
  }

  $boundary = '=_GRASP_PM_' . bin2hex(random_bytes(12));
  $headersMixed = [];
  $headersMixed[] = 'MIME-Version: 1.0';
  $headersMixed[] = 'From: ' . $from;
  $headersMixed[] = 'Reply-To: ' . $from;
  $headersMixed[] = 'X-Mailer: PHP/' . phpversion();
  if (!empty($config['email_bcc'])) {
    $headersMixed[] = 'Bcc: ' . $config['email_bcc'];
  }
  $headersMixed[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

  $attachmentData = chunk_split(base64_encode(file_get_contents($pdfTmpPath)));
  $htmlPart = wordwrap($body, 900, "\r\n", true);

  $message = '';
  $message .= '--' . $boundary . "\r\n";
  $message .= "Content-Type: text/html; charset=utf-8\r\n";
  $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
  $message .= $htmlPart . "\r\n\r\n";

  $message .= '--' . $boundary . "\r\n";
  $message .= 'Content-Type: application/pdf; name="' . addcslashes($pdfFilename, '"') . '"' . "\r\n";
  $message .= "Content-Transfer-Encoding: base64\r\n";
  $message .= 'Content-Disposition: attachment; filename="' . addcslashes($pdfFilename, '"') . '"' . "\r\n\r\n";
  $message .= $attachmentData . "\r\n";
  $message .= '--' . $boundary . "--\r\n";

  $headersStr = implode("\r\n", $headersMixed);
  $messageBytes = strlen($message);

  // Use envelope-from for better deliverability where supported.
  $envFrom = '';
  if (preg_match('/<([^>]+)>/', $from, $m)) {
    $envFrom = trim($m[1]);
  } else {
    $envFrom = trim($from);
  }
  $envFrom = preg_replace("/[\r\n]+/", "", $envFrom);
  if (!filter_var($envFrom, FILTER_VALIDATE_EMAIL)) {
    $envFrom = '';
  }

  if ($envFrom !== '') {
    $envFromUsed = 1;
    $ok = @mail($to, $subject, $message, $headersStr, "-f $envFrom");
    if (!$ok) {
      $last = error_get_last();
      if (is_array($last) && isset($last['message'])) {
        $mailError = (string) $last['message'];
      }
      $envFromUsed = 0;
      $ok = @mail($to, $subject, $message, $headersStr);
    }
  } else {
    $ok = @mail($to, $subject, $message, $headersStr);
  }

  if (!$ok && $mailError === '') {
    $last = error_get_last();
    if (is_array($last) && isset($last['message'])) {
      $mailError = (string) $last['message'];
    }
  }
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
  $logFile = __DIR__ . '/../submit_parent_manual.log';
  @file_put_contents(
    $logFile,
    date('c')
    . ' host=' . ($_SERVER['HTTP_HOST'] ?? '')
    . ' uri=' . ($_SERVER['REQUEST_URI'] ?? '')
    . ' success=' . var_export($ok, true)
    . ' envFromUsed=' . var_export($envFromUsed, true)
    . ' mailError=' . ($mailError !== '' ? $mailError : '-')
    . ' normalizedSaved=' . var_export(!empty($normalizedResult['ok']), true)
    . ' pdfBytes=' . $pdfBytes
    . ' messageBytes=' . $messageBytes
    . ' to=' . $to
    . PHP_EOL,
    FILE_APPEND
  );

  if ($pdfTmpPath && file_exists($pdfTmpPath)) {
    @unlink($pdfTmpPath);
  }
}

if (!$ok) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'message' => 'Unable to send email (mail() failed).',
    'mailError' => $mailError,
  ]);
  exit;
}

if (!empty($normalizedResult['submissionId'])) {
  NormalizedSubmissionStore::markEmailSent([
    'dsn' => $config['db']['dsn'] ?? '',
    'user' => $config['db']['user'] ?? '',
    'password' => $config['db']['password'] ?? '',
    'submissionId' => (int)$normalizedResult['submissionId'],
    'source' => 'submit_parent_manual.php',
    'meta' => ['mailSent' => true],
  ]);
}

echo json_encode([
  'ok' => true,
  'normalizedSaved' => !empty($normalizedResult['ok']),
]);
