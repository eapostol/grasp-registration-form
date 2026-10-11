<?php
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['message' => 'Method not allowed.']);
    exit;
}
require_once __DIR__ . '/lib/ContactAdminSecurity.php';
try {
    echo json_encode(contactAdminPublicConfig(contactAdminConfig()), JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(503);
    echo json_encode(['message' => 'Contact verification is temporarily unavailable. Please try again later.']);
}
