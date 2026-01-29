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
$emailHtml = $payload['emailHtml'] ?? '';

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

$meta = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#333;margin:0 0 10px;">'
  . '<p style="margin:0;">Session ID: ' . htmlspecialchars($sessionId) . '</p>'
  . '<p style="margin:0;">Submitted: ' . htmlspecialchars($submittedAt) . '</p>'
  . '</div>';

$body = $meta . ($emailHtml ? $emailHtml : '<p>No emailHtml provided.</p>');

$ok = @mail($to, $subject, $body, implode("\r\n", $headers));
if (!$ok) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Unable to send email (mail() failed).']);
  exit;
}

echo json_encode(['ok' => true]);
