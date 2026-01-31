<?php
// api/submit_waitlist.php
// Sends GRASP Wait List Application submission via email (HTML) to configured recipient.

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

$to = $config['email_to'] ?? '';
$from = $config['email_from'] ?? '';
if (!$to || !$from) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Email configuration is missing.']);
  exit;
}

// Build subject (include child name if present)
$childName = '';
if (isset($payload['data']) && is_array($payload['data']) && isset($payload['data']['child_name'])) {
  $childName = trim((string)$payload['data']['child_name']);
  // Prevent header/subject injection
  $childName = preg_replace("/[\r\n]+/", " ", $childName);
  $childName = trim(preg_replace('/\s+/', ' ', $childName));
}

$subject = 'New GRASP Wait List Application';
if ($childName !== '') {
  $subject .= ' - ' . $childName;
}

// HTML body: use provided emailHtml (rendered in the client) if present
$emailHtml = '';
if (isset($payload['emailHtml'])) {
  $emailHtml = (string)$payload['emailHtml'];
}

if ($emailHtml === '') {
  // Fallback: very simple HTML from data
  $emailHtml = '<h3>GRASP Wait List Application</h3><pre>' . htmlspecialchars(json_encode($payload['data'] ?? [], JSON_PRETTY_PRINT)) . '</pre>';
}

// Wrap with basic HTML document
$body = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $emailHtml . '</body></html>';


// Avoid very long lines that can cause SMTP issues on some servers
$body = wordwrap($body, 950, "\r\n", true);
$headers = [];
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-type: text/html; charset=UTF-8';
$headers[] = 'From: ' . $from;
$headers[] = 'Reply-To: ' . $from;
$headers[] = 'X-Mailer: PHP/' . phpversion();
if (!empty($config['email_bcc'])) {
  $headers[] = 'Bcc: ' . $config['email_bcc'];
}

$headersStr = implode("\r\n", $headers);

// Try to set an envelope-from for better deliverability (safe fallback if disabled)
// Extract a plain email address from $from (supports "Name <email@domain>")
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

$sent = false;
$envFromUsed = 0;
$mailError = '';

if ($envFrom !== '') {
  $envFromUsed = 1;
  $sent = @mail($to, $subject, $body, $headersStr, "-f $envFrom");
  if (!$sent) {
    $last = error_get_last();
    if (is_array($last) && isset($last['message'])) {
      $mailError = (string)$last['message'];
    }
    // Fallback: retry without additional parameters
    $envFromUsed = 0;
    $sent = @mail($to, $subject, $body, $headersStr);
  }
}

if (!$sent && $envFrom === '') {
  $sent = @mail($to, $subject, $body, $headersStr);
}

if (!$sent && $mailError === '') {
  $last = error_get_last();
  if (is_array($last) && isset($last['message'])) {
    $mailError = (string)$last['message'];
  }
}

// Minimal diagnostics (no payload) to help confirm send attempts in production
$host = $_SERVER['HTTP_HOST'] ?? '';
$uri = $_SERVER['REQUEST_URI'] ?? '';
$bytes = strlen($body);
$logLine = date('c')
  . " sent=" . ($sent ? '1' : '0')
  . " envFromUsed=" . ($envFromUsed ? '1' : '0')
  . " bytes=" . $bytes
  . " host=" . $host
  . " uri=" . $uri;
if (!$sent && $mailError !== '') {
  $logLine .= " err=" . str_replace(["\r", "\n"], ' ', $mailError);
}
$logLine .= "\n";
@file_put_contents(__DIR__ . '/submit_waitlist.log', $logLine, FILE_APPEND);

if (!$sent) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Email could not be sent. Please try again later.']);
  exit;
}

echo json_encode(['ok' => true]);
