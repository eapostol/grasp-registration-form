<?php
declare(strict_types=1);

// This module has no email side effects. Never log credentials or Google tokens.
function contactAdminConfig(): array
{
    $path = getenv('GRASP_CONTACT_CONFIG') ?: '/etc/grasp/contact-admin.json';
    $config = [];
    if (is_file($path)) {
        $resolved = realpath($path);
        $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2));
        if (!$resolved || !$documentRoot || $resolved === $documentRoot
            || str_starts_with($resolved, $documentRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Contact configuration must be outside the document root.');
        }
        $contents = @file_get_contents($resolved);
        if ($contents === false) {
            throw new RuntimeException('Contact configuration is unreadable.');
        }
        $config = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($config)) {
            throw new RuntimeException('Invalid contact configuration.');
        }
    }
    foreach (['project_id', 'site_key', 'api_key', 'allowed_hostnames', 'recipient'] as $key) {
        $value = getenv('GRASP_CONTACT_' . strtoupper($key));
        if ($value !== false) {
            $config[$key] = $key === 'allowed_hostnames' ? explode(',', $value) : $value;
        }
    }
    foreach (['project_id', 'site_key', 'api_key', 'recipient'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key])) {
            throw new RuntimeException('Incomplete contact configuration.');
        }
        $config[$key] = trim($config[$key]);
        if ($config[$key] === '') {
            throw new RuntimeException('Incomplete contact configuration.');
        }
    }
    if (!preg_match('/^[a-z][a-z0-9-]{4,61}[a-z0-9]$/D', $config['project_id'])
        || !preg_match('/^[A-Za-z0-9_-]+$/D', $config['site_key'])
        || !preg_match('/^[A-Za-z0-9_-]+$/D', $config['api_key'])
        || preg_match('/[\r\n]/', $config['recipient'])
        || !filter_var($config['recipient'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid contact configuration.');
    }
    if (!isset($config['allowed_hostnames']) || !is_array($config['allowed_hostnames'])
        || !$config['allowed_hostnames']) {
        throw new RuntimeException('Missing allowed hostnames.');
    }
    foreach ($config['allowed_hostnames'] as &$hostname) {
        if (!is_string($hostname)) {
            throw new RuntimeException('Invalid hostname.');
        }
        $hostname = strtolower(trim($hostname));
        if (!filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new RuntimeException('Invalid hostname.');
        }
    }
    unset($hostname);
    return $config;
}

/** Return only public configuration; never serialize the full runtime config. */
function contactAdminPublicConfig(array $config): array
{
    return ['siteKey' => $config['site_key']];
}

function contactAdminAssessmentRequest(array $config, string $token): array
{
    // Explicitly rendered checkbox keys do not support actions. Google validates
    // the token against this site key; also check the returned hostname below.
    return ['event' => ['token' => $token, 'siteKey' => $config['site_key']]];
}

function contactAdminGoogleRequest(array $config, array $request): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Verification unavailable.');
    }
    $url = 'https://recaptchaenterprise.googleapis.com/v1/projects/'
        . rawurlencode($config['project_id']) . '/assessments';
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            // Keep the credential out of URLs and access logs.
            'X-Goog-Api-Key: ' . $config['api_key'],
        ],
        CURLOPT_POSTFIELDS => json_encode($request, JSON_THROW_ON_ERROR),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($body === false || $status !== 200) {
        throw new RuntimeException('Verification unavailable.');
    }
    $response = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($response) || !isset($response['tokenProperties'])
        || !is_array($response['tokenProperties'])
        || !is_bool($response['tokenProperties']['valid'] ?? null)) {
        throw new RuntimeException('Invalid verification response.');
    }
    return $response;
}

function contactAdminVerify(array $config, string $token, ?callable $transport = null): bool
{
    if ($token === '' || strlen($token) > 16384) {
        return false;
    }
    $response = ($transport ?? 'contactAdminGoogleRequest')(
        $config, contactAdminAssessmentRequest($config, $token)
    );
    $properties = $response['tokenProperties'] ?? [];
    return ($properties['valid'] ?? null) === true
        && is_string($properties['hostname'] ?? null)
        && in_array(strtolower($properties['hostname']), $config['allowed_hostnames'], true);
}
